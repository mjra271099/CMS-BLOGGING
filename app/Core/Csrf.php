<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session bound CSRF tokens, per-form scopes supported.
 */
final class Csrf
{
    public static function token(string $scope = 'default'): string
    {
        if (empty($_SESSION['csrf'][$scope])) {
            $_SESSION['csrf'][$scope] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'][$scope];
    }

    public static function field(string $scope = 'default'): string
    {
        return '<input type="hidden" name="csrf" value="' . Helpers::e(self::token($scope)) . '">';
    }

    public static function verify(?string $token = null, string $scope = 'default'): void
    {
        $token ??= (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals(self::token($scope), $token)) {
            throw new SecurityException('Permintaan tidak valid. Muat ulang halaman dan coba lagi.', 403);
        }
    }

    public static function rotate(): void
    {
        unset($_SESSION['csrf']);
    }
}
