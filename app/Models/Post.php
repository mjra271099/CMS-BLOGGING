<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Context;
use App\Core\Database;
use App\Core\Html;
use App\Core\Logger;
use App\Core\SecurityException;
use App\Core\Slug;
use App\Core\Str;
use App\Core\ValidationException;

/**
 * Post repository: content queries, CRUD, revisions and bulk operations.
 * All queries are blog scoped and use prepared statements.
 */
final class Post
{
    public const STATUSES = ['draft', 'published', 'trash'];
    public const FORMATS = ['standard', 'video', 'gallery', 'quote', 'link', 'audio'];

    public static function find(int $id, ?int $blogId = null): ?array
    {
        $blogId ??= Context::blogId();
        $post = Database::fetch(
            'SELECT p.*, u.username, u.display_name AS author_name, u.avatar AS author_avatar
             FROM posts p JOIN users u ON u.id = p.author_id
             WHERE p.id = ? AND p.blog_id = ?',
            [$id, $blogId]
        );
        if (!$post) {
            return null;
        }
        $post['id'] = (int)$post['id'];
        return $post;
    }

    public static function findBySlug(string $slug, ?int $blogId = null, bool $includeUnpublished = false): ?array
    {
        $blogId ??= Context::blogId();
        $sql = 'SELECT p.*, u.username, u.display_name AS author_name, u.avatar AS author_avatar
                FROM posts p JOIN users u ON u.id = p.author_id
                WHERE p.slug = ? AND p.blog_id = ? AND p.status <> "trash"';
        if (!$includeUnpublished) {
            $sql .= ' AND p.status = "published" AND p.published_at <= NOW()';
        }
        $post = Database::fetch($sql, [$slug, $blogId]);
        if ($post) {
            $post['id'] = (int)$post['id'];
        }
        return $post;
    }

    /**
     * Unified listing used by admin lists and front archives.
     */
    public static function query(array $filters = [], int $limit = 12, int $offset = 0): array
    {
        [$where, $params] = self::buildWhere($filters);
        $order = self::buildOrder($filters);
        $sql = 'SELECT p.*, u.username, u.display_name AS author_name, u.avatar AS author_avatar
                FROM posts p JOIN users u ON u.id = p.author_id WHERE ' . $where . ' ORDER BY ' . $order
                . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        return Database::fetchAll($sql, $params);
    }

    public static function count(array $filters = []): int
    {
        [$where, $params] = self::buildWhere($filters);
        return (int)Database::fetch(
            'SELECT COUNT(*) FROM posts p JOIN users u ON u.id = p.author_id WHERE ' . $where,
            $params
        )['COUNT(*)'];
    }

    public static function buildWhere(array $filters): array
    {
        $where = ['p.blog_id = ?'];
        $params = [Context::blogId()];
        $status = $filters['status'] ?? 'published';
        if ($status === 'all') {
            $where[] = 'p.status <> "trash"';
        } elseif ($status === 'trash') {
            $where[] = 'p.status = "trash"';
        } else {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if (in_array($status, ['published', 'all'], true) && empty($filters['include_future'])) {
            $where[] = '(p.status <> "published" OR p.published_at <= NOW())';
            if ($status === 'all') {
                $where[count($where) - 1] = '(p.status <> "published" OR p.published_at <= NOW())';
            }
        }
        if (!empty($filters['author_id'])) {
            $where[] = 'p.author_id = ?';
            $params[] = (int)$filters['author_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM post_categories pc WHERE pc.post_id = p.id AND pc.category_id = ?)';
            $params[] = (int)$filters['category_id'];
        }
        if (!empty($filters['tag_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id = p.id AND pt.tag_id = ?)';
            $params[] = (int)$filters['tag_id'];
        }
        if (!empty($filters['featured'])) {
            $where[] = 'p.featured = 1';
        }
        if (!empty($filters['sticky'])) {
            $where[] = 'p.sticky = 1';
        }
        if (!empty($filters['format'])) {
            $where[] = 'p.format = ?';
            $params[] = $filters['format'];
        }
        if (!empty($filters['month'])) {
            $where[] = 'DATE_FORMAT(p.published_at, "%Y-%m") = ?';
            $params[] = (string)$filters['month'];
        }
        if (!empty($filters['search'])) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$filters['search']) . '%';
            $where[] = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.body LIKE ?)';
            array_push($params, $term, $term, $term);
        }
        if (!empty($filters['ids'])) {
            $ids = array_map('intval', (array)$filters['ids']);
            $ids = $ids ?: [0];
            $where[] = 'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        return [implode(' AND ', $where), $params];
    }

    private static function buildOrder(array $filters): string
    {
        $sort = (string)($filters['sort'] ?? 'newest');
        return match ($sort) {
            'oldest' => 'p.published_at ASC, p.id ASC',
            'title' => 'p.title ASC',
            'popular' => 'p.views DESC, p.published_at DESC',
            'updated', 'recent' => 'p.updated_at DESC',
            'sticky' => 'p.sticky DESC, p.published_at DESC',
            default => 'p.published_at DESC, p.id DESC',
        };
    }

    /**
     * Related posts sharing categories, falling back to recent posts.
     */
    public static function related(int $postId, int $limit = 3): array
    {
        $posts = Database::fetchAll(
            "SELECT DISTINCT p.* FROM posts p
             JOIN post_categories pc ON pc.post_id = p.id
             WHERE p.blog_id = ? AND p.id <> ? AND p.status = 'published' AND p.published_at <= NOW()
               AND pc.category_id IN (SELECT category_id FROM post_categories WHERE post_id = ?)
             ORDER BY p.published_at DESC LIMIT " . max(1, $limit),
            [Context::blogId(), $postId, $postId]
        );
        if (!$posts) {
            $posts = self::query(['status' => 'published'], $limit);
            $posts = array_values(array_filter($posts, static fn(array $p): bool => (int)$p['id'] !== $postId));
        }
        return $posts;
    }

    /**
     * Persist a post (create or update) and its taxonomy links + revision.
     *
     * @param array $data   validated input
     * @param array $categories category names
     * @param array $tags     tag names
     */
    public static function save(array $data, array $categories = [], array $tags = []): int
    {
        $blogId = Context::blogId();
        $id = (int)($data['id'] ?? 0);
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || strlen($title) > 255) {
            throw new ValidationException('Judul wajib diisi dan maksimal 255 karakter.');
        }
        $rawSlug = trim((string)($data['slug'] ?? '')) ?: $title;
        $slug = Slug::unique('posts', Slug::make($rawSlug), 'blog_id', $blogId, $id);

        $status = in_array($data['status'] ?? '', self::STATUSES, true) ? (string)$data['status'] : 'draft';
        $format = in_array($data['format'] ?? '', self::FORMATS, true) ? (string)$data['format'] : 'standard';
        $publishedAt = self::resolvePublishedAt($data['published_at'] ?? null, $status, $id);

        $body = (string)($data['body'] ?? '');
        $excerpt = trim((string)($data['excerpt'] ?? ''));
        $thumbnail = trim((string)($data['thumbnail'] ?? ''));
        if ($thumbnail !== '' && !valid_asset_url($thumbnail)) {
            throw new ValidationException('Thumbnail harus berupa URL HTTP(S) atau path lokal.');
        }

        $visibility = in_array($data['visibility'] ?? '', ['public', 'private', 'password'], true) ? (string)$data['visibility'] : 'public';
        $password = (string)($data['access_password'] ?? '');
        if ($visibility === 'password' && $password !== '') {
            $password = password_hash($password, PASSWORD_DEFAULT);
        } elseif ($id > 0) {
            $existing = self::find($id);
            $password = $existing['access_password'] ?? null;
        } else {
            $password = null;
        }

        $payload = [
            'blog_id' => $blogId,
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'content_json' => $data['content_json'] ?? null,
            'excerpt' => $excerpt !== '' ? $excerpt : null,
            'thumbnail' => $thumbnail !== '' ? $thumbnail : null,
            'meta_title' => Str::limit((string)($data['meta_title'] ?? ''), 255) ?: null,
            'meta_description' => Str::limit((string)($data['meta_description'] ?? ''), 320) ?: null,
            'canonical_url' => Str::limit((string)($data['canonical_url'] ?? ''), 500) ?: null,
            'og_image' => Str::limit((string)($data['og_image'] ?? ''), 500) ?: null,
            'status' => $status,
            'format' => $format,
            'visibility' => $visibility,
            'access_password' => $password,
            'comment_status' => ($data['comment_status'] ?? 'open') === 'closed' ? 'closed' : 'open',
            'robots' => Str::limit((string)($data['robots'] ?? ''), 80) ?: null,
            'featured' => !empty($data['featured']) ? 1 : 0,
            'sticky' => !empty($data['sticky']) ? 1 : 0,
            'reading_time' => Str::readingTime($body),
            'published_at' => $publishedAt,
        ];
        if ($status === 'trash') {
            $payload['deleted_at'] = gmdate('Y-m-d H:i:s');
        }

        $savedId = Database::transaction(static function () use ($id, $payload, $categories, $tags, $blogId, $data): int {
            if ($id > 0) {
                $previous = self::find($id);
                if (!$previous) {
                    throw new ValidationException('Postingan tidak ditemukan.');
                }
                if ((int)$previous['blog_id'] !== $blogId) {
                    throw new SecurityException('Postingan milik blog lain.', 403);
                }
                self::snapshot($previous, 'sebelum penyimpanan');
                Database::update('posts', $payload, 'id = ?', [$id]);
                $postId = $id;
            } else {
                $payload['author_id'] = (int)($data['author_id'] ?? ($_SESSION['user_id'] ?? 1));
                $postId = Database::insert('posts', $payload);
            }
            Taxonomy::syncPost($postId, $categories, $tags);
            return $postId;
        });
        self::flushCaches();
        return $savedId;
    }

    private static function resolvePublishedAt(mixed $input, string $status, int $id): ?string
    {
        $existing = $id > 0 ? (string)(Database::value('SELECT published_at FROM posts WHERE id = ?', [$id]) ?: '') : '';
        $input = trim((string)$input);
        if ($input !== '') {
            $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d'];
            foreach ($formats as $format) {
                $date = \DateTimeImmutable::createFromFormat('!' . $format, $input, new \DateTimeZone('UTC'));
                $errors = \DateTimeImmutable::getLastErrors();
                if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    $input = $date->format('Y-m-d H:i:s');
                    break;
                }
            }
        }
        if ($status === 'published') {
            return $input !== '' ? $input : ($existing !== '' ? $existing : gmdate('Y-m-d H:i:s'));
        }
        return $input !== '' ? $input : ($existing !== '' ? $existing : null);
    }

    public static function snapshot(array $post, string $note = ''): void
    {
        try {
            Database::insert('revisions', [
                'blog_id' => (int)$post['blog_id'],
                'entity_type' => 'post',
                'entity_id' => (int)$post['id'],
                'user_id' => $_SESSION['user_id'] ?? null,
                'title' => (string)$post['title'],
                'body' => (string)$post['body'],
                'content_json' => $post['content_json'] ?? null,
                'note' => substr($note, 0, 255) ?: null,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('revision snapshot failed', ['post' => $post['id'] ?? 0, 'error' => $e->getMessage()]);
        }
    }

    public static function revisions(int $postId, int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT r.*, u.username FROM revisions r LEFT JOIN users u ON u.id = r.user_id
             WHERE r.blog_id = ? AND r.entity_type = "post" AND r.entity_id = ?
             ORDER BY r.id DESC LIMIT ' . max(1, $limit),
            [Context::blogId(), $postId]
        );
    }

    /**
     * Restore the title/body/content_json of one revision back onto its post.
     *
     * The post as it stands is snapshotted first, so restoring a revision is
     * itself undoable. Only content fields travel back — status, slug,
     * taxonomies and SEO meta stay as the editor last left them.
     */
    public static function restoreRevision(int $postId, int $revisionId): int
    {
        $post = self::find($postId);
        if (!$post) {
            throw new ValidationException('Postingan tidak ditemukan.');
        }
        $revision = self::findRevision($revisionId);
        if (!$revision || (int)$revision['entity_id'] !== $postId) {
            throw new ValidationException('Revisi tidak ditemukan.');
        }
        self::snapshot($post, 'sebelum memulihkan revisi #' . $revisionId);
        Database::update('posts', [
            'title' => (string)$revision['title'],
            'body' => (string)$revision['body'],
            'content_json' => $revision['content_json'] ?? null,
        ], 'id = ? AND blog_id = ?', [$postId, Context::blogId()]);
        self::flushCaches();
        return $revisionId;
    }

    /**
     * One revision of this blog, scoped so an id from another blog is invisible.
     *
     * @return array<string, mixed>|null
     */
    public static function findRevision(int $revisionId): ?array
    {
        if ($revisionId <= 0) {
            return null;
        }
        return Database::fetch(
            'SELECT * FROM revisions
             WHERE id = ? AND blog_id = ? AND entity_type = "post" LIMIT 1',
            [$revisionId, Context::blogId()]
        );
    }

    public static function trash(int $id): void
    {
        $post = self::find($id);
        if (!$post) {
            throw new ValidationException('Postingan tidak ditemukan.');
        }
        self::snapshot($post, 'sebelum dipindahkan ke trash');
        Database::update('posts', ['status' => 'trash', 'deleted_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$id]);
        self::flushCaches();
    }

    public static function restore(int $id): void
    {
        Database::update('posts', ['status' => 'draft', 'deleted_at' => null], 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
        self::flushCaches();
    }

    public static function purge(int $id): void
    {
        Database::transaction(static function () use ($id): void {
            Database::run('DELETE FROM revisions WHERE entity_type = "post" AND entity_id = ? AND blog_id = ?', [$id, Context::blogId()]);
            Database::delete('posts', 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
        });
        self::flushCaches();
    }

    public static function duplicate(int $id): int
    {
        $post = self::find($id);
        if (!$post) {
            throw new ValidationException('Postingan tidak ditemukan.');
        }
        unset($post['id'], $post['username'], $post['author_name'], $post['author_avatar']);
        $post['title'] = $post['title'] . ' (salinan)';
        $post['slug'] = Slug::unique('posts', Slug::make($post['slug'] . '-salinan'), 'blog_id', Context::blogId());
        $post['status'] = 'draft';
        $post['views'] = 0;
        $post['featured'] = 0;
        $post['sticky'] = 0;
        $post['access_password'] = null;
        $post['deleted_at'] = null;
        $categories = array_column(Taxonomy::postCategories($id), 'name');
        $tags = array_column(Taxonomy::postTags($id), 'name');
        return self::save($post, $categories, $tags);
    }

    /**
     * Bulk operations from the posts list: publish, draft, trash, restore, delete.
     */
    public static function bulk(string $operation, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            throw new ValidationException('Pilih minimal satu postingan.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [Context::blogId()]);
        switch ($operation) {
            case 'publish':
                Database::run("UPDATE posts SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id IN ({$placeholders}) AND blog_id = ?", $params);
                break;
            case 'draft':
                Database::run("UPDATE posts SET status = 'draft' WHERE id IN ({$placeholders}) AND blog_id = ?", $params);
                break;
            case 'trash':
                Database::run("UPDATE posts SET status = 'trash', deleted_at = NOW() WHERE id IN ({$placeholders}) AND blog_id = ?", $params);
                break;
            case 'restore':
                Database::run("UPDATE posts SET status = 'draft', deleted_at = NULL WHERE id IN ({$placeholders}) AND blog_id = ?", $params);
                break;
            case 'delete':
                Database::run("DELETE FROM posts WHERE id IN ({$placeholders}) AND blog_id = ? AND status = 'trash'", $params);
                break;
            case 'feature':
                Database::run("UPDATE posts SET featured = 1 WHERE id IN ({$placeholders}) AND blog_id = ?", $params);
                break;
            case 'unfeature':
                Database::run("UPDATE posts SET featured = 0 WHERE id IN ({$placeholders}) AND blog_id = ?", $params);
                break;
            default:
                throw new ValidationException('Operasi massal tidak dikenal.');
        }
        self::flushCaches();
        return count($ids);
    }

    public static function incrementViews(int $id): void
    {
        Database::run('UPDATE posts SET views = views + 1 WHERE id = ?', [$id]);
    }

    /**
     * Current post count grouped by status for the dashboard.
     */
    public static function statusCounts(): array
    {
        $rows = Database::fetchAll(
            'SELECT status, COUNT(*) AS total FROM posts WHERE blog_id = ? GROUP BY status',
            [Context::blogId()]
        );
        $counts = ['draft' => 0, 'published' => 0, 'trash' => 0, 'scheduled' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }
        $counts['scheduled'] = (int)Database::value(
            "SELECT COUNT(*) FROM posts WHERE blog_id = ? AND status = 'published' AND published_at > NOW()",
            [Context::blogId()]
        );
        return $counts;
    }

    public static function flushCaches(): void
    {
        \App\Core\Cache::flushGroup('posts');
        \App\Core\Cache::flushGroup('sitemap');
    }
}
