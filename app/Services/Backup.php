<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Database;
use App\Core\ValidationException;

/**
 * Full-blog backup and restore (JSON). Restores never overwrite existing
 * posts: imported rows get collision-free slugs.
 */
final class Backup
{
    public static function create(?int $blogId = null): array
    {
        $blogId ??= Context::blogId();
        $blog = Database::fetch('SELECT * FROM blogs WHERE id = ?', [$blogId]);
        if (!$blog) {
            throw new ValidationException('Blog tidak ditemukan.');
        }
        $posts = Database::fetchAll('SELECT * FROM posts WHERE blog_id = ? ORDER BY id', [$blogId]);
        $pages = Database::fetchAll('SELECT * FROM pages WHERE blog_id = ? ORDER BY id', [$blogId]);
        return [
            'format' => 'cms-backup-v2',
            'generated_at' => gmdate(DATE_W3C),
            'version' => '2.0',
            'blog' => $blog,
            'settings' => Database::fetchAll('SELECT setting_key, setting_value FROM settings WHERE blog_id = ?', [$blogId]),
            'posts' => $posts,
            'pages' => $pages,
            'categories' => Database::fetchAll('SELECT * FROM categories WHERE blog_id = ?', [$blogId]),
            'tags' => Database::fetchAll('SELECT * FROM tags WHERE blog_id = ?', [$blogId]),
            'post_categories' => Database::fetchAll(
                'SELECT pc.* FROM post_categories pc JOIN posts p ON p.id = pc.post_id WHERE p.blog_id = ?',
                [$blogId]
            ),
            'post_tags' => Database::fetchAll(
                'SELECT pt.* FROM post_tags pt JOIN posts p ON p.id = pt.post_id WHERE p.blog_id = ?',
                [$blogId]
            ),
            'widgets' => Database::fetchAll('SELECT * FROM widgets WHERE blog_id = ?', [$blogId]),
            'menus' => Database::fetchAll('SELECT * FROM menus WHERE blog_id = ?', [$blogId]),
            'menu_items' => Database::fetchAll(
                'SELECT mi.* FROM menu_items mi JOIN menus m ON m.id = mi.menu_id WHERE m.blog_id = ?',
                [$blogId]
            ),
            'comments' => Database::fetchAll('SELECT * FROM comments WHERE blog_id = ?', [$blogId]),
            'media' => Database::fetchAll('SELECT * FROM media WHERE blog_id = ?', [$blogId]),
            'redirects' => Database::fetchAll('SELECT * FROM redirects WHERE blog_id = ?', [$blogId]),
            'theme_settings' => Database::fetchAll('SELECT * FROM theme_settings WHERE blog_id = ?', [$blogId]),
        ];
    }

    public static function writeFile(array $payload): string
    {
        $dir = CMS_ROOT . '/storage/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Folder backup tidak dapat dibuat.');
        }
        $file = $dir . '/blog-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
        if (file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException('File backup tidak dapat ditulis.');
        }
        return $file;
    }

    public static function listFiles(): array
    {
        $files = [];
        foreach (glob(CMS_ROOT . '/storage/backups/*.json') ?: [] as $file) {
            $files[] = ['name' => basename($file), 'size' => (int)filesize($file), 'date' => gmdate('Y-m-d H:i:s', (int)filemtime($file))];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']));
        return $files;
    }

    public static function restore(array $payload, bool $wipe = false): array
    {
        if (($payload['format'] ?? '') !== 'cms-backup-v2') {
            throw new ValidationException('Format backup tidak dikenali.');
        }
        $blogId = Context::blogId();
        return Database::transaction(static function () use ($payload, $wipe, $blogId): array {
            if ($wipe) {
                Database::run('DELETE FROM posts WHERE blog_id = ?', [$blogId]);
                Database::run('DELETE FROM pages WHERE blog_id = ?', [$blogId]);
            }
            foreach ((array)($payload['settings'] ?? []) as $row) {
                Database::run(
                    'INSERT INTO settings (blog_id, setting_key, setting_value) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    [$blogId, (string)$row['setting_key'], (string)$row['setting_value']]
                );
            }
            $postMap = [];
            $created = 0;
            foreach ((array)($payload['posts'] ?? []) as $post) {
                $slug = \App\Core\Slug::unique('posts', (string)$post['slug'], 'blog_id', $blogId);
                $id = Database::insert('posts', [
                    'blog_id' => $blogId,
                    'author_id' => (int)($post['author_id'] ?? 1),
                    'title' => (string)$post['title'],
                    'slug' => $slug,
                    'body' => (string)$post['body'],
                    'excerpt' => $post['excerpt'] ?? null,
                    'thumbnail' => $post['thumbnail'] ?? null,
                    'meta_title' => $post['meta_title'] ?? null,
                    'meta_description' => $post['meta_description'] ?? null,
                    'status' => in_array($post['status'] ?? '', ['draft', 'published', 'trash'], true) ? $post['status'] : 'draft',
                    'featured' => (int)($post['featured'] ?? 0),
                    'published_at' => $post['published_at'] ?? null,
                    'views' => (int)($post['views'] ?? 0),
                    'created_at' => $post['created_at'] ?? gmdate('Y-m-d H:i:s'),
                ]);
                $postMap[(int)$post['id']] = $id;
                $created++;
            }
            $categoryMap = [];
            foreach ((array)($payload['categories'] ?? []) as $category) {
                $categoryMap[(int)$category['id']] = \App\Models\Taxonomy::createCategory((string)$category['name'], $category);
            }
            $tagMap = [];
            foreach ((array)($payload['tags'] ?? []) as $tag) {
                $slug = \App\Core\Slug::unique('tags', (string)$tag['slug'], 'blog_id', $blogId);
                $tagMap[(int)$tag['id']] = Database::insert('tags', ['blog_id' => $blogId, 'name' => (string)$tag['name'], 'slug' => $slug]);
            }
            foreach ((array)($payload['post_categories'] ?? []) as $link) {
                if (isset($postMap[(int)$link['post_id']], $categoryMap[(int)$link['category_id']])) {
                    Database::run('INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)', [
                        $postMap[(int)$link['post_id']], $categoryMap[(int)$link['category_id']],
                    ]);
                }
            }
            foreach ((array)($payload['post_tags'] ?? []) as $link) {
                if (isset($postMap[(int)$link['post_id']], $tagMap[(int)$link['tag_id']])) {
                    Database::run('INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)', [
                        $postMap[(int)$link['post_id']], $tagMap[(int)$link['tag_id']],
                    ]);
                }
            }
            $pageCount = 0;
            foreach ((array)($payload['pages'] ?? []) as $page) {
                $slug = \App\Core\Slug::unique('pages', (string)$page['slug'], 'blog_id', $blogId);
                Database::insert('pages', [
                    'blog_id' => $blogId,
                    'author_id' => (int)($page['author_id'] ?? 1),
                    'title' => (string)$page['title'],
                    'slug' => $slug,
                    'body' => (string)$page['body'],
                    'status' => in_array($page['status'] ?? '', ['draft', 'published', 'trash'], true) ? $page['status'] : 'draft',
                    'published_at' => $page['published_at'] ?? null,
                    'created_at' => $page['created_at'] ?? gmdate('Y-m-d H:i:s'),
                ]);
                $pageCount++;
            }
            return ['posts' => $created, 'pages' => $pageCount, 'categories' => count($categoryMap), 'tags' => count($tagMap)];
        });
    }
}
