<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal request abstraction (query, body, files, headers).
 */
final class Request
{
    public static function method(): string
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string)$_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $method;
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::input($key, $default);
        return is_string($value) ? trim($value) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int)self::input($key, $default);
    }

    public static function bool(string $key): bool
    {
        $value = self::input($key, false);
        return in_array($value, ['1', 'on', 'true', 'yes', 1, true], true);
    }

    public static function array(string $key): array
    {
        $value = self::input($key, []);
        return is_array($value) ? $value : [];
    }

    public static function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string)($_SERVER[$key] ?? '');
    }

    public static function ip(): string
    {
        $forwarded = self::header('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function path(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        return (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
    }

    public static function url(): string
    {
        return (string)($_SERVER['REQUEST_URI'] ?? '/');
    }

    public static function wantsJson(): bool
    {
        $accept = self::header('Accept');
        return str_contains($accept, 'application/json') || self::header('X-Requested-With') === 'XMLHttpRequest';
    }

    public static function isAjax(): bool
    {
        return self::header('X-Requested-With') === 'XMLHttpRequest' || self::wantsJson();
    }
}
