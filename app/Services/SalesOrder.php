<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Database;
use App\Core\ValidationException;

/**
 * Penyimpanan pesanan lisensi CMS (halaman penjualan).
 *
 * Tabel dibuat otomatis pada pemakaian pertama (CREATE TABLE IF NOT EXISTS)
 * sehingga tidak perlu menyentuh `database/schema.sql` atau migrasi lama.
 * Data ter-scope `blog_id` seperti tabel lain.
 */
final class SalesOrder
{
    private static bool $ready = false;

    public static function ensureTable(): void
    {
        if (self::$ready) {
            return;
        }
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS orders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                blog_id INT UNSIGNED NOT NULL DEFAULT 1,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(180) NOT NULL,
                whatsapp VARCHAR(40) NULL,
                package VARCHAR(80) NULL,
                note TEXT NULL,
                amount INT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT "pending",
                verified_by INT UNSIGNED NULL,
                paid_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY orders_blog_status (blog_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        self::$ready = true;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        self::ensureTable();
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('Nama wajib diisi (maksimal 120 karakter).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Alamat email tidak valid.');
        }
        $whatsapp = preg_replace('/[^0-9+]/', '', (string)($data['whatsapp'] ?? '')) ?? '';
        $status = ($data['status'] ?? '') === 'paid' ? 'paid' : 'pending';
        return Database::insert('orders', [
            'blog_id' => Context::blogId(),
            'name' => $name,
            'email' => $email,
            'whatsapp' => mb_substr($whatsapp, 0, 40) ?: null,
            'package' => mb_substr(trim((string)($data['package'] ?? '')), 0, 80) ?: null,
            'note' => mb_substr(trim((string)($data['note'] ?? '')), 0, 2000) ?: null,
            'amount' => max(0, (int)($data['amount'] ?? 0)),
            'status' => $status,
            'paid_at' => $status === 'paid' ? gmdate('Y-m-d H:i:s') : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent(int $limit = 20): array
    {
        self::ensureTable();
        return Database::fetchAll(
            'SELECT * FROM orders WHERE blog_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [Context::blogId()]
        );
    }

    public static function count(string $status = ''): int
    {
        self::ensureTable();
        $sql = 'SELECT COUNT(*) FROM orders WHERE blog_id = ?';
        $params = [Context::blogId()];
        if ($status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        return (int)Database::value($sql, $params);
    }
}
