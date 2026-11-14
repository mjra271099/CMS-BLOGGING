<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Context;
use App\Core\Database;
use App\Core\Slug;
use App\Core\ValidationException;

final class Blog
{
    public static function find(int $id): ?array
    {
        $blog = Database::fetch('SELECT * FROM blogs WHERE id = ?', [$id]);
        if ($blog) {
            $blog['id'] = (int)$blog['id'];
        }
        return $blog;
    }

    public static function bySlug(string $slug): ?array
    {
        return Database::fetch('SELECT * FROM blogs WHERE slug = ?', [$slug]);
    }

    public static function forUser(int $userId): array
    {
        return Database::fetchAll(
            'SELECT DISTINCT b.*, bu.role AS member_role, bu.is_owner
             FROM blogs b JOIN blog_users bu ON bu.blog_id = b.id
             WHERE bu.user_id = ? ORDER BY bu.is_owner DESC, b.is_primary DESC, b.name',
            [$userId]
        );
    }

    public static function create(array $data, int $ownerId): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 190) {
            throw new ValidationException('Nama blog wajib diisi (maks 190 karakter).');
        }
        $slug = Slug::unique('blogs', Slug::make(trim((string)($data['slug'] ?? '')) ?: $name), '', null, 0);
        $domain = trim((string)($data['domain'] ?? ''));
        return Database::transaction(static function () use ($name, $slug, $domain, $data, $ownerId): int {
            $id = Database::insert('blogs', [
                'owner_id' => $ownerId,
                'name' => $name,
                'slug' => $slug,
                'description' => trim((string)($data['description'] ?? '')) ?: null,
                'domain' => $domain,
                'theme' => (string)($data['theme'] ?? \App\Core\Theme::defaultSlug()),
                'language' => (string)($data['language'] ?? 'id'),
                'timezone' => (string)($data['timezone'] ?? 'Asia/Jakarta'),
                'is_primary' => 0,
                'settings' => '{}',
            ]);
            Database::run('INSERT INTO blog_users (blog_id, user_id, role, is_owner) VALUES (?, ?, "owner", 1)', [$id, $ownerId]);
            Database::insert('menus', ['blog_id' => $id, 'name' => 'Menu Utama', 'slug' => 'primary', 'location' => 'primary']);
            // Default site settings for the new blog.
            $defaults = \App\Core\Settings::defaults();
            $defaults['site_name'] = $name;
            $defaults['site_url'] = $domain !== '' ? 'https://' . $domain . '/' : '';
            $defaults['theme'] = (string)($data['theme'] ?? \App\Core\Theme::defaultSlug());
            $stmt = Database::pdo()->prepare('INSERT INTO settings (blog_id, setting_key, setting_value) VALUES (?, ?, ?)');
            foreach ($defaults as $key => $value) {
                $stmt->execute([$id, $key, (string)$value]);
            }
            // Seed a starting menu so the header is not empty.
            $menuId = (int)Database::value('SELECT id FROM menus WHERE blog_id = ? ORDER BY id LIMIT 1', [$id]);
            Database::insert('menu_items', ['menu_id' => $menuId, 'label' => 'Beranda', 'url' => '/', 'sort_order' => 0]);
            return $id;
        });
    }

    public static function update(int $id, array $data): void
    {
        $allowed = ['name', 'description', 'logo', 'favicon', 'language', 'timezone', 'custom_domain'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (isset($payload['name'])) {
            $payload['name'] = trim((string)$payload['name']);
            if ($payload['name'] === '') {
                throw new ValidationException('Nama blog tidak boleh kosong.');
            }
        }
        if (!empty($payload['custom_domain'])) {
            $payload['custom_domain'] = self::normalizeDomain((string)$payload['custom_domain']);
        } else {
            unset($payload['custom_domain']);
        }
        Database::update('blogs', $payload, 'id = ?', [$id]);
        \App\Core\Context::flushBlog();
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('~^https?://~', '', $domain) ?: $domain;
        $domain = rtrim($domain, '/');
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            throw new ValidationException('Domain tidak valid: ' . $domain);
        }
        return $domain;
    }

    public static function addDomain(int $blogId, string $domain, string $type = 'custom'): int
    {
        $domain = self::normalizeDomain($domain);
        $exists = Database::value('SELECT COUNT(*) FROM blog_domains WHERE domain = ?', [$domain]);
        if ($exists) {
            throw new ValidationException('Domain sudah terdaftar pada blog lain.');
        }
        return Database::insert('blog_domains', [
            'blog_id' => $blogId,
            'domain' => $domain,
            'type' => $type,
            'is_primary' => 0,
            'ssl_status' => 'unknown',
        ]);
    }

    public static function domains(int $blogId): array
    {
        return Database::fetchAll('SELECT * FROM blog_domains WHERE blog_id = ? ORDER BY id', [$blogId]);
    }

    public static function removeDomain(int $id, int $blogId): void
    {
        Database::delete('blog_domains', 'id = ? AND blog_id = ?', [$id, $blogId]);
    }

    /**
     * Verify a domain by DNS resolution. Returns the resolved addresses.
     */
    public static function verifyDomain(int $id, int $blogId): array
    {
        $domain = (string)Database::value('SELECT domain FROM blog_domains WHERE id = ? AND blog_id = ?', [$id, $blogId]);
        if ($domain === '') {
            throw new ValidationException('Domain tidak ditemukan.');
        }
        $records = @dns_get_record($domain, DNS_A + DNS_CNAME);
        $ok = is_array($records) && $records !== [];
        Database::update('blog_domains', [
            'verified_at' => $ok ? gmdate('Y-m-d H:i:s') : null,
            'ssl_status' => $ok ? 'pending' : 'error',
        ], 'id = ?', [$id]);
        return ['verified' => $ok, 'records' => $records ?: []];
    }

    public static function stats(int $blogId): array
    {
        return [
            'posts' => (int)Database::value('SELECT COUNT(*) FROM posts WHERE blog_id = ?', [$blogId]),
            'published' => (int)Database::value('SELECT COUNT(*) FROM posts WHERE blog_id = ? AND status = "published"', [$blogId]),
            'pages' => (int)Database::value('SELECT COUNT(*) FROM pages WHERE blog_id = ?', [$blogId]),
            'comments' => (int)Database::value('SELECT COUNT(*) FROM comments WHERE blog_id = ?', [$blogId]),
            'media' => (int)Database::value('SELECT COUNT(*) FROM media WHERE blog_id = ?', [$blogId]),
            'views' => (int)Database::value('SELECT COALESCE(SUM(views),0) FROM posts WHERE blog_id = ?', [$blogId]),
            'users' => (int)Database::value('SELECT COUNT(*) FROM blog_users WHERE blog_id = ?', [$blogId]),
            'subscribers' => (int)Database::value('SELECT COUNT(*) FROM newsletter_subscribers WHERE blog_id = ? AND status = "confirmed"', [$blogId]),
        ];
    }
}
