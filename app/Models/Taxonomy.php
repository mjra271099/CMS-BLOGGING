<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Context;
use App\Core\Database;
use App\Core\Slug;
use App\Core\ValidationException;

/**
 * Categories and tags (labels) with post relation synchronisation.
 */
final class Taxonomy
{
    public static function categories(int $limit = 200): array
    {
        return Database::fetchAll(
            "SELECT c.*, (SELECT COUNT(*) FROM post_categories pc JOIN posts p ON p.id = pc.post_id
                WHERE pc.category_id = c.id AND p.status = 'published' AND p.published_at <= NOW()) AS post_count
             FROM categories c WHERE c.blog_id = ? ORDER BY c.sort_order, c.name LIMIT " . max(1, $limit),
            [Context::blogId()]
        );
    }

    public static function tags(int $limit = 500): array
    {
        return Database::fetchAll(
            "SELECT t.*, (SELECT COUNT(*) FROM post_tags pt JOIN posts p ON p.id = pt.post_id
                WHERE pt.tag_id = t.id AND p.status = 'published') AS post_count
             FROM tags t WHERE t.blog_id = ? ORDER BY t.name LIMIT " . max(1, $limit),
            [Context::blogId()]
        );
    }

    public static function categoryBySlug(string $slug): ?array
    {
        return Database::fetch('SELECT * FROM categories WHERE slug = ? AND blog_id = ?', [$slug, Context::blogId()]);
    }

    public static function tagBySlug(string $slug): ?array
    {
        return Database::fetch('SELECT * FROM tags WHERE slug = ? AND blog_id = ?', [$slug, Context::blogId()]);
    }

    public static function createCategory(string $name, array $extra = []): int
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 190) {
            throw new ValidationException('Nama kategori wajib diisi (maks 190 karakter).');
        }
        $slug = Slug::unique('categories', Slug::make($extra['slug'] ?? $name), 'blog_id', Context::blogId());
        return Database::insert('categories', [
            'blog_id' => Context::blogId(),
            'name' => $name,
            'slug' => $slug,
            'description' => $extra['description'] ?? null,
            'image' => $extra['image'] ?? null,
            'color' => $extra['color'] ?? null,
            'parent_id' => (int)($extra['parent_id'] ?? 0) ?: null,
            'meta_title' => $extra['meta_title'] ?? null,
            'meta_description' => $extra['meta_description'] ?? null,
            'sort_order' => (int)($extra['sort_order'] ?? 0),
        ]);
    }

    public static function updateCategory(int $id, array $data): void
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Nama kategori wajib diisi.');
        }
        $slug = trim((string)($data['slug'] ?? '')) ?: Slug::make($name);
        $slug = Slug::unique('categories', $slug, 'blog_id', Context::blogId(), $id);
        Database::update('categories', [
            'name' => $name,
            'slug' => $slug,
            'description' => (string)($data['description'] ?? '') ?: null,
            'image' => (string)($data['image'] ?? '') ?: null,
            'color' => (string)($data['color'] ?? '') ?: null,
            'parent_id' => (int)($data['parent_id'] ?? 0) ?: null,
            'meta_title' => (string)($data['meta_title'] ?? '') ?: null,
            'meta_description' => (string)($data['meta_description'] ?? '') ?: null,
            'sort_order' => (int)($data['sort_order'] ?? 0),
        ], 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    public static function deleteCategory(int $id): void
    {
        Database::delete('categories', 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    public static function deleteTag(int $id): void
    {
        Database::delete('tags', 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    public static function postCategories(int $postId): array
    {
        return Database::fetchAll(
            'SELECT c.* FROM categories c JOIN post_categories pc ON pc.category_id = c.id
             WHERE pc.post_id = ? AND c.blog_id = ? ORDER BY c.name',
            [$postId, Context::blogId()]
        );
    }

    public static function postTags(int $postId): array
    {
        return Database::fetchAll(
            'SELECT t.* FROM tags t JOIN post_tags pt ON pt.tag_id = t.id
             WHERE pt.post_id = ? AND t.blog_id = ? ORDER BY t.name',
            [$postId, Context::blogId()]
        );
    }

    /**
     * Names may come from a comma separated string or an array.
     */
    public static function syncPost(int $postId, array|string $categories, array|string $tags): void
    {
        $categoryIds = self::ensureTerms('categories', 'post_categories', 'category_id', $categories);
        $tagIds = self::ensureTerms('tags', 'post_tags', 'tag_id', $tags);

        Database::delete('post_categories', 'post_id = ?', [$postId]);
        foreach ($categoryIds as $id) {
            Database::run('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)', [$postId, $id]);
        }
        Database::delete('post_tags', 'post_id = ?', [$postId]);
        foreach ($tagIds as $id) {
            Database::run('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $id]);
        }
    }

    private static function ensureTerms(string $table, string $pivot, string $foreignKey, array|string $names): array
    {
        if (is_string($names)) {
            $names = explode(',', $names);
        }
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '' || strlen($name) > 190) {
                continue;
            }
            $slug = Slug::make($name);
            $existing = Database::fetch(
                "SELECT id FROM {$table} WHERE blog_id = ? AND (slug = ? OR name = ?) LIMIT 1",
                [Context::blogId(), $slug, $name]
            );
            if ($existing) {
                $ids[] = (int)$existing['id'];
                continue;
            }
            $unique = Slug::unique($table, $slug, 'blog_id', Context::blogId());
            $ids[] = Database::insert($table, [
                'blog_id' => Context::blogId(),
                'name' => $name,
                'slug' => $unique,
            ]);
        }
        return array_values(array_unique($ids));
    }

    public static function labelIndex(): array
    {
        return self::categories();
    }
}
