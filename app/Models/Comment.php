<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Context;
use App\Core\Database;
use App\Core\ValidationException;

final class Comment
{
    public static function forPost(int $postId, int $limit = 100): array
    {
        return Database::fetchAll(
            "SELECT c.* FROM comments c WHERE c.post_id = ? AND c.blog_id = ? AND c.status = 'approved'
             ORDER BY c.created_at ASC LIMIT " . max(1, $limit),
            [$postId, Context::blogId()]
        );
    }

    public static function create(array $data): int
    {
        $author = trim((string)($data['author'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $body = trim((string)($data['body'] ?? ''));
        if ($author === '' || strlen($author) > 120) {
            throw new ValidationException('Nama wajib diisi (maks 120 karakter).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Email tidak valid.');
        }
        if ($body === '' || strlen($body) > 8000) {
            throw new ValidationException('Komentar wajib diisi (maks 8.000 karakter).');
        }
        $status = (bool)\App\Core\Settings::get('comment_moderation', '1') ? 'pending' : 'approved';
        return Database::insert('comments', [
            'blog_id' => Context::blogId(),
            'post_id' => (int)($data['post_id'] ?? 0),
            'parent_id' => (int)($data['parent_id'] ?? 0) ?: null,
            'author' => $author,
            'email' => $email,
            'url' => filter_var((string)($data['url'] ?? ''), FILTER_VALIDATE_URL) ?: null,
            'body' => $body,
            'status' => $status,
            'ip_hash' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'approved_at' => $status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
        ]);
    }

    public static function moderate(int $id, string $status): void
    {
        if (!in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            throw new ValidationException('Status komentar tidak valid.');
        }
        Database::update('comments', [
            'status' => $status,
            'approved_at' => $status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
        ], 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    public static function delete(int $id): void
    {
        Database::delete('comments', 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    public static function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT c.*, p.title AS post_title, p.slug AS post_slug FROM comments c
                JOIN posts p ON p.id = c.post_id WHERE c.blog_id = ?';
        $params = [Context::blogId()];
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $sql .= ' AND c.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['post_id'])) {
            $sql .= ' AND c.post_id = ?';
            $params[] = (int)$filters['post_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (c.author LIKE ? OR c.email LIKE ? OR c.body LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }
        $sql .= ' ORDER BY c.created_at DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        return Database::fetchAll($sql, $params);
    }

    public static function counts(): array
    {
        $rows = Database::fetchAll(
            'SELECT status, COUNT(*) AS total FROM comments WHERE blog_id = ? GROUP BY status',
            [Context::blogId()]
        );
        $counts = ['pending' => 0, 'approved' => 0, 'spam' => 0, 'trash' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }
        return $counts;
    }
}
