<?php
declare(strict_types=1);

namespace App\Core;

use ZipArchive;

/**
 * Theme management: install, activate, duplicate, export, import, delete,
 * validation and bundled-theme synchronisation.
 */
final class ThemeEngine
{
    public static function syncBundledThemes(): array
    {
        $registered = 0;
        foreach (Theme::bundledSlugs() as $slug) {
            $meta = Theme::meta($slug);
            $existing = Database::fetch('SELECT id, version FROM themes WHERE slug = ?', [$slug]);
            $payload = [
                'slug' => $slug,
                'name' => (string)$meta['name'],
                'version' => (string)$meta['version'],
                'author' => (string)$meta['author'],
                'description' => (string)$meta['description'],
                'category' => (string)$meta['category'],
                'screenshot' => (string)($meta['screenshot'] ?? ''),
                'settings_json' => json_encode($meta['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                'layouts_json' => json_encode($meta['layouts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'source' => 'bundled',
            ];
            if ($existing) {
                if ($existing['version'] !== $meta['version']) {
                    Database::update('themes', $payload, 'id = ?', [(int)$existing['id']]);
                    $registered++;
                }
            } else {
                Database::insert('themes', $payload);
                $registered++;
            }
        }

        // Prune rows for bundled themes whose folder is gone (the catalog is
        // the source of truth). Themes still used by a blog are kept so the
        // site never falls back to another design without a human decision,
        // and uploaded themes are never touched.
        $removed = 0;
        $slugs = Theme::bundledSlugs();
        if ($slugs !== []) {
            $placeholders = implode(',', array_fill(0, count($slugs), '?'));
            $stale = Database::run(
                'SELECT id, slug FROM themes WHERE source = "bundled" AND slug NOT IN (' . $placeholders . ')',
                $slugs
            )->fetchAll();
            foreach ($stale as $row) {
                $inUse = (int)Database::value('SELECT COUNT(*) FROM blogs WHERE theme = ?', [(string)$row['slug']]);
                if ($inUse > 0) {
                    continue;
                }
                Database::delete('themes', 'id = ?', [(int)$row['id']]);
                $removed++;
            }
        }

        return ['registered' => $registered, 'removed' => $removed, 'total' => count($slugs)];
    }

    public static function activate(string $slug): void
    {
        if (!Theme::exists($slug)) {
            throw new ValidationException('Tema tidak ditemukan: ' . $slug);
        }
        Database::transaction(static function () use ($slug): void {
            Database::run('UPDATE themes SET status = "inactive"');
            Database::run('UPDATE themes SET status = "active" WHERE slug = ?', [$slug]);
            Database::update('blogs', ['theme' => $slug], 'id = ?', [Context::blogId()]);
            Settings::set('theme', $slug);
        });
        Theme::reset();
        Context::flushBlog();
        Cache::flushGroup('theme');
        log_activity('theme.activate', $slug);
    }

    public static function validate(string $directory): array
    {
        $errors = [];
        $warnings = [];
        $metaFile = $directory . '/theme.json';
        if (!is_file($metaFile)) {
            return ['valid' => false, 'errors' => ['theme.json tidak ditemukan.'], 'warnings' => []];
        }
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!is_array($meta)) {
            return ['valid' => false, 'errors' => ['theme.json bukan JSON yang valid.'], 'warnings' => []];
        }
        foreach (['slug', 'name', 'version'] as $field) {
            if (empty($meta[$field])) {
                $errors[] = 'Field wajib theme.json tidak lengkap: ' . $field;
            }
        }
        if (!empty($meta['slug']) && !preg_match('/^[a-z0-9_-]{3,80}$/', (string)$meta['slug'])) {
            $errors[] = 'Slug tema hanya boleh huruf kecil, angka, - dan _.';
        }
        if (!is_file($directory . '/layout.php')) {
            $errors[] = 'layout.php tidak ditemukan.';
        } else {
            $layout = (string)file_get_contents($directory . '/layout.php');
            if (!str_contains($layout, 'head(') || !str_contains($layout, 'content(')) {
                $warnings[] = 'Layout disarankan memanggil $ui->head() dan $ui->content().';
            }
            if (preg_match('/\beval\s*\(|base64_decode\s*\(|shell_exec\s*\(|\bsystem\s*\(|\bexec\s*\(/i', $layout)) {
                $errors[] = 'layout.php mengandung fungsi berbahaya.';
            }
        }
        if (!is_file($directory . '/style.css')) {
            $warnings[] = 'style.css tidak ditemukan; tema akan memakai gaya dasar.';
        }
        foreach ((array)($meta['settings'] ?? []) as $index => $definition) {
            if (empty($definition['key'])) {
                $errors[] = 'Definisi setting #' . $index . ' tidak punya key.';
                continue;
            }
            $type = (string)($definition['type'] ?? 'text');
            if (!in_array($type, ['text', 'textarea', 'color', 'select', 'image', 'number', 'checkbox', 'font'], true)) {
                $errors[] = 'Tipe setting tidak dikenal: ' . $type;
            }
        }
        $functions = $directory . '/functions.php';
        if (is_file($functions) && preg_match('/\beval\s*\(|base64_decode\s*\(|shell_exec\s*\(|\bsystem\s*\(|\bexec\s*\(/i', (string)file_get_contents($functions))) {
            $errors[] = 'functions.php mengandung fungsi berbahaya.';
        }
        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Install an uploaded zip theme. Validates structure and rollback on error.
     */
    public static function installFromZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ValidationException('Ekstensi PHP zip tidak tersedia pada server ini.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ValidationException('File zip tidak dapat dibuka.');
        }
        $temp = sys_get_temp_dir() . '/theme_' . bin2hex(random_bytes(6));
        @mkdir($temp, 0755, true);
        $zip->extractTo($temp);
        $zip->close();

        $themeDir = self::findThemeRoot($temp);
        if ($themeDir === null) {
            self::removeTree($temp);
            throw new ValidationException('Struktur tema tidak dikenali (theme.json tidak ditemukan).');
        }
        $validation = self::validate($themeDir);
        if (!$validation['valid']) {
            self::removeTree($temp);
            throw new ValidationException('Tema tidak valid: ' . implode(' ', $validation['errors']));
        }
        $meta = json_decode((string)file_get_contents($themeDir . '/theme.json'), true);
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$meta['slug']));
        if ($slug === '' || is_dir(Theme::dir($slug))) {
            self::removeTree($temp);
            throw new ValidationException('Slug tema sudah dipakai atau tidak valid: ' . $slug);
        }
        if (!@rename($themeDir, Theme::dir($slug))) {
            self::removeTree($temp);
            throw new RuntimeException('Tema tidak dapat dipasang. Periksa izin tulis folder themes/.');
        }
        self::removeTree($temp);
        self::syncBundledThemes();
        Database::update('themes', ['source' => 'uploaded'], 'slug = ?', [$slug]);
        log_activity('theme.install', $slug);
        return ['slug' => $slug, 'name' => (string)$meta['name']];
    }

    public static function duplicate(string $slug, string $newName): string
    {
        if (!Theme::exists($slug)) {
            throw new ValidationException('Tema asal tidak ditemukan.');
        }
        $meta = Theme::meta($slug);
        $newSlug = Slug::make($newName, 'theme');
        while (is_dir(Theme::dir($newSlug))) {
            $newSlug .= '-' . bin2hex(random_bytes(2));
        }
        self::copyTree(Theme::dir($slug), Theme::dir($newSlug));
        $meta['slug'] = $newSlug;
        $meta['name'] = $newName;
        $meta['version'] = '1.0.0';
        file_put_contents(Theme::dir($newSlug) . '/theme.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        self::syncBundledThemes();
        log_activity('theme.duplicate', $slug . ' -> ' . $newSlug);
        return $newSlug;
    }

    public static function export(string $slug, string $targetPath): string
    {
        if (!Theme::exists($slug)) {
            throw new ValidationException('Tema tidak ditemukan.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new ValidationException('Ekstensi PHP zip tidak tersedia.');
        }
        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('File ekspor tidak dapat dibuat.');
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(Theme::dir($slug), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                $local = ltrim(str_replace(Theme::dir($slug), '', $file->getPathname()), '/');
                $zip->addFile($file->getPathname(), $slug . '/' . $local);
            }
        }
        $zip->close();
        return $targetPath;
    }

    public static function remove(string $slug): void
    {
        $isActive = $slug === Theme::instance()->slug();
        $active = Database::value('SELECT COUNT(*) FROM blogs WHERE theme = ?', [$slug]);
        if ($isActive || (int)$active > 0) {
            throw new ValidationException('Tema aktif tidak dapat dihapus. Aktifkan tema lain terlebih dahulu.');
        }
        if (!Theme::exists($slug)) {
            throw new ValidationException('Tema tidak ditemukan.');
        }
        $meta = Theme::meta($slug);
        if (($meta['source'] ?? '') === 'bundled' && (bool)Config::get('protect_bundled_themes', true)) {
            throw new ValidationException('Tema bawaan dilindungi. Duplikat tema untuk memodifikasinya.');
        }
        self::removeTree(Theme::dir($slug));
        Database::delete('themes', 'slug = ?', [$slug]);
        log_activity('theme.remove', $slug);
    }

    public static function backup(string $slug): string
    {
        $dir = CMS_ROOT . '/storage/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/theme-' . $slug . '-' . gmdate('Ymd-His') . '.zip';
        return self::export($slug, $file);
    }

    private static function findThemeRoot(string $directory): ?string
    {
        if (is_file($directory . '/theme.json')) {
            return $directory;
        }
        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (is_file($sub . '/theme.json')) {
                return $sub;
            }
        }
        return null;
    }

    private static function copyTree(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            @mkdir($destination, 0755, true);
        }
        foreach (glob($source . '/*') ?: [] as $path) {
            $target = $destination . '/' . basename($path);
            if (is_dir($path)) {
                self::copyTree($path, $target);
            } elseif (is_file($path)) {
                copy($path, $target);
            }
        }
    }

    private static function removeTree(string $directory): void
    {
        if (is_file($directory)) {
            @unlink($directory);
            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $path) {
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
