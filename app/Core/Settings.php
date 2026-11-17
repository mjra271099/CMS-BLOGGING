<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Per-blog key/value settings with an in-request cache.
 */
final class Settings
{
    private static array $cache = [];
    private static array $dirty = [];

    public static function get(string $key, string $default = '', ?int $blogId = null): string
    {
        $blogId ??= Context::blogId();
        $all = self::all($blogId);
        return (string)($all[$key] ?? $default);
    }

    public static function bool(string $key, bool $default = false, ?int $blogId = null): bool
    {
        $value = self::get($key, $default ? '1' : '0', $blogId);
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0, ?int $blogId = null): int
    {
        return (int)self::get($key, (string)$default, $blogId);
    }

    public static function all(?int $blogId = null): array
    {
        $blogId ??= Context::blogId();
        if (!isset(self::$cache[$blogId])) {
            // The group must be "settings": Cache::remember() stores the entry
            // in "default" when omitted, so Settings::flush()/flushGroup('settings')
            // could never invalidate it and saved values kept reading stale.
            self::$cache[$blogId] = Cache::remember('settings.' . $blogId, 300, static function () use ($blogId): array {
                try {
                    return Database::run(
                        'SELECT setting_key, setting_value FROM settings WHERE blog_id = ?',
                        [$blogId]
                    )->fetchAll(\PDO::FETCH_KEY_PAIR);
                } catch (\Throwable) {
                    return [];
                }
            }, 'settings');
        }
        return self::$cache[$blogId];
    }

    public static function set(string $key, string $value, ?int $blogId = null): void
    {
        $blogId ??= Context::blogId();
        self::setMany([$key => $value], $blogId);
    }

    public static function setMany(array $values, ?int $blogId = null): void
    {
        $blogId ??= Context::blogId();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (blog_id, setting_key, setting_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $key => $value) {
            $stmt->execute([$blogId, (string)$key, (string)$value]);
            self::$cache[$blogId][(string)$key] = (string)$value;
        }
        Cache::flushGroup('settings');
    }

    public static function forget(string $key, ?int $blogId = null): void
    {
        $blogId ??= Context::blogId();
        Database::run('DELETE FROM settings WHERE blog_id = ? AND setting_key = ?', [$blogId, $key]);
        unset(self::$cache[$blogId][$key]);
        Cache::flushGroup('settings');
    }

    /**
     * Kunci yang TIDAK boleh ikut direset otomatis: identitas aktif (tema),
     * rahasia integrasi, dan status yang memengaruhi keamanan/penagihan.
     * Reset eksplisit per-kunci masih diizinkan bila pemanggil menyebutnya.
     */
    private const RESET_PROTECTED = ['theme', 'default_role'];

    /**
     * Kembalikan pengaturan ke nilai bawaan. Bila $keys diberikan, hanya kunci
     * tersebut yang direset; jika null, seluruh pengaturan blog direset.
     * Nilai default disimpan eksplisit (bukan dihapus) agar nilai kosong
     * ("") pada default tetap berlaku dan tidak jatuh ke fallback kode.
     *
     * Saat reset massal ($keys === null) kunci sensitif seperti `theme` dan
     * rahasia integrasi (mis. token API) dikecualikan agar konfigurasi aktif
     * dan kredensial tidak hilang tanpa sengaja.
     *
     * @param string[]|null $keys
     */
    public static function reset(?array $keys = null, ?int $blogId = null): void
    {
        $blogId ??= Context::blogId();
        $defaults = self::defaults();
        if ($keys === null) {
            foreach (self::RESET_PROTECTED as $protected) {
                unset($defaults[$protected]);
            }
            // Jangan reset rahasia integrasi yang tidak ada di defaults()
            // (mis. tmdb_api_token) — hanya kunci default publik yang direset.
            self::setMany($defaults, $blogId);
            return;
        }
        $subset = [];
        foreach ($keys as $key) {
            if (in_array($key, self::RESET_PROTECTED, true)) {
                continue;
            }
            if (array_key_exists($key, $defaults)) {
                $subset[$key] = $defaults[$key];
            }
        }
        if ($subset !== []) {
            self::setMany($subset, $blogId);
        }
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Site-level defaults, used when a blog has no value yet.
     */
    public static function defaults(): array
    {
        return [
            'site_name' => 'My Blog',
            'site_description' => '',
            'site_url' => '',
            'language' => 'id',
            'timezone' => 'Asia/Jakarta',
            'posts_per_page' => '9',
            'permalink_format' => 'blogger',
            'homepage_mode' => 'posts',
            'homepage_page_id' => '0',
            'allow_comments' => '1',
            'comment_moderation' => '1',
            'comments_per_page' => '50',
            'theme' => '',
            'seo_title' => '',
            'seo_description' => '',
            'seo_keywords' => '',
            'google_site_verification' => '',
            'bing_site_verification' => '',
            'yandex_site_verification' => '',
            'social_image' => '',
            'twitter_site' => '',
            'organization_name' => '',
            'organization_logo' => '',
            'robots_policy' => 'index,follow',
            'sitemap_enabled' => '1',
            'feed_items' => '20',
            'logo_url' => '',
            'favicon_url' => '',
            'footer_text' => '',
            'maintenance_mode' => '0',
            'registration_enabled' => '0',
            'default_role' => 'author',
            'media_max_mb' => '5',
            'media_webp' => '1',
            'cache_ttl' => '300',
            'cdn_url' => '',
            'qr_download_direct_url' => '',
            'analytics_code' => '',
            'header_code' => '',
            'footer_code' => '',
        ];
    }
}
