<?php
declare(strict_types=1);

/**
 * CMS console. Runs maintenance tasks from the command line:
 *
 *   php bin/console.php install --name="My Blog" --url=http://localhost/
 *   php bin/console.php migrate
 *   php bin/console.php seed
 *   php bin/console.php theme:sync
 *   php bin/console.php user:create --username=... --email=... --password=... --role=admin
 *   php bin/console.php cache:clear
 *   php bin/console.php status
 *
 * Configuration is read from config.php, or from CMS_CONFIG when set.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Settings;
use App\Core\Slug;
use App\Core\Theme;
use App\Core\ThemeEngine;
use App\Models\Post;
use App\Models\Taxonomy;

$argv = $_SERVER['argv'] ?? [];
array_shift($argv);
$command = array_shift($argv) ?: 'help';

$options = [];
foreach ($argv as $argument) {
    if (preg_match('/^--([a-z0-9_-]+)=(.+)$/i', $argument, $match)) {
        $options[strtolower($match[1])] = $match[2];
        continue;
    }
    if (str_starts_with($argument, '--')) {
        $options[strtolower(substr($argument, 2))] = true;
    }
}

$out = static function (string $message = ''): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

$fail = static function (string $message): never {
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
};

$requireOption = static function (string $key) use ($options, $fail): string {
    if (!isset($options[$key]) || $options[$key] === true || (string)$options[$key] === '') {
        $fail('Opsi --' . $key . ' wajib diisi.');
    }
    return (string)$options[$key];
};

/** Ensure the schema exists (fresh install) and every migration has run. */
$prepare = static function () use ($out): void {
    if (!Database::tableExists('users')) {
        Migrator::installSchema();
        Migrator::markAllShippedAsApplied();
        $out('Skema awal dibuat.');
        return;
    }
    $applied = Migrator::run();
    foreach ($applied as $version) {
        $out('Migrasi dijalankan: ' . $version);
    }
};

try {
    switch ($command) {
        case 'install':
            $prepare();
            $name = $options['name'] ?? 'My Blog';
            $url = rtrim((string)($options['url'] ?? (string)Config::get('site_url', 'http://localhost/')), '/') . '/';
            $existing = (int)(Database::value('SELECT COUNT(*) FROM users') ?: 0);
            if ($existing > 0) {
                $out('Instalasi dilewati: database sudah berisi pengguna.');
                break;
            }
            $username = $options['username'] ?? 'admin';
            $email = $options['email'] ?? 'admin@example.com';
            $password = $options['password'] ?? 'admin-password-123';
            Database::transaction(static function () use ($name, $url, $username, $email, $password): void {
                $userId = Database::insert('users', [
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'display_name' => $username,
                    'role' => 'admin',
                ]);
                $blogId = Database::insert('blogs', [
                    'owner_id' => $userId,
                    'name' => $name,
                    'slug' => Slug::make($name, 'blog'),
                    'description' => '',
                    'theme' => 'aurora',
                    'is_primary' => 1,
                    'status' => 'active',
                ]);
                Database::insert('blog_users', [
                    'blog_id' => $blogId,
                    'user_id' => $userId,
                    'role' => 'owner',
                    'is_owner' => 1,
                ]);
                Database::insert('menus', [
                    'blog_id' => $blogId,
                    'name' => 'Menu Utama',
                    'slug' => 'primary',
                    'location' => 'primary',
                ]);
                Settings::setMany(Settings::defaults(), $blogId);
                Settings::setMany([
                    'site_name' => $name,
                    'site_url' => $url,
                    'theme' => 'aurora',
                ], $blogId);
                log_activity('install', 'CMS dipasang melalui console');
            });
            ThemeEngine::syncBundledThemes();
            $out('Instalasi selesai. Pengguna admin: ' . $username);
            break;

        case 'migrate':
            $prepare();
            $pending = Migrator::pending();
            $out($pending === [] ? 'Tidak ada migrasi tertunda.' : 'Masih tertunda: ' . implode(', ', $pending));
            break;

        case 'theme:sync':
            $prepare();
            $result = ThemeEngine::syncBundledThemes();
            $out(sprintf('%d tema terdaftar dari %d tema bawaan.', $result['registered'], $result['total']));
            break;

        case 'seed':
            $prepare();
            $blogId = (int)(Database::value('SELECT id FROM blogs ORDER BY is_primary DESC, id LIMIT 1') ?: 0);
            if ($blogId <= 0) {
                $fail('Belum ada blog. Jalankan perintah install terlebih dahulu.');
            }
            Context::forceBlog($blogId);
            $authorId = (int)(Database::value('SELECT id FROM users ORDER BY id LIMIT 1') ?: 0);
            if ($authorId <= 0) {
                $fail('Belum ada pengguna. Jalankan perintah install terlebih dahulu.');
            }
            $count = (int)(Database::value('SELECT COUNT(*) FROM posts WHERE blog_id = ?', [$blogId]) ?: 0);
            if ($count > 0 && empty($options['force'])) {
                $out('Blog sudah memiliki artikel. Gunakan --force untuk menambah contoh baru.');
                break;
            }
            $categories = ['Teknologi', 'Desain', 'Bisnis', 'Gaya Hidup', 'Traveling'];
            $categoryIds = [];
            foreach ($categories as $categoryName) {
                $existing = Database::fetch('SELECT id FROM categories WHERE blog_id = ? AND slug = ?', [$blogId, Slug::make($categoryName)]);
                $categoryIds[$categoryName] = $existing
                    ? (int)$existing['id']
                    : Taxonomy::createCategory($categoryName, ['description' => 'Kumpulan artikel ' . $categoryName . '.']);
            }
            $samples = [
                ['Membangun Kebiasaan Menulis Setiap Hari', 'Teknologi', ['produktivitas', 'menulis'], 'Menulis setiap hari bukan soal bakat, tapi sistem. Artikel ini membahas ritme kerja, alat bantu, dan cara menjaga konsistensi tanpa kehilangan kualitas.'],
                ['Panduan Desain Antarmuka yang Aksesibel', 'Desain', ['ui', 'aksesibilitas'], 'Aksesibilitas bukan tambahan opsional. Mulai dari kontras warna, hierarki tipografi, hingga navigasi keyboard yang rapi.'],
                ['Strategi Konten untuk Bisnis Kecil', 'Bisnis', ['marketing', 'strategi'], 'Anggaran terbatas bukan penghalang. Fokus pada satu audiens inti, satu kanal utama, dan jadwal publikasi yang realistis.'],
                ['Rutinitas Pagi yang Benar-Benar Bekerja', 'Gaya Hidup', ['rutinitas', 'kesehatan'], 'Rutinitas pagi terbaik adalah yang bisa dijalankan konsisten. Mulai dari dua kebiasaan kecil, bukan sepuluh.'],
                ['Menjelajah Kota Tua di Pagi Hari', 'Traveling', ['jalan-jalan', 'fotografi'], 'Kota tua punya ritme berbeda saat matahari baru terbit. Catatan perjalanan singkat dengan tips fotografi dan rute jalan kaki.'],
                ['Optimasi SEO Tanpa Mengorbankan Pengalaman Baca', 'Teknologi', ['seo', 'konten'], 'SEO teknis dan kualitas tulisan saling melengkapi. Struktur heading, internal link, dan kecepatan halaman adalah fondasi.'],
            ];
            $inserted = 0;
            foreach ($samples as $index => [$title, $category, $tags, $summary]) {
                $body = '<p>' . $summary . '</p>'
                    . '<h2>Mengapa ini penting</h2><p>Konsistensi mengalahkan intensitas jangka pendek. Mulailah dari langkah kecil yang bisa diulang.</p>'
                    . '<ul><li>Tentukan satu tujuan yang jelas.</li><li>Sederhanakan alat kerja.</li><li>Ukur hasil secara berkala.</li></ul>'
                    . '<blockquote>Kemajuan kecil yang konsisten mengalahkan lonjakan besar yang jarang.</blockquote>';
                $postId = Post::save([
                    'title' => $title,
                    'slug' => Slug::make($title),
                    'body' => $body,
                    'excerpt' => $summary,
                    'status' => 'published',
                    'published_at' => gmdate('Y-m-d H:i:s', time() - ($index * 86400 * 3)),
                    'author_id' => $authorId,
                    'featured' => $index < 3 ? 1 : 0,
                ], [$category], $tags);
                $inserted++;
            }
            $out(sprintf('%d artikel contoh dibuat untuk blog #%d.', $inserted, $blogId));
            break;

        case 'user:create':
            $prepare();
            $username = $requireOption('username');
            $email = $requireOption('email');
            $password = $requireOption('password');
            $role = (string)($options['role'] ?? 'author');
            if (!in_array($role, Auth::ROLES, true)) {
                $fail('Role tidak dikenal: ' . $role);
            }
            if (Database::fetch('SELECT id FROM users WHERE username = ? OR email = ?', [$username, $email])) {
                $fail('Username atau email sudah dipakai.');
            }
            $userId = Database::insert('users', [
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => (string)($options['display_name'] ?? $username),
                'role' => $role,
            ]);
            $blogId = (int)(Database::value('SELECT id FROM blogs ORDER BY is_primary DESC, id LIMIT 1') ?: 1);
            Database::insert('blog_users', [
                'blog_id' => $blogId,
                'user_id' => $userId,
                'role' => 'author',
            ]);
            $out('Pengguna dibuat: ' . $username . ' (#' . $userId . ')');
            break;

        case 'cache:clear':
            Cache::flush();
            $out('Cache dibersihkan.');
            break;

        case 'status':
            $prepare();
            $out('Database  : ' . Config::db()['name']);
            $out('Tabel     : ' . \count(Database::column('SHOW TABLES')));
            $out('Blog      : ' . \count(Database::column('SELECT id FROM blogs')));
            $out('Artikel   : ' . \count(Database::column('SELECT id FROM posts')));
            $out('Tema      : ' . \count(Theme::bundledSlugs()));
            $out('Migrasi   : ' . implode(', ', Migrator::applied() ? array_column(Migrator::applied(), 'version') : ['-']));
            break;

        case 'help':
        default:
            $out('Penggunaan: php bin/console.php <perintah> [opsi]');
            $out();
            $out('  install       Membuat skema dan akun admin pertama.');
            $out('  migrate       Menjalankan migrasi yang tertunda.');
            $out('  seed          Menambahkan artikel contoh.');
            $out('  theme:sync    Mendaftarkan ulang seluruh tema bawaan.');
            $out('  user:create   Membuat pengguna baru.');
            $out('  cache:clear   Menghapus cache berkas.');
            $out('  status        Ringkasan database dan tema.');
            break;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    if (Config::get('debug', false)) {
        fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}
