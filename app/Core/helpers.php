<?php
declare(strict_types=1);

/**
 * Legacy-compatible global helpers. All helpers delegate to App\Core classes
 * so templates and older integrations keep working while logic stays central.
 *
 * Every function is guarded with function_exists() because legacy installs may
 * still load the historical functions.php during an upgrade.
 */

use App\Core\Auth;
use App\Core\Config;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Html;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\Slug;
use App\Core\Str;
use App\Core\Theme;
use App\Core\View;
use App\Core\Widget;

if (!function_exists('db')) {
    function db(): PDO
    {
        return Database::pdo();
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return Helpers::e($value);
    }
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return Helpers::e($value);
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return base_url() . ltrim($path, '/');
    }
}

if (!function_exists('base_url')) {
    function base_url(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $configured = Settings::get('site_url', '');
        if ($configured === '') {
            $configured = (string)Config::get('site_url', '');
        }
        if ($configured === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $configured = $scheme . '://' . $host . '/';
        }
        return $base = rtrim($configured, '/') . '/';
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        $cdn = Settings::get('cdn_url');
        if ($cdn !== '') {
            return rtrim($cdn, '/') . '/' . ltrim($path, '/');
        }
        // Root-relative so CSS/JS never break when the site URL changes scheme
        // or host (http -> https, new domain) or when the app lives in a
        // subfolder. Absolute URLs stay reserved for canonical/SEO output.
        $prefix = rtrim((string)(parse_url(base_url(), PHP_URL_PATH) ?? ''), '/');
        return $prefix . '/' . ltrim($path, '/');
    }
}

if (!function_exists('setting')) {
    function setting(string $key, string $default = ''): string
    {
        $value = Settings::get($key);
        return $value !== '' ? $value : $default;
    }
}

if (!function_exists('save_settings')) {
    function save_settings(array $values): void
    {
        Settings::setMany($values);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(string $scope = 'default'): string
    {
        return Csrf::token($scope);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(string $scope = 'default'): string
    {
        return Csrf::field($scope);
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(?string $token = null, string $scope = 'default'): void
    {
        Csrf::verify($token, $scope);
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): array
    {
        return Auth::require();
    }
}

if (!function_exists('require_permission')) {
    function require_permission(string $permission): array
    {
        return Auth::requirePermission($permission);
    }
}

if (!function_exists('log_activity')) {
    function log_activity(string $action, string $detail = ''): void
    {
        try {
            Database::insert('activity_log', [
                'blog_id' => Context::blogId(),
                'user_id' => $_SESSION['user_id'] ?? null,
                'action' => substr($action, 0, 190),
                'detail' => substr($detail, 0, 500),
                'ip_hash' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'cli')),
            ]);
        } catch (Throwable $error) {
            Logger::warning('activity_log failed', ['error' => $error->getMessage()]);
        }
    }
}

if (!function_exists('slugify')) {
    function slugify(string $text): string
    {
        return Slug::make($text);
    }
}

if (!function_exists('excerpt')) {
    function excerpt(array $post, int $length = 180): string
    {
        return Helpers::excerpt($post, $length);
    }
}

if (!function_exists('post_url')) {
    function post_url(array $post): string
    {
        return Helpers::postUrl($post);
    }
}

if (!function_exists('page_url')) {
    function page_url(array $page): string
    {
        return Helpers::pageUrl($page);
    }
}

if (!function_exists('category_url')) {
    function category_url(array $category): string
    {
        return Helpers::categoryUrl($category);
    }
}

if (!function_exists('author_url')) {
    function author_url(string $name): string
    {
        return Helpers::authorUrl($name);
    }
}

if (!function_exists('safe_href')) {
    function safe_href(string $href): string
    {
        return Helpers::safeHref($href);
    }
}

if (!function_exists('valid_asset_url')) {
    function valid_asset_url(string $value): bool
    {
        return Helpers::validAssetUrl($value);
    }
}

if (!function_exists('valid_site_url')) {
    function valid_site_url(string $value): bool
    {
        return Helpers::validSiteUrl($value);
    }
}

if (!function_exists('media_url')) {
    function media_url(?string $path): string
    {
        return Helpers::mediaUrl($path);
    }
}

if (!function_exists('post_image_url')) {
    function post_image_url(array $post): string
    {
        $image = (string)($post['thumbnail'] ?? '');
        if ($image === '' && preg_match('/<img[^>]+src=["\']([^"\']+)/i', (string)($post['body'] ?? ''), $match)) {
            $image = $match[1];
        }
        return Helpers::mediaUrl($image);
    }
}

if (!function_exists('sanitize_html')) {
    function sanitize_html(string $html): string
    {
        return Html::sanitize($html);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = []): string
    {
        return View::render($template, $data);
    }
}

if (!function_exists('render_widgets')) {
    function render_widgets(string $section, array $context = []): void
    {
        echo Widget::renderSection($section, $context);
    }
}

if (!function_exists('theme')) {
    function theme(): Theme
    {
        return Theme::instance();
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $view = 'dashboard', array $params = []): string
    {
        return url('admin/index.php?' . http_build_query(['view' => $view] + $params));
    }
}

if (!function_exists('reading_time')) {
    function reading_time(string $html): int
    {
        return Str::readingTime($html);
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $value, int $max): string
    {
        return Str::limit($value, $max);
    }
}
