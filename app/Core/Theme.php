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
    public static function fontPairs(): array
    {
        return [
            '' => 'Tanpa font eksternal (paling cepat)',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap' => 'Inter + Plus Jakarta Sans (bawaan)',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap' => 'Inter saja (netral)',
            'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Roboto+Slab:wght@500;700&display=swap' => 'Roboto + Roboto Slab (klasik)',
            'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;500;600;700&family=Source+Serif+4:wght@600;700&display=swap' => 'Source Sans + Source Serif (editorial)',
            'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap' => 'Nunito (ramah)',
            'https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Open+Sans:wght@400;600;700&display=swap' => 'Merriweather + Open Sans (blog)',
            'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap' => 'Poppins (modern)',
            'https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700&family=Nunito+Sans:wght@400;600;700&display=swap' => 'Lora + Nunito Sans (sastra)',
        ];
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
     * Font pertama = teks, font kedua (bila ada) = judul; bila hanya satu,
     * keduanya memakai font yang sama. Tanpa URL → stack sistem yang aman.
     */
    private static function fontStackFor(string $url, string $key): string
    {
        $fallback = '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
        $families = [];
        if ($url !== '' && preg_match_all('/family=([^&:]+)/i', $url, $matches)) {
            foreach ($matches[1] as $raw) {
                $name = trim(str_replace('+', ' ', rawurldecode($raw)));
                if ($name !== '' && preg_match('/^[a-z0-9 ]+$/i', $name)) {
                    $families[] = $name;
                }
            }
        }
        if ($families === []) {
            return $key === 'font_heading'
                ? '"Plus Jakarta Sans", "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'
                : $fallback;
        }
        $primary = $key === 'font_heading'
            ? ($families[1] ?? $families[0])
            : $families[0];
        return '"' . $primary . '", ' . $fallback;
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
