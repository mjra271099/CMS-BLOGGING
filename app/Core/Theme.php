<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Theme runtime: catalog lookup, per-blog settings, and page rendering.
 *
 * A theme is a folder under /themes with:
 *   theme.json   metadata + settings schema + declared widget areas
 *   layout.php   the page layout (receives $page and $ui)
 *   style.css    theme identity (design tokens + layout rules)
 *   screenshot.svg
 *   functions.php  optional hook registration
 */
final class Theme
{
    private static ?Theme $instance = null;
    private static array $metaCache = [];
    private string $slug;
    private array $meta;
    private array $settings;

    private function __construct(string $slug)
    {
        $this->slug = $slug;
        $this->meta = self::meta($slug);
        $this->settings = $this->resolveSettings();
    }

    public static function instance(): Theme
    {
        if (self::$instance === null) {
            $slug = Settings::get('theme', self::defaultSlug());
            $blogTheme = (string)(Context::blog()['theme'] ?? '');
            if ($blogTheme !== '' && self::exists($blogTheme)) {
                $slug = $blogTheme;
            }
            // Admins can preview any installed theme without activating it.
            $preview = (string)($_GET['theme_preview'] ?? '');
            if ($preview !== '' && self::exists($preview) && Auth::isAdmin()) {
                $slug = $preview;
            }
            if (!self::exists($slug)) {
                $slug = self::defaultSlug();
            }
            self::$instance = new self($slug);
        }
        return self::$instance;
    }

    /**
     * Explicitly load a theme by slug (customizer, preview, exports).
     */
    public static function make(string $slug): Theme
    {
        $slug = (string)preg_replace('/[^a-z0-9_-]/i', '', $slug);
        if (!self::exists($slug)) {
            throw new ValidationException('Tema tidak ditemukan: ' . $slug);
        }
        return new self($slug);
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function defaultSlug(): string
    {
        $bundled = self::bundledSlugs();
        return $bundled[0] ?? 'amp-theme';
    }

    public static function dir(string $slug): string
    {
        return CMS_ROOT . '/themes/' . preg_replace('/[^a-z0-9_-]/i', '', $slug);
    }

    public static function exists(string $slug): bool
    {
        return $slug !== '' && is_file(self::dir($slug) . '/theme.json');
    }

    public static function meta(string $slug): array
    {
        if (isset(self::$metaCache[$slug])) {
            return self::$metaCache[$slug];
        }
        $file = self::dir($slug) . '/theme.json';
        $defaults = [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'version' => '1.0.0',
            'author' => 'CMS Studio',
            'description' => '',
            'category' => 'personal',
            'tags' => [],
            'screenshot' => 'screenshot.svg',
            'supports' => ['sidebar' => 'right', 'hero' => true, 'builder' => true],
            'areas' => ['header', 'announcement', 'before-content', 'sidebar', 'after-content', 'footer-1', 'footer-2', 'footer-3'],
            'settings' => [],
            'layouts' => ['home' => 'default', 'post' => 'default', 'page' => 'default'],
        ];
        if (!is_file($file)) {
            return self::$metaCache[$slug] = $defaults + ['slug' => $slug];
        }
        $decoded = json_decode((string)file_get_contents($file), true);
        $meta = is_array($decoded) ? array_replace_recursive($defaults, $decoded) : $defaults;
        $meta['slug'] = $slug;
        return self::$metaCache[$slug] = $meta;
    }

    public static function bundledSlugs(): array
    {
        $slugs = [];
        foreach (glob(CMS_ROOT . '/themes/*/theme.json') ?: [] as $file) {
            $slugs[] = basename(dirname($file));
        }
        sort($slugs);
        return $slugs;
    }

    public static function catalog(): array
    {
        $items = [];
        foreach (self::bundledSlugs() as $slug) {
            $items[] = self::meta($slug);
        }
        return $items;
    }

    /**
     * Kurasi pasangan font siap pakai. Nilai = URL stylesheet Google Fonts,
     * sehingga tetap kompatibel dengan penyimpanan `font_pair` yang lama
     * (tidak perlu migrasi data) sekaligus menghilangkan kebutuhan admin
     * mengetik URL/CSS font secara manual.
     *
     * @return array<string,string> url => label
     */
    /**
     * Katalog font siap pakai. Nilai = URL stylesheet Google Fonts sehingga
     * tetap kompatibel dengan penyimpanan `font_pair` lama (tanpa migrasi data).
     * Setiap entri menyimpan kategori agar fallback CSS-nya cocok (serif tetap
     * serif meski font eksternal gagal dimuat).
     *
     * @return array<string,array{label:string,category:string,note:string}>
     */
    public static function fontCatalog(): array
    {
        // Setiap entri: URL Google Fonts (kompatibel dengan data lama),
        // label, kategori fallback, catatan singkat, dan peran font.
        // `body`/`heading` ditulis eksplisit karena urutan `family=` pada URL
        // tidak menjamin font mana yang dipakai untuk teks.
        $catalog = [];

        // --- Satu font untuk semua (tanpa eksternal + sans populer) ----------
        $catalog[''] = ['label' => 'Tanpa font eksternal (paling cepat)', 'category' => 'sans',
            'note' => 'Memakai font bawaan perangkat.', 'families' => [], 'body' => '', 'heading' => ''];

        $single = [
            ['Inter', 'Inter:wght@400;500;600;700', 'Inter', 'sans', 'Netral, terbaca di semua ukuran.'],
            ['Plus Jakarta Sans', 'Plus+Jakarta+Sans:wght@400;500;600;700;800', 'Plus Jakarta Sans', 'sans', 'Modern, cocok untuk startup.'],
            ['Poppins', 'Poppins:wght@400;500;600;700', 'Poppins', 'sans', 'Geometris dan ramah.'],
            ['Nunito', 'Nunito:wght@400;600;700;800', 'Nunito', 'sans', 'Membulat, terasa akrab.'],
            ['Manrope', 'Manrope:wght@400;500;600;700;800', 'Manrope', 'sans', 'Rapi dan profesional.'],
            ['DM Sans', 'DM+Sans:wght@400;500;700', 'DM Sans', 'sans', 'Bersih dengan kesan lembut.'],
            ['Work Sans', 'Work+Sans:wght@400;500;600;700', 'Work Sans', 'sans', 'Jelas untuk teks panjang.'],
            ['Rubik', 'Rubik:wght@400;500;600;700', 'Rubik', 'sans', 'Sedikit membulat, tegas.'],
            ['Outfit', 'Outfit:wght@400;500;600;700', 'Outfit', 'sans', 'Judul besar yang menonjol.'],
            ['Figtree', 'Figtree:wght@400;500;600;700', 'Figtree', 'sans', 'Netral masa kini.'],
            ['Public Sans', 'Public+Sans:wght@400;500;600;700', 'Public Sans', 'sans', 'Praktis untuk konten padat.'],
            ['Be Vietnam Pro', 'Be+Vietnam+Pro:wght@400;500;600;700', 'Be Vietnam Pro', 'sans', 'Modern dan bersih.'],
            ['Sora', 'Sora:wght@400;500;600;700', 'Sora', 'sans', 'Teknis, cocok untuk topik teknologi.'],
            ['Space Grotesk', 'Space+Grotesk:wght@400;500;600;700', 'Space Grotesk', 'sans', 'Berani dan berbeda.'],
            ['Roboto', 'Roboto:wght@400;500;700', 'Roboto', 'sans', 'Familiar di Android.'],
            ['Open Sans', 'Open+Sans:wght@400;600;700', 'Open Sans', 'sans', 'Aman dan netral.'],
            ['Lato', 'Lato:wght@400;700', 'Lato', 'sans', 'Hangat dan mudah dibaca.'],
            ['Karla', 'Karla:wght@400;500;600;700', 'Karla', 'sans', 'Ringkas dan sedikit jenaka.'],
            ['Montserrat', 'Montserrat:wght@400;500;600;700', 'Montserrat', 'sans', 'Kuat untuk judul.'],
            ['Raleway', 'Raleway:wght@400;500;600;700', 'Raleway', 'sans', 'Elegan dan tipis.'],
            ['Urbanist', 'Urbanist:wght@400;500;600;700', 'Urbanist', 'sans', 'Minimalis dan modern.'],
            ['Lexend', 'Lexend:wght@400;500;600;700', 'Lexend', 'sans', 'Dirancang untuk kenyamanan baca.'],
        ];
        foreach ($single as [$label, $query, $family, $category, $note]) {
            $url = 'https://fonts.googleapis.com/css2?family=' . $query . '&display=swap';
            $catalog[$url] = ['label' => $label, 'category' => $category, 'note' => $note,
                'families' => [$family], 'body' => $family, 'heading' => $family];
        }

        // --- Pasangan teks + judul ------------------------------------------
        // [label, query teks, query judul, keluarga teks, keluarga judul, kategori, catatan]
        $pairs = [
            ['Inter + Plus Jakarta Sans', 'Inter:wght@400;500;600;700', 'Plus+Jakarta+Sans:wght@500;600;700;800', 'Inter', 'Plus Jakarta Sans', 'sans', 'Bawaan. Netral dan sangat terbaca.'],
            ['Source Sans + Source Serif', 'Source+Sans+3:wght@400;500;600;700', 'Source+Serif+4:wght@600;700', 'Source Sans 3', 'Source Serif 4', 'serif', 'Rapi seperti penerbitan daring.'],
            ['Merriweather + Open Sans', 'Open+Sans:wght@400;600;700', 'Merriweather:wght@400;700', 'Open Sans', 'Merriweather', 'serif', 'Klasik untuk blog bacaan panjang.'],
            ['Lora + Nunito Sans', 'Nunito+Sans:wght@400;600;700', 'Lora:wght@400;600;700', 'Nunito Sans', 'Lora', 'serif', 'Bernuansa sastra.'],
            ['Roboto + Roboto Slab', 'Roboto:wght@400;500;700', 'Roboto+Slab:wght@500;700', 'Roboto', 'Roboto Slab', 'serif', 'Judul tebal bergaya koran.'],
            ['Playfair Display + Source Sans', 'Source+Sans+3:wght@400;600;700', 'Playfair+Display:wght@500;600;700', 'Source Sans 3', 'Playfair Display', 'serif', 'Mewah, cocok untuk mode/fesyen.'],
            ['Libre Baskerville + PT Sans', 'PT+Sans:wght@400;700', 'Libre+Baskerville:wght@400;700', 'PT Sans', 'Libre Baskerville', 'serif', 'Formal dan tepercaya.'],
            ['Crimson Pro + Karla', 'Karla:wght@400;600;700', 'Crimson+Pro:wght@400;600;700', 'Karla', 'Crimson Pro', 'serif', 'Hangat, seperti buku cetak.'],
            ['Noto Serif + Noto Sans', 'Noto+Sans:wght@400;600;700', 'Noto+Serif:wght@400;600;700', 'Noto Sans', 'Noto Serif', 'serif', 'Dukungan aksara paling luas.'],
            ['Bitter + Public Sans', 'Public+Sans:wght@400;600;700', 'Bitter:wght@400;600;700', 'Public Sans', 'Bitter', 'serif', 'Tegas namun tetap santai.'],
            ['Inter + JetBrains Mono', 'Inter:wght@400;500;600;700', 'JetBrains+Mono:wght@400;500;700', 'Inter', 'JetBrains Mono', 'mono', 'Untuk blog pemrograman.'],
            ['IBM Plex Sans + Plex Mono', 'IBM+Plex+Sans:wght@400;500;600;700', 'IBM+Plex+Mono:wght@400;500;600', 'IBM Plex Sans', 'IBM Plex Mono', 'mono', 'Rapi, nuansa teknis.'],
        ];
        foreach ($pairs as [$label, $bodyQuery, $headingQuery, $bodyFamily, $headingFamily, $category, $note]) {
            $url = 'https://fonts.googleapis.com/css2?family=' . $bodyQuery . '&family=' . $headingQuery . '&display=swap';
            $catalog[$url] = ['label' => $label, 'category' => $category, 'note' => $note,
                'families' => [$bodyFamily, $headingFamily], 'body' => $bodyFamily, 'heading' => $headingFamily];
        }

        return $catalog;
    }

    /**
     * Pasangan font untuk `<select>`: url => label, terurut agar mudah dicari.
     *
     * @return array<string,string>
     */
    public static function fontPairs(): array
    {
        $groups = ['sans' => 'Sans-serif (modern)', 'serif' => 'Serif (klasik)', 'mono' => 'Monospace (teknis)'];
        $byCategory = ['sans' => [], 'serif' => [], 'mono' => []];
        foreach (self::fontCatalog() as $url => $meta) {
            $category = (string)($meta['category'] ?? 'sans');
            $byCategory[$category][$url] = (string)$meta['label'];
        }
        $flat = ['' => self::fontCatalog()['']['label']];
        foreach ($groups as $category => $heading) {
            foreach ($byCategory[$category] as $url => $label) {
                $flat[$url] = $label;
            }
        }
        return $flat;
    }

    /**
     * Urutan keluarga font untuk teks dan judul dari sebuah pasangan.
     * Peran ditulis eksplisit di katalog (`body`/`heading`) karena urutan
     * `family=` pada URL Google Fonts tidak menjamin font mana untuk teks.
     *
     * @return array{body:array<int,string>,heading:array<int,string>,fallback:string}
     */
    public static function fontFamilies(string $pair): array
    {
        $meta = self::fontPairMeta($pair);
        $fallback = match ($meta['category']) {
            'serif' => 'Georgia, "Times New Roman", Times, serif',
            'mono' => '"SFMono-Regular", Consolas, "Liberation Mono", monospace',
            default => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        };
        $declared = [];
        foreach ($meta['families'] as $family) {
            $declared[] = (string)$family;
        }
        // Pasangan lama/kustom tidak ada di katalog: baca dari URL.
        if ($declared === []) {
            $declared = self::familiesFromUrl($pair);
        }
        if ($declared === []) {
            return ['body' => [], 'heading' => [], 'fallback' => $fallback];
        }
        $body = $meta['body'] !== '' && in_array($meta['body'], $declared, true)
            ? $meta['body']
            : $declared[0];
        $heading = $meta['heading'] !== '' && in_array($meta['heading'], $declared, true)
            ? $meta['heading']
            : $body;
        return [
            'body' => array_values(array_unique(array_merge([$body], $declared))),
            'heading' => array_values(array_unique(array_merge([$heading], $declared))),
            'fallback' => $fallback,
        ];
    }

    /**
     * Keterangan singkat + kategori untuk sebuah pasangan font.
     *
     * @return array{label:string,category:string,note:string}
     */
    public static function fontPairMeta(string $url): array
    {
        return self::fontCatalog()[$url]
            ?? ['label' => 'Kustom', 'category' => 'sans', 'note' => '', 'families' => [], 'body' => '', 'heading' => ''];
    }

    /**
     * Untuk pasangan di luar katalog (mis. URL lama yang dipilih manual),
     * tebak keluarga font dari URL agar tipografi tetap bekerja.
     *
     * @return array<int,string>
     */
    private static function familiesFromUrl(string $url): array
    {
        $families = [];
        if ($url !== '' && preg_match_all('/family=([^&:]+)/i', $url, $matches)) {
            foreach ($matches[1] as $raw) {
                $name = trim(str_replace('+', ' ', rawurldecode($raw)));
                if ($name !== '' && preg_match('/^[a-z0-9 ]+$/i', $name)) {
                    $families[] = $name;
                }
            }
        }
        return $families;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function metadata(): array
    {
        return $this->meta;
    }

    public function name(): string
    {
        return (string)$this->meta['name'];
    }

    public function setting(string $key, ?string $default = null): string
    {
        if (array_key_exists($key, $this->settings) && $this->settings[$key] !== null) {
            return (string)$this->settings[$key];
        }
        if ($default !== null) {
            return $default;
        }
        foreach ($this->meta['settings'] as $definition) {
            if (($definition['key'] ?? '') === $key) {
                return (string)($definition['default'] ?? '');
            }
        }
        return '';
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function supports(string $feature, mixed $default = false): mixed
    {
        return $this->meta['supports'][$feature] ?? $default;
    }

    public function areas(): array
    {
        return (array)($this->meta['areas'] ?? []);
    }

    public function asset(string $file = 'style.css'): string
    {
        $path = 'themes/' . $this->slug . '/' . ltrim($file, '/');
        $absolute = self::dir($this->slug) . '/' . ltrim($file, '/');
        if (!is_file($absolute)) {
            return '';
        }
        return url($path) . '?v=' . rawurlencode((string)filemtime($absolute));
    }

    /**
     * Render the whole page using the theme layout.
     */
    public function render(array $page): string
    {
        $layout = self::dir($this->slug) . '/layout.php';
        if (!is_file($layout)) {
            $layout = self::dir(self::defaultSlug()) . '/layout.php';
        }
        $ui = new \App\Theme\Ui($this, $page);
        $functions = self::dir($this->slug) . '/functions.php';
        if (is_file($functions)) {
            (static function (string $__file) use ($ui, $page): void {
                require_once $__file;
            })($functions);
        }
        return View::evaluate($layout, ['page' => $page, 'ui' => $ui, 'theme' => $this]);
    }

    /**
     * Resolve theme settings: schema defaults + stored values.
     */
    private function resolveSettings(): array
    {
        $values = [];
        try {
            $rows = Database::run(
                'SELECT setting_key, setting_value FROM theme_settings WHERE blog_id = ? AND theme = ?',
                [Context::blogId(), $this->slug]
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
            $values = $rows ?: [];
        } catch (\Throwable) {
            $values = [];
        }
        $merged = [];
        foreach ($this->meta['settings'] as $definition) {
            $key = (string)($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $merged[$key] = $values[$key] ?? (string)($definition['default'] ?? '');
        }
        return $merged + $values;
    }

    public function saveSettings(array $values): void
    {
        $existing = Database::run(
            'SELECT setting_key FROM theme_settings WHERE blog_id = ? AND theme = ?',
            [Context::blogId(), $this->slug]
        )->fetchAll(\PDO::FETCH_COLUMN);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO theme_settings (blog_id, theme, setting_key, setting_value) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($this->meta['settings'] as $definition) {
            $key = (string)($definition['key'] ?? '');
            if ($key === '' || !array_key_exists($key, $values)) {
                continue;
            }
            $value = $values[$key];
            $type = (string)($definition['type'] ?? 'text');
            // Field opsional (mis. primary_dark) boleh dikosongkan agar nilai
            // diturunkan otomatis; hapus barisnya supaya fallback berlaku.
            if (trim((string)$value) === '' && !empty($definition['optional'])) {
                Database::run(
                    'DELETE FROM theme_settings WHERE blog_id = ? AND theme = ? AND setting_key = ?',
                    [Context::blogId(), $this->slug, $key]
                );
                continue;
            }
            if ($type === 'checkbox') {
                $value = $value ? '1' : '0';
            } elseif ($type === 'color' && !preg_match('/^#[0-9a-f]{3,8}$/i', (string)$value)) {
                continue;
            } elseif ($type === 'select') {
                // Options may be a value => label map or a list of
                // {value, label} arrays; validate against the value, never the
                // label, otherwise a valid choice is silently dropped.
                $options = (array)($definition['options'] ?? []);
                if (($definition['source'] ?? '') === 'font_pairs') {
                    $options = self::fontPairs();
                }
                $allowed = [];
                foreach ($options as $optionKey => $optionLabel) {
                    $allowed[] = is_array($optionLabel)
                        ? (string)($optionLabel['value'] ?? '')
                        : (string)$optionKey;
                }
                if (!in_array((string)$value, $allowed, true)) {
                    continue;
                }
            } elseif ($type === 'number') {
                $value = (string)max((int)($definition['min'] ?? 0), min((int)($definition['max'] ?? 200), (int)$value));
            } elseif ($type === 'image' && !valid_asset_url((string)$value)) {
                continue;
            }
            $stmt->execute([Context::blogId(), $this->slug, $key, (string)$value]);
        }
        $this->settings = $this->resolveSettings();
        Cache::flushGroup('theme');
    }

    /**
     * Hapus semua pengaturan kustomisasi tema ini sehingga kembali ke nilai
     * bawaan dari theme.json. Baris lama (mis. font_family) juga dihapus agar
     * nilainya benar-benar kembali ke default.
     */
    public function resetSettings(): void
    {
        Database::run(
            'DELETE FROM theme_settings WHERE blog_id = ? AND theme = ?',
            [Context::blogId(), $this->slug]
        );
        $this->settings = $this->resolveSettings();
        Cache::flushGroup('theme');
    }

    /**
     * Turunkan CSS font stack dari URL Google Fonts pada `font_pair`.
     * Font pertama = teks, font kedua (bila ada) = judul. Fallback mengikuti
     * kategori pasangan (sans/serif/mono) supaya jenis huruf tetap konsisten
     * bila font eksternal gagal dimuat.
     *
     * @param string      $key  'font_family' (teks) atau 'font_heading' (judul)
     * @param string|null $pair Pasangan font; null = pakai setting tersimpan
     */
    public static function fontStackFor(string $url, string $key, ?string $pair = null): string
    {
        $pair ??= $url;
        $roles = self::fontFamilies($pair);
        $candidates = $key === 'font_heading' ? $roles['heading'] : $roles['body'];
        if ($candidates === []) {
            return $roles['fallback'];
        }
        // Bangun stack: font utama, cadangan pasangannya, lalu fallback kategori.
        $stack = [];
        $seen = [];
        foreach ($candidates as $family) {
            $lower = strtolower($family);
            if ($lower === '' || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $stack[] = '"' . $family . '"';
        }
        foreach (explode(',', $roles['fallback']) as $fallbackFamily) {
            $fallbackFamily = trim($fallbackFamily);
            $lower = strtolower(trim($fallbackFamily, '"\''));
            if ($fallbackFamily === '' || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $stack[] = $fallbackFamily;
        }
        return implode(', ', $stack);
    }

    /**
     * Turunkan varian lebih gelap dari sebuah warna hex (#rgb atau #rrggbb).
     * Dipakai untuk `primary_dark` agar admin tidak perlu memilih dua warna.
     */
    public static function darkenHex(string $hex, float $amount = 0.22): ?string
    {
        $hex = trim($hex);
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $hex, $m)) {
            return null;
        }
        $body = $m[1];
        if (strlen($body) === 3) {
            $body = $body[0] . $body[0] . $body[1] . $body[1] . $body[2] . $body[2];
        }
        $parts = [];
        foreach ([0, 2, 4] as $i) {
            $channel = (int)hexdec(substr($body, $i, 2));
            $channel = (int)round($channel * (1 - max(0.0, min(1.0, $amount))));
            $parts[] = str_pad(dechex(max(0, min(255, $channel))), 2, '0', STR_PAD_LEFT);
        }
        return '#' . implode('', $parts);
    }

    /**
     * CSS variables from customizer settings, emitted in the page head.
     */
    public function customizerCss(): string
    {
        $mapping = [
            'primary_color' => '--t-primary',
            'primary_dark' => '--t-primary-dark',
            'accent_color' => '--t-accent',
            'background_color' => '--t-bg',
            'surface_color' => '--t-surface',
            'text_color' => '--t-text',
            'muted_color' => '--t-muted',
            'border_color' => '--t-border',
            'font_family' => '--t-font',
            'font_heading' => '--t-font-head',
            'font_size' => '--t-base-size',
            'radius' => '--t-radius',
            'content_width' => '--t-width',
            'header_style' => '--t-header-style',
            'footer_style' => '--t-footer-style',
            'card_style' => '--t-card-style',
            'spacing' => '--t-space',
        ];
        $rules = [];
        foreach ($mapping as $key => $variable) {
            $value = $this->setting($key);
            // `font_family`/`font_heading` are no longer edited directly; they
            // are derived from the chosen `font_pair`. Nilai derived menang atas
            // baris lama (font_family/font_heading) agar pasangan font yang
            // dipilih admin benar-benar berlaku, sementara baris lama dibiarkan
            // di database demi kompatibilitas mundur.
            if (in_array($key, ['font_family', 'font_heading'], true)) {
                $derived = self::fontStackFor($this->setting('font_pair'), $key);
                if ($derived !== '') {
                    $value = $derived;
                }
            }            // `primary_dark` is a shade of the brand color. Kalau admin tidak
            // memilihnya sendiri, hitung otomatis dari `primary_color` supaya
            // pemula cukup memilih satu warna. Nilai eksplisit tetap menang.
            if ($key === 'primary_dark' && trim($value) === '') {
                $value = self::darkenHex($this->setting('primary_color')) ?? '';
            }
            if ($value === '') {
                continue;
            }
            $unit = match ($key) {
                'font_size', 'radius', 'content_width', 'spacing' => 'px',
                default => '',
            };
            if (in_array($key, ['font_size', 'radius', 'content_width', 'spacing'], true)) {
                $value = (int)$value . $unit;
            }
            $rules[] = $variable . ':' . $value;
        }
        $css = $rules ? ':root{' . implode(';', $rules) . '}' : '';
        if ($this->setting('custom_css') !== '') {
            $css .= "\n" . $this->setting('custom_css');
        }
        return $css;
    }

    public function layoutOptions(): array
    {
        $options = [];
        if ($this->setting('sidebar_position') !== '') {
            $options['sidebar'] = $this->setting('sidebar_position');
        }
        if ($this->setting('header_style') !== '') {
            $options['header'] = $this->setting('header_style');
        }
        if ($this->setting('footer_style') !== '') {
            $options['footer'] = $this->setting('footer_style');
        }
        if ($this->setting('card_style') !== '') {
            $options['cards'] = $this->setting('card_style');
        }
        return $options;
    }

    public function bodyClasses(): string
    {
        $classes = ['theme-' . $this->slug];
        foreach ($this->layoutOptions() as $key => $value) {
            $classes[] = 'opt-' . $key . '-' . preg_replace('/[^a-z0-9-]/i', '', $value);
        }
        return implode(' ', $classes);
    }
}
