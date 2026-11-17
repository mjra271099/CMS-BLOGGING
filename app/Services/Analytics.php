<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Core\Settings;
use App\Core\Str;

/**
 * Dashboard statistics and analytics aggregation.
 */
final class Analytics
{
    public static function overview(): array
    {
        $blogId = Context::blogId();
        $counts = \App\Models\Post::statusCounts();
        $comments = \App\Models\Comment::counts();
        $stats = \App\Models\Blog::stats($blogId);
        $traffic = self::traffic(14);
        return [
            'posts' => $counts,
            'comments' => $comments,
            'blog' => $stats,
            'media' => \App\Models\Media::stats(),
            'traffic' => $traffic,
            'views_total' => $stats['views'],
        ];
    }

    /**
     * 14-day published-post trend plus accumulated views per day.
     */
    public static function traffic(int $days = 14): array
    {
        $days = max(7, min(90, $days));
        $rows = Database::fetchAll(
            "SELECT DATE(published_at) AS day, COUNT(*) AS published, COALESCE(SUM(views),0) AS views
             FROM posts WHERE blog_id = ? AND status = 'published'
               AND published_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
             GROUP BY DATE(published_at) ORDER BY day",
            [Context::blogId()]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row['day']] = ['published' => (int)$row['published'], 'views' => (int)$row['views']];
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $series[] = [
                'day' => $day,
                'label' => date('d M', strtotime($day)),
                'published' => $map[$day]['published'] ?? 0,
                'views' => $map[$day]['views'] ?? 0,
            ];
        }
        return $series;
    }

    public static function topPosts(int $limit = 5): array
    {
        return Database::fetchAll(
            "SELECT id, title, slug, views, published_at FROM posts
             WHERE blog_id = ? AND status = 'published' ORDER BY views DESC LIMIT " . max(1, $limit),
            [Context::blogId()]
        );
    }

    public static function recentActivity(int $limit = 12): array
    {
        return Database::fetchAll(
            'SELECT a.*, u.username FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.blog_id = ? ORDER BY a.id DESC LIMIT ' . max(1, $limit),
            [Context::blogId()]
        );
    }

    public static function latestComments(int $limit = 5): array
    {
        return Database::fetchAll(
            'SELECT c.*, p.title AS post_title FROM comments c LEFT JOIN posts p ON p.id = c.post_id
             WHERE c.blog_id = ? ORDER BY c.created_at DESC LIMIT ' . max(1, $limit),
            [Context::blogId()]
        );
    }

    public static function popularCategories(int $limit = 6): array
    {
        return Database::fetchAll(
            "SELECT c.name, c.slug, COUNT(pc.post_id) AS total FROM categories c
             JOIN post_categories pc ON pc.category_id = c.id
             JOIN posts p ON p.id = pc.post_id AND p.status = 'published'
             WHERE c.blog_id = ? GROUP BY c.id ORDER BY total DESC LIMIT " . max(1, $limit),
            [Context::blogId()]
        );
    }
}
