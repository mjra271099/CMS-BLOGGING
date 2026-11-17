<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authentication, authorization and role/permission checks.
 */
final class Auth
{
    public const ROLES = ['admin', 'editor', 'author', 'contributor'];

    /**
     * Hash bcrypt valid untuk username yang tidak ada. password_verify()
     * terhadap hash ini tetap menghabiskan waktu yang sama dengan verifikasi
     * sungguhan, sehingga kegagalan login tidak membocorkan keberadaan akun.
     */
    private const DUMMY_HASH = '$2y$10$5Y5p0pMgOlhBY2vNRUnlFuKS8jN4oK5E4Z2sDz4HfHkVbqUcA/vz2';

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        if (PHP_SAPI === 'cli' || empty($_SESSION['user_id'])) {
            return self::$user = null;
        }
        if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 7200) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = time();
        $user = Database::fetch(
            'SELECT id, username, email, display_name, role, avatar, bio, status, created_at FROM users WHERE id = ?',
            [(int)$_SESSION['user_id']]
        );
        if (!$user || ($user['status'] ?? 'active') !== 'active') {
            return self::$user = null;
        }
        $user['id'] = (int)$user['id'];
        $user['blog_id'] = self::blogForUser($user['id']);
        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function role(): string
    {
        $user = self::user();
        if (!$user) {
            return 'guest';
        }
        $membership = Database::fetch(
            'SELECT role FROM blog_users WHERE user_id = ? AND blog_id = ?',
            [(int)$user['id'], Context::blogId()]
        );
        return (string)($membership['role'] ?? $user['role'] ?? 'author');
    }

    public static function isAdmin(): bool
    {
        return in_array(self::role(), ['admin', 'owner'], true);
    }

    public static function can(string $permission): bool
    {
        $role = self::role();
        $matrix = [
            'admin' => ['*'],
            'editor' => [
                'post.create', 'post.edit', 'post.delete', 'post.publish', 'page.create', 'page.edit',
                'page.delete', 'page.publish', 'comment.moderate', 'comment.delete', 'media.upload',
                'media.delete', 'taxonomy.manage', 'widget.manage', 'menu.manage', 'theme.activate',
                'theme.customize', 'layout.manage', 'redirect.manage', 'seo.manage', 'user.manage',
                'blog.manage', 'settings.manage', 'builder.manage', 'import.export',
            ],
            'author' => [
                'post.create', 'post.edit.own', 'post.delete.own', 'media.upload', 'media.delete',
                'page.create', 'page.edit.own', 'builder.manage.own', 'taxonomy.manage',
            ],
            'contributor' => ['post.create', 'post.edit.own', 'media.upload', 'builder.manage.own'],
        ];
        $allowed = $matrix[$role] ?? [];
        if ($role === 'owner') {
            $allowed = $matrix['admin'];
        }
        return in_array('*', $allowed, true) || in_array($permission, $allowed, true);
    }

    public static function require(): array
    {
        $user = self::user();
        if (!$user) {
            $target = $_SERVER['REQUEST_URI'] ?? url('admin/');
            $_SESSION['redirect_after_login'] = $target;
            if (PHP_SAPI !== 'cli') {
                header('Location: ' . url('admin/index.php?view=login'));
                exit;
            }
            throw new SecurityException('Login diperlukan.', 401);
        }
        return $user;
    }

    public static function requirePermission(string $permission): array
    {
        $user = self::require();
        if (!self::can($permission)) {
            throw new SecurityException('Anda tidak memiliki izin untuk tindakan ini.', 403);
        }
        return $user;
    }

    public static function attempt(string $login, string $password): bool
    {
        $ipHash = self::ipHash();
        $attempt = Database::fetch('SELECT attempts, locked_until FROM login_attempts WHERE ip_hash = ?', [$ipHash]);
        if ($attempt && !empty($attempt['locked_until']) && strtotime((string)$attempt['locked_until']) > time()) {
            throw new SecurityException('Login sementara dikunci setelah terlalu banyak percobaan. Coba lagi beberapa menit.', 429);
        }
        if ($attempt && !empty($attempt['locked_until'])) {
            Database::delete('login_attempts', 'ip_hash = ?', [$ipHash]);
        }
        $account = Database::fetch(
            'SELECT id, password_hash, status FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$login, $login]
        );
        // Selalu jalankan password_verify, termasuk saat akun tidak ada, agar
        // waktu respons tidak membocorkan apakah username/email terdaftar
        // (user enumeration lewat timing).
        $hash = $account ? (string)$account['password_hash'] : self::DUMMY_HASH;
        $verified = password_verify($password, $hash) && $account !== null;
        if ($verified) {
            if (($account['status'] ?? 'active') !== 'active') {
                throw new SecurityException('Akun tidak aktif.', 403);
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['user_id'] = (int)$account['id'];
            $_SESSION['last_activity'] = time();
            Database::delete('login_attempts', 'ip_hash = ?', [$ipHash]);
            Database::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [(int)$account['id']]);
            self::$resolved = false;
            self::$user = null;
            log_activity('auth.login', 'Login berhasil');
            return true;
        }
        $count = $attempt ? (int)$attempt['attempts'] + 1 : 1;
        $lockedUntil = $count >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null;
        Database::run(
            'INSERT INTO login_attempts (ip_hash, attempts, locked_until) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until)',
            [$ipHash, $count, $lockedUntil]
        );
        Logger::audit('login failed', ['ip' => $ipHash, 'login' => $login]);
        return false;
    }

    public static function loginAs(int $userId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['last_activity'] = time();
        self::$resolved = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
        }
        self::$resolved = true;
        self::$user = null;
    }

    public static function ipHash(): string
    {
        return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    }

    /**
     * Blog the user is currently editing. Owners keep their primary blog,
     * other users can switch via session.
     */
    public static function blogForUser(int $userId): int
    {
        $blogs = Database::column('SELECT blog_id FROM blog_users WHERE user_id = ? ORDER BY is_owner DESC, blog_id', [$userId]);
        $owned = Database::value('SELECT id FROM blogs WHERE owner_id = ? ORDER BY is_primary DESC, id LIMIT 1', [$userId]);
        if ($owned) {
            $blogs[] = (int)$owned;
        }
        $blogs = array_values(array_unique(array_map('intval', $blogs)));
        $requested = (int)($_SESSION['active_blog_id'] ?? 0);
        if ($requested > 0 && in_array($requested, $blogs, true)) {
            return $requested;
        }
        return $blogs[0] ?? 1;
    }

    public static function switchBlog(int $blogId): bool
    {
        $user = self::require();
        $member = Database::value(
            'SELECT COUNT(*) FROM blog_users WHERE user_id = ? AND blog_id = ?',
            [(int)$user['id'], $blogId]
        );
        if (!$member && (int)Database::value('SELECT owner_id FROM blogs WHERE id = ?', [$blogId]) !== (int)$user['id']) {
            return false;
        }
        $_SESSION['active_blog_id'] = $blogId;
        Context::forceBlog($blogId);
        self::$user['blog_id'] = $blogId;
        return true;
    }
}
