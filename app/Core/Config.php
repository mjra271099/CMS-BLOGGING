<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Immutable configuration access with environment-variable overrides.
 */
final class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(array $items): void
    {
        self::$items = $items;
        self::$loaded = true;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$items)) {
            $env = getenv('CMS_' . strtoupper($key));
            return $env !== false ? $env : $default;
        }
        return self::$items[$key];
    }

    public static function set(string $key, mixed $value): void
    {
        self::$items[$key] = $value;
    }

    public static function all(): array
    {
        return self::$items;
    }

    public static function db(): array
    {
        return [
            'host' => (string)self::get('db_host', 'localhost'),
            'name' => (string)self::get('db_name', ''),
            'user' => (string)self::get('db_user', ''),
            'pass' => (string)self::get('db_pass', ''),
            'charset' => (string)self::get('db_charset', 'utf8mb4'),
            'port' => (int)self::get('db_port', 3306),
            'socket' => self::get('db_socket', null),
        ];
    }
}
