<?php
declare(strict_types=1);

namespace App\Core;

/**
 * File cache with group flushing and atomic writes.
 */
final class Cache
{
    private static ?string $dir = null;
    private static array $memory = [];

    public static function enabled(): bool
    {
        return (bool)Config::get('cache_enabled', true);
    }

    public static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = Config::get('cache_dir', CMS_ROOT . '/storage/cache');
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0755, true);
            }
        }
        return self::$dir;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (isset(self::$memory[$key])) {
            return self::$memory[$key]['value'];
        }
        if (!self::enabled()) {
            return $default;
        }
        $file = self::path($key);
        if (!is_file($file)) {
            return $default;
        }
        $payload = @unserialize((string)file_get_contents($file), ['allowed_classes' => false]);
        if (!is_array($payload) || ($payload['expires'] !== 0 && $payload['expires'] < time())) {
            @unlink($file);
            return $default;
        }
        self::$memory[$key] = $payload;
        return $payload['value'];
    }

    public static function put(string $key, mixed $value, int $ttl = 300, string $group = 'default'): void
    {
        self::$memory[$key] = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0, 'group' => $group];
        if (!self::enabled()) {
            return;
        }
        $payload = serialize(self::$memory[$key]);
        $file = self::path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    public static function remember(string $key, int $ttl, callable $callback, string $group = 'default'): mixed
    {
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        self::put($key, $value, $ttl, $group);
        return $value;
    }

    public static function forget(string $key): void
    {
        unset(self::$memory[$key]);
        @unlink(self::path($key));
    }

    public static function flush(): void
    {
        self::$memory = [];
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    public static function flushGroup(string $group): void
    {
        self::$memory = [];
        if (!self::enabled()) {
            return;
        }
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            $payload = @unserialize((string)file_get_contents($file), ['allowed_classes' => false]);
            if (is_array($payload) && ($payload['group'] ?? '') === $group) {
                @unlink($file);
            }
        }
    }

    public static function stats(): array
    {
        $files = glob(self::dir() . '/*.cache') ?: [];
        return ['files' => count($files), 'bytes' => array_sum(array_map('filesize', $files))];
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . hash('sha256', $key) . '.cache';
    }
}
