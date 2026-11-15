<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Context;
use App\Core\Database;
use App\Core\Hash;
use App\Core\Slug;
use App\Core\ValidationException;

final class Page
{
    public static function find(int $id, ?int $blogId = null): ?array
    {
        $blogId ??= Context::blogId();
        $page = Database::fetch('SELECT * FROM pages WHERE id = ? AND blog_id = ?', [$id, $blogId]);
        if ($page) {
            $page['id'] = (int)$page['id'];
        }
        return $page;
    }

    public static function findBySlug(string $slug, bool $includeUnpublished = false): ?array
    {
        $sql = 'SELECT * FROM pages WHERE slug = ? AND blog_id = ? AND status <> "trash"';
        if (!$includeUnpublished) {
            $sql .= ' AND status = "published" AND published_at <= NOW()';
        }
        return Database::fetch($sql, [$slug, Context::blogId()]);
    }

    public static function all(array $filters = []): array
    {
        $status = $filters['status'] ?? 'all';
        $sql = 'SELECT p.*, u.username, u.display_name AS author_name FROM pages p
                LEFT JOIN users u ON u.id = p.author_id WHERE p.blog_id = ?';
        $params = [Context::blogId()];
        if ($status !== 'all') {
            $sql .= ' AND p.status = ?';
            $params[] = $status;
        } else {
            $sql .= ' AND p.status <> "trash"';
        }
        return Database::fetchAll($sql . ' ORDER BY p.sort_order, p.title', $params);
    }

    public static function save(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || strlen($title) > 255) {
            throw new ValidationException('Judul halaman wajib diisi dan maksimal 255 karakter.');
        }
        $slug = Slug::unique('pages', Slug::make(trim((string)($data['slug'] ?? '')) ?: $title), 'blog_id', Context::blogId(), $id);
        $status = in_array($data['status'] ?? '', Post::STATUSES, true) ? (string)$data['status'] : 'draft';
        $publishedAt = self::publishedAt($data['published_at'] ?? null, $status, $id);
        $customScript = (string)($data['custom_script'] ?? '');
        if ($customScript !== '' && !\App\Core\Auth::isAdmin()) {
            $customScript = '';
        }
        $payload = [
            'blog_id' => Context::blogId(),
            'title' => $title,
            'slug' => $slug,
            'body' => (string)($data['body'] ?? ''),
            'content_json' => $data['content_json'] ?? null,
            'excerpt' => trim((string)($data['excerpt'] ?? '')) ?: null,
            'thumbnail' => trim((string)($data['thumbnail'] ?? '')) ?: null,
            'template' => Slug::make((string)($data['template'] ?? 'default'), 'default'),
            'status' => $status,
            'visibility' => in_array($data['visibility'] ?? '', ['public', 'private', 'password'], true) ? (string)$data['visibility'] : 'public',
            'parent_id' => (int)($data['parent_id'] ?? 0) ?: null,
            'meta_title' => trim((string)($data['meta_title'] ?? '')) ?: null,
            'meta_description' => trim((string)($data['meta_description'] ?? '')) ?: null,
            'canonical_url' => trim((string)($data['canonical_url'] ?? '')) ?: null,
            'og_image' => trim((string)($data['og_image'] ?? '')) ?: null,
            'robots' => trim((string)($data['robots'] ?? '')) ?: null,
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'custom_css' => (string)($data['custom_css'] ?? '') ?: null,
            'custom_script' => $customScript ?: null,
            'published_at' => $publishedAt,
        ];
        return Database::transaction(static function () use ($id, $payload, $data): int {
            if ($id > 0) {
                $previous = self::find($id);
                if (!$previous) {
                    throw new ValidationException('Halaman tidak ditemukan.');
                }
                Database::insert('revisions', [
                    'blog_id' => Context::blogId(),
                    'entity_type' => 'page',
                    'entity_id' => $id,
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'title' => (string)$previous['title'],
                    'body' => (string)$previous['body'],
                    'content_json' => $previous['content_json'] ?? null,
                    'note' => 'sebelum penyimpanan',
                ]);
                Database::update('pages', $payload, 'id = ?', [$id]);
                return $id;
            }
            $payload['author_id'] = (int)($data['author_id'] ?? ($_SESSION['user_id'] ?? 1));
            return Database::insert('pages', $payload);
        });
    }

    private static function publishedAt(mixed $input, string $status, int $id): ?string
    {
        $input = trim((string)$input);
        if ($input !== '') {
            foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
                $date = \DateTimeImmutable::createFromFormat('!' . $format, $input, new \DateTimeZone('UTC'));
                $errors = \DateTimeImmutable::getLastErrors();
                if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    $input = $date->format('Y-m-d H:i:s');
                    break;
                }
            }
        }
        if ($status === 'published') {
            return $input !== '' ? $input : (string)(Database::value('SELECT published_at FROM pages WHERE id = ?', [$id]) ?: gmdate('Y-m-d H:i:s'));
        }
        return $input !== '' ? $input : null;
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM revisions WHERE entity_type = "page" AND entity_id = ? AND blog_id = ?', [$id, Context::blogId()]);
        Database::delete('pages', 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    public static function revisions(int $pageId): array
    {
        return Database::fetchAll(
            'SELECT r.*, u.username FROM revisions r LEFT JOIN users u ON u.id = r.user_id
             WHERE r.blog_id = ? AND r.entity_type = "page" AND r.entity_id = ? ORDER BY r.id DESC LIMIT 20',
            [Context::blogId(), $pageId]
        );
    }

    /**
     * Build a nested page tree for navigation widgets.
     */
    public static function tree(): array
    {
        $pages = self::all(['status' => 'published']);
        $build = static function (array $items, int $parent = 0) use (&$build): array {
            $branch = [];
            foreach ($items as $item) {
                if ((int)($item['parent_id'] ?? 0) === $parent) {
                    $item['children'] = $build($items, (int)$item['id']);
                    $branch[] = $item;
                }
            }
            return $branch;
        };
        return $build($pages);
    }

    public static function templates(): array
    {
        return [
            'default' => 'Default',
            'full-width' => 'Full Width',
            'sidebar-left' => 'Sidebar Kiri',
            'landing' => 'Landing Page',
            'builder' => 'Page Builder',
            'qr-generator' => 'QR Code Generator',
            'contact' => 'Kontak',
            'documentation' => 'Dokumentasi',
        ];
    }
}
