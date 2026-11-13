<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(mixed $data, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        return (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function redirect(string $target, int $status = 302): void
    {
        if (!headers_sent()) {
            header('Location: ' . $target, true, $status);
        }
        exit;
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain; charset=utf-8'): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $type);
        }
        return $body;
    }

    public static function error(string $message, int $status = 400): string
    {
        return self::json(['ok' => false, 'error' => $message], $status);
    }

    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 0');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }
}
