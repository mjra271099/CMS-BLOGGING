<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Database;
use App\Core\ValidationException;

/**
 * Newsletter subscription handling.
 */
final class Newsletter
{
    public static function subscribe(string $email, string $name = ''): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Email tidak valid.');
        }
        $existing = Database::fetch(
            'SELECT id, status FROM newsletter_subscribers WHERE blog_id = ? AND email = ?',
            [Context::blogId(), $email]
        );
        if ($existing) {
            if ($existing['status'] === 'unsubscribed') {
                Database::update('newsletter_subscribers', ['status' => 'confirmed'], 'id = ?', [(int)$existing['id']]);
            }
            return ['status' => 'already', 'message' => 'Email sudah terdaftar.'];
        }
        Database::insert('newsletter_subscribers', [
            'blog_id' => Context::blogId(),
            'email' => $email,
            'name' => substr(trim($name), 0, 190),
            'status' => 'confirmed',
        ]);
        return ['status' => 'created', 'message' => 'Terima kasih telah berlangganan.'];
    }

    public static function unsubscribe(string $email): void
    {
        Database::update(
            'newsletter_subscribers',
            ['status' => 'unsubscribed'],
            'blog_id = ? AND email = ?',
            [Context::blogId(), strtolower(trim($email))]
        );
    }

    public static function subscribers(int $limit = 200): array
    {
        return Database::fetchAll(
            'SELECT * FROM newsletter_subscribers WHERE blog_id = ? ORDER BY created_at DESC LIMIT ' . max(1, $limit),
            [Context::blogId()]
        );
    }
}
