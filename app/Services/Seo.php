<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Context;
use App\Core\Database;
use App\Core\Settings;

/**
 * SEO engine: meta resolution, sitemaps, schema.org, redirects, audit.
 */
final class Seo
{
    /**
     * Build the complete meta payload for a front page request.
     */
    public static function meta(array $page): array
    {
        $blog = Context::blog();
        $siteName = Settings::get('site_name', (string)($blog['name'] ?? 'Blog'));
        $siteDescription = Settings::get('seo_description', Settings::get('site_description', ''));
        $canonical = (string)($page['canonical'] ?? url());
        $image = (string)($page['image'] ?? Settings::get('social_image', ''));
        $type = (string)($page['type'] ?? 'website');
        $robots = (string)($page['robots'] ?? Settings::get('robots_policy', 'index,follow'));
        $title = (string)($page['title'] ?? $siteName);
        $title = self::title($title, $siteName);

        $meta = [
            'title' => $title,
            'description' => self::clamp(
                trim((string)($page['description'] ?? '')) !== '' ? (string)$page['description'] : $siteDescription,
                160
            ),
            'canonical' => $canonical,
            'image' => $image,
            'type' => $type,
            'robots' => $robots,
            'site_name' => $siteName,
            'locale' => \App\Core\Lang::ogLocale(),
            'twitter' => Settings::get('twitter_site', ''),
            'published' => (string)($page['published'] ?? ''),
            'modified' => (string)($page['modified'] ?? ''),
            'author' => (string)($page['author'] ?? ''),
            'article' => $page['article'] ?? null,
            'breadcrumbs' => (array)($page['breadcrumbs'] ?? []),
            // Extra structured data hints supplied by the controller (see
            // App\Services\StructuredData): author profile, listed posts,
            // post visibility, …
            'schema' => (array)($page['schema'] ?? []),
        ];
        return $meta;
    }

    public static function title(string $title, string $siteName): string
    {
        $title = trim($title);
        if ($title === '') {
            return $siteName;
        }
        $pattern = Settings::get('title_pattern', '');
        if ($pattern !== '' && !str_contains($title, $siteName)) {
            return strtr($pattern, ['%title%' => $title, '%site%' => $siteName]);
        }
        if (str_contains($title, $siteName)) {
            return $title;
        }
        $separator = Settings::get('title_separator', ' - ');
        return $title . $separator . $siteName;
    }

    public static function clamp(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * JSON-LD graph for a page. The node catalogue (all 25 Google Search
     * Gallery entries) lives in App\Services\StructuredData; this method stays
     * as the stable entry point for callers that already used it.
     */
    public static function structuredData(array $page): array
    {
        // Callers used to pass the flat meta payload; keep them working by
        // presenting it as the `meta` branch StructuredData reads from.
        if (!isset($page['meta']) && (isset($page['type']) || isset($page['title']))) {
            $page = ['meta' => $page] + $page;
        }
        return StructuredData::graph($page);
    }

    /**
     * Full sitemap index with sub sitemaps for posts, pages, categories.
     */
    public static function sitemap(): string
    {
        // TTL mengikuti pengaturan cache_ttl (default 900 untuk sitemap bila
        // admin memakai nilai bawaan 300 yang lebih pendek — sitemap boleh
        // lebih lama). Dibatasi agar tidak membebani server.
        $ttl = Settings::int('cache_ttl', 300);
        $ttl = max(60, min(86400, $ttl > 0 ? max($ttl, 900) : 900));
        return Cache::remember('sitemap.' . Context::blogId(), $ttl, static function (): string {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
            $xml .= '<url><loc>' . e(url()) . '</loc><changefreq>daily</changefreq><priority>1.0</priority></url>';
            $posts = Database::fetchAll(
                "SELECT slug, published_at, updated_at, thumbnail, title FROM posts
                 WHERE blog_id = ? AND status = 'published' AND published_at <= NOW()
                   AND (robots IS NULL OR robots NOT LIKE '%noindex%')
                 ORDER BY published_at DESC LIMIT 2000",
                [Context::blogId()]
            );
            foreach ($posts as $post) {
                $xml .= '<url><loc>' . e(post_url($post)) . '</loc>'
                    . '<lastmod>' . e(gmdate(DATE_W3C, strtotime((string)$post['updated_at'])) ?: '') . '</lastmod>'
                    . '<changefreq>weekly</changefreq><priority>0.8</priority></url>';
            }
            $pages = Database::fetchAll(
                "SELECT slug, updated_at FROM pages
                 WHERE blog_id = ? AND status = 'published' AND published_at <= NOW()
                   AND visibility = 'public'
                   AND (robots IS NULL OR robots NOT LIKE '%noindex%')",
                [Context::blogId()]
            );
            foreach ($pages as $page) {
                $xml .= '<url><loc>' . e(page_url($page)) . '</loc>'
                    . '<lastmod>' . e(gmdate(DATE_W3C, strtotime((string)$page['updated_at'])) ?: '') . '</lastmod>'
                    . '<changefreq>monthly</changefreq><priority>0.6</priority></url>';
            }
            foreach (Database::fetchAll('SELECT name, slug, created_at FROM categories WHERE blog_id = ?', [Context::blogId()]) as $category) {
                $xml .= '<url><loc>' . e(category_url($category)) . '</loc>'
                    . '<lastmod>' . e(gmdate(DATE_W3C, strtotime((string)$category['created_at'])) ?: '') . '</lastmod>'
                    . '<changefreq>weekly</changefreq><priority>0.5</priority></url>';
            }
            return $xml . '</urlset>';
        }, 'sitemap');
    }

    public static function robots(): string
    {
        $lines = ['User-agent: *'];
        if (Settings::get('maintenance_mode') === '1') {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Allow: /';
            $lines[] = 'Disallow: /admin/';
            $lines[] = 'Disallow: /install.php';
            $lines[] = 'Disallow: /storage/';
            $lines[] = 'Disallow: /uploads/*?preview=1';
        }
        $lines[] = '';
        if (Settings::get('sitemap_enabled', '1') === '1') {
            $lines[] = 'Sitemap: ' . url('sitemap.xml');
        }
        $custom = Settings::get('robots_txt');
        if ($custom !== '') {
            $lines[] = '';
            $lines[] = $custom;
        }
        return implode("\n", $lines) . "\n";
    }

    public static function feed(int $limit = 20): string
    {
        $limit = max(1, min(100, (int)Settings::get('feed_items', (string)$limit)));
        $posts = \App\Models\Post::query(['status' => 'published'], $limit);
        $siteName = Settings::get('site_name');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">';
        $xml .= '<channel><title>' . e($siteName) . '</title><link>' . e(url()) . '</link>'
            . '<description>' . e(Settings::get('site_description')) . '</description>'
            . '<language>' . e(Settings::get('language', 'id')) . '</language>'
            . '<atom:link href="' . e(url('feed.xml')) . '" rel="self" type="application/rss+xml"/>'
            . '<lastBuildDate>' . gmdate(DATE_RSS) . '</lastBuildDate>';
        foreach ($posts as $post) {
            $link = post_url($post);
            $xml .= '<item><title>' . e($post['title']) . '</title><link>' . e($link) . '</link>'
                . '<guid isPermaLink="true">' . e($link) . '</guid>'
                . '<pubDate>' . gmdate(DATE_RSS, strtotime((string)$post['published_at'])) . '</pubDate>'
                . '<author>' . e((string)($post['author_name'] ?? $post['author'] ?? '')) . '</author>'
                . '<description>' . e(excerpt($post, 300)) . '</description>'
                . '<content:encoded><![CDATA[' . $post['body'] . ']]></content:encoded></item>';
        }
        return $xml . '</channel></rss>';
    }

    /**
     * Resolve a redirect for the current path. Increments hit counter.
     */
    public static function redirectFor(string $path): ?array
    {
        $path = '/' . ltrim($path, '/');
        $redirect = Database::fetch(
            'SELECT * FROM redirects WHERE blog_id = ? AND enabled = 1 AND from_path = ? LIMIT 1',
            [Context::blogId(), $path]
        );
        if (!$redirect) {
            return null;
        }
        Database::run('UPDATE redirects SET hits = hits + 1 WHERE id = ?', [(int)$redirect['id']]);
        return $redirect;
    }

    public static function saveRedirect(array $data): int
    {
        $from = '/' . ltrim(trim((string)($data['from_path'] ?? '')), '/');
        $to = trim((string)($data['to_path'] ?? ''));
        if ($from === '/' || $to === '') {
            throw new \App\Core\ValidationException('Path asal dan tujuan wajib diisi.');
        }
        if (!valid_asset_url($to) && !str_starts_with($to, '/')) {
            throw new \App\Core\ValidationException('URL tujuan tidak valid.');
        }
        $status = in_array((int)($data['status_code'] ?? 301), [301, 302, 307, 308], true) ? (int)$data['status_code'] : 301;
        $existing = Database::fetch('SELECT id FROM redirects WHERE blog_id = ? AND from_path = ?', [Context::blogId(), $from]);
        if ($existing) {
            Database::update('redirects', ['to_path' => $to, 'status_code' => $status, 'enabled' => 1], 'id = ?', [(int)$existing['id']]);
            return (int)$existing['id'];
        }
        return Database::insert('redirects', [
            'blog_id' => Context::blogId(),
            'from_path' => $from,
            'to_path' => $to,
            'status_code' => $status,
            'enabled' => 1,
        ]);
    }

    public static function redirects(): array
    {
        return Database::fetchAll('SELECT * FROM redirects WHERE blog_id = ? ORDER BY id DESC LIMIT 500', [Context::blogId()]);
    }

    /**
     * SEO audit: actionable issues with severity and fix hints.
     */
    public static function audit(): array
    {
        $blogId = Context::blogId();
        $issues = [];
        $posts = Database::fetchAll(
            "SELECT id, title, slug, excerpt, meta_title, meta_description, thumbnail, body FROM posts
             WHERE blog_id = ? AND status = 'published'",
            [$blogId]
        );
        foreach ($posts as $post) {
            if (trim((string)$post['meta_description']) === '') {
                $issues[] = ['level' => 'warning', 'type' => 'meta_description', 'id' => (int)$post['id'], 'message' => 'Meta description kosong: ' . $post['title']];
            }
            if (mb_strlen((string)$post['meta_description']) > 160) {
                $issues[] = ['level' => 'info', 'type' => 'meta_long', 'id' => (int)$post['id'], 'message' => 'Meta description > 160 karakter: ' . $post['title']];
            }
            if ((string)$post['thumbnail'] === '' && !preg_match('/<img/i', (string)$post['body'])) {
                $issues[] = ['level' => 'info', 'type' => 'image', 'id' => (int)$post['id'], 'message' => 'Postingan tanpa gambar: ' . $post['title']];
            }
            if (mb_strlen((string)$post['title']) > 65) {
                $issues[] = ['level' => 'info', 'type' => 'title_long', 'id' => (int)$post['id'], 'message' => 'Judul > 65 karakter: ' . $post['title']];
            }
        }
        $duplicateSlugs = Database::fetchAll(
            'SELECT slug, COUNT(*) c FROM posts WHERE blog_id = ? GROUP BY slug HAVING c > 1',
            [$blogId]
        );
        foreach ($duplicateSlugs as $duplicate) {
            $issues[] = ['level' => 'error', 'type' => 'duplicate_slug', 'id' => 0, 'message' => 'Slug duplikat: ' . $duplicate['slug']];
        }
        if (Settings::get('sitemap_enabled', '1') !== '1') {
            $issues[] = ['level' => 'warning', 'type' => 'sitemap', 'id' => 0, 'message' => 'Sitemap dinonaktifkan.'];
        }
        if (Settings::get('google_site_verification') === '') {
            $issues[] = ['level' => 'info', 'type' => 'verification', 'id' => 0, 'message' => 'Google Search Console belum diverifikasi.'];
        }
        $score = 100;
        foreach ($issues as $issue) {
            $score -= match ($issue['level']) {
                'error' => 8,
                'warning' => 4,
                default => 1,
            };
        }
        return [
            'score' => max(0, $score),
            'issues' => $issues,
            'counts' => [
                'error' => count(array_filter($issues, static fn($i) => $i['level'] === 'error')),
                'warning' => count(array_filter($issues, static fn($i) => $i['level'] === 'warning')),
                'info' => count(array_filter($issues, static fn($i) => $i['level'] === 'info')),
            ],
            'checked' => count($posts),
        ];
    }
}
