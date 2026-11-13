<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * File-backed logger with automatic redaction of secrets.
 */
final class Logger
{
    private static ?string $file = null;
    private const SENSITIVE = ['password', 'pass', 'token', 'secret', 'authorization', 'api_key', 'csrf'];

    public static function boot(): void
    {
        $dir = getenv('CMS_LOG_DIR') ?: CMS_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        self::$file = $dir . '/cms.log';
        if (!is_file(self::$file)) {
            @touch(self::$file);
            @chmod(self::$file, 0640);
        }
    }

    public static function exception(Throwable $e): void
    {
        self::error(get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function audit(string $message, array $context = []): void
    {
        self::write('AUDIT', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$file === null) {
            self::boot();
        }
        $context = self::redact($context);
        $line = sprintf(
            "[%s] %s %s%s\n",
            gmdate('Y-m-d H:i:s'),
            $level,
            $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents((string)self::$file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            foreach (self::SENSITIVE as $needle) {
                if (stripos((string)$key, $needle) !== false) {
                    $context[$key] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }

    public static function tail(int $lines = 200): array
    {
        $file = self::$file ?: CMS_ROOT . '/storage/logs/cms.log';
        if (!is_file($file)) {
            return [];
        }
        $content = (string)file_get_contents($file);
        $split = preg_split('/\r?\n/', trim($content)) ?: [];
        return array_slice($split, max(0, count($split) - $lines));
    }
}
