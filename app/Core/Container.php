<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight service container. Keeps singletons explicit and testable.
 */
final class Container
{
    private static array $instances = [];
    private static array $factories = [];

    public static function boot(): void
    {
        self::singleton(Auth::class, static fn(): Auth => new Auth());
        self::singleton(Flash::class, static fn(): Flash => new Flash());
    }

    public static function singleton(string $id, callable $factory): void
    {
        self::$factories[$id] = $factory;
    }

    public static function get(string $id): mixed
    {
        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }
        if (!isset(self::$factories[$id])) {
            if (class_exists($id)) {
                self::$factories[$id] = static fn(): object => new $id();
            } else {
                throw new \RuntimeException('Service tidak ditemukan: ' . $id);
            }
        }
        return self::$instances[$id] = (self::$factories[$id])();
    }

    public static function reset(): void
    {
        self::$instances = [];
        Settings::flush();
        Context::flushBlog();
    }
}
