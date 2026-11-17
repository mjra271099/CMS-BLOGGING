<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Request context: resolve the active blog plus lazy site metadata.
 */
final class Context
{
    private static ?array $blog = null;
    private static ?int $forcedBlogId = null;
    private static array $request = [];

    public static function forceBlog(?int $blogId): void
    {
        self::$forcedBlogId = $blogId;
        self::$blog = null;
        Settings::flush();
        Cache::flushGroup('settings');
        Cache::flushGroup('theme');
    }

    public static function forcedBlogId(): ?int
    {
        return self::$forcedBlogId;
    }

    public static function blog(): array
    {
        if (self::$blog !== null) {
            return self::$blog;
        }
        if (self::$forcedBlogId !== null) {
            $blog = self::find(self::$forcedBlogId);
        } else {
            $blog = self::resolveFromHost();
        }
        self::$blog = $blog;
        self::applyTimezone($blog);
        return $blog;
    }

    /**
     * Terapkan zona waktu blog ke proses PHP agar date(), strtotime(), dan
     * penjadwalan artikel mengikuti pengaturan blog (bukan zona default server).
     * Aman dipanggil berulang; diabaikan bila zona tidak valid.
     */
    private static function applyTimezone(array $blog): void
    {
        $tz = (string)($blog['timezone'] ?? '');
        if ($tz === '') {
            $tz = Settings::get('timezone', '');
        }
        if ($tz === '') {
            return;
        }
        $current = date_default_timezone_get();
        if ($tz !== $current && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
    }

    public static function blogId(): int
    {
        return (int)(self::blog()['id'] ?? 1);
    }

    public static function flushBlog(): void
    {
        self::$blog = null;
        Settings::flush();
        Cache::flushGroup('settings');
        Cache::flushGroup('theme');
    }

    private static function find(int $id): array
    {
        $blog = Database::fetch('SELECT * FROM blogs WHERE id = ?', [$id]);
        return $blog ?: self::fallback();
    }

    /**
     * Resolve blog by requested host: exact custom domain, subdomain or primary.
     */
    private static function resolveFromHost(): array
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('~^www\.~', '', $host) ?: $host;
        if ($host !== '') {
            $blog = Database::fetch(
                'SELECT b.* FROM blogs b WHERE b.status = "active" AND (
                    LOWER(b.domain) = ? OR LOWER(b.custom_domain) = ?
                    OR (? <> "" AND LOWER(SUBSTRING_INDEX(b.domain, ".", 1)) = ?)
                 ) ORDER BY b.id LIMIT 1',
                [$host, $host, $host, $host]
            );
            if ($blog) {
                return self::decorate($blog);
            }
        }
        $primary = Database::fetch('SELECT * FROM blogs WHERE status = "active" ORDER BY is_primary DESC, id ASC LIMIT 1');
        return $primary ? self::decorate($primary) : self::fallback();
    }

    private static function decorate(array $blog): array
    {
        $blog['id'] = (int)$blog['id'];
        return $blog;
    }

    private static function fallback(): array
    {
        return [
            'id' => 1,
            'name' => 'Blog',
            'slug' => 'blog',
            'domain' => '',
            'custom_domain' => null,
            'description' => '',
            'theme' => Theme::defaultSlug(),
            'logo' => null,
            'favicon' => null,
            'language' => 'id',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'owner_id' => 0,
            'settings' => '{}',
            'is_primary' => 1,
        ];
    }

    public static function setRequest(string $key, mixed $value): void
    {
        self::$request[$key] = $value;
    }

    public static function getRequest(string $key, mixed $default = null): mixed
    {
        return self::$request[$key] ?? $default;
    }

    public static function isFront(): bool
    {
        return PHP_SAPI !== 'cli' && !str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/');
    }
}
