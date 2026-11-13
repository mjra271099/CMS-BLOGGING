<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('CMS belum diinstal. Buka /install.php terlebih dahulu.');
    }
    header('Location: install.php');
    exit;
}
$config = require $configFile;

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_exception_handler(static function (Throwable $exception): void {
    error_log('CMS runtime error: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Kesalahan server</title><body><h1>Kesalahan server</h1><p>Permintaan tidak dapat diproses. Periksa konfigurasi database dan log PHP hosting.</p></body></html>';
});

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo;
    global $config;
    if (!$pdo instanceof PDO) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['db_host'],
            $config['db_name'],
            $config['db_charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_href(string $href): string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '//')) {
        return '#';
    }
    $scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
    return $scheme === '' || in_array($scheme, ['http', 'https', 'mailto'], true) ? $href : '#';
}

function setting(string $key, string $default = ''): string
{
    static $all;
    if ($all === null) {
        $all = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return (string)($all[$key] ?? $default);
}

function save_settings(array $values): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($values as $key => $value) {
        $stmt->execute([(string)$key, (string)$value]);
    }
}

function base_url(): string
{
    return rtrim(setting('site_url', ''), '/') . '/';
}

function valid_site_url(string $value): bool
{
    $parts = parse_url($value);
    return filter_var($value, FILTER_VALIDATE_URL) !== false
        && is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['query'])
        && !isset($parts['fragment']);
}

function valid_asset_url(string $value): bool
{
    if ($value === '') {
        return true;
    }
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
        return true;
    }
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false;
}

function url(string $path = ''): string
{
    return base_url() . ltrim($path, '/');
}

function post_url(array $post): string
{
    return url(date('Y/m/', strtotime((string)($post['published_at'] ?: $post['created_at']))) . rawurlencode($post['slug']) . '.html');
}

function page_url(array $page): string
{
    return url('p/' . rawurlencode($page['slug']) . '.html');
}

function category_url(array $category): string
{
    return url('search/label/' . rawurlencode($category['slug']));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman dan coba lagi.');
    }
}

function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'artikel';
}

function excerpt(array $post, int $length = 180): string
{
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)($post['excerpt'] ?: $post['body']))) ?? '');
    if (function_exists('mb_substr')) {
        return mb_strlen($plain) > $length ? mb_substr($plain, 0, $length) . '…' : $plain;
    }
    return strlen($plain) > $length ? substr($plain, 0, $length) . '...' : $plain;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 1800) {
        unset($_SESSION['user_id'], $_SESSION['last_activity']);
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['last_activity'] = time();
    $stmt = db()->prepare('SELECT id, username, email FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_admin(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: ' . url('admin/index.php'));
        exit;
    }
    return $user;
}

function log_activity(string $action, string $detail = ''): void
{
    $stmt = db()->prepare('INSERT INTO activity_log (user_id, action, detail) VALUES (?, ?, ?)');
    $stmt->execute([$_SESSION['user_id'] ?? null, $action, $detail]);
}

function published_posts(int $limit, int $offset = 0, ?string $category = null): array
{
    if ($category !== null) {
        $stmt = db()->prepare(
            'SELECT p.*, u.username AS author FROM posts p
             JOIN users u ON u.id = p.author_id
             JOIN post_categories pc ON pc.post_id = p.id
             JOIN categories c ON c.id = pc.category_id
             WHERE p.status = "published" AND p.published_at <= NOW() AND c.slug = ?
             ORDER BY p.published_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $category);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    } else {
        $stmt = db()->prepare(
            'SELECT p.*, u.username AS author FROM posts p JOIN users u ON u.id = p.author_id
             WHERE p.status = "published" AND p.published_at <= NOW()
             ORDER BY p.published_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function post_categories(int $postId): array
{
    $stmt = db()->prepare(
        'SELECT c.* FROM categories c JOIN post_categories pc ON pc.category_id = c.id
         WHERE pc.post_id = ? ORDER BY c.name'
    );
    $stmt->execute([$postId]);
    return $stmt->fetchAll();
}

function post_image_url(array $post): string
{
    $image = (string)($post['thumbnail'] ?? '');
    if ($image === '' && preg_match('/<img[^>]+src=["\']([^"\']+)/i', (string)$post['body'], $match)) {
        $image = $match[1];
    }
    return $image;
}

function widget_is_enabled(string $key): bool
{
    $stmt = db()->prepare('SELECT enabled FROM widgets WHERE widget_key=?');
    $stmt->execute([$key]);
    return (bool)$stmt->fetchColumn();
}

function header_widget_is_enabled(): bool
{
    return section_widget_is_enabled('header', 'Header');
}

function section_widget_is_enabled(string $section, string $type): bool
{
    $stmt = db()->prepare('SELECT 1 FROM widgets WHERE section_id=? AND type=? AND enabled=1 LIMIT 1');
    $stmt->execute([$section, $type]);
    return (bool)$stmt->fetchColumn();
}

function render_widgets(string $section): void
{
    $stmt = db()->prepare('SELECT * FROM widgets WHERE section_id = ? AND enabled = 1 ORDER BY sort_order, id');
    $stmt->execute([$section]);
    foreach ($stmt->fetchAll() as $widget) {
        $type = $widget['type'];
        $title = (string)$widget['title'];
        $content = (string)$widget['content'];
        if (in_array($type, ['HTML', 'Text'], true)) {
            if ($content === '') {
                continue;
            }
            if ($widget['widget_key'] === 'Text2' && $section === 'footer-links') {
                echo '<div class="attribution"><div class="widget-content">' . $content . '</div></div>';
                continue;
            }
            echo '<div class="widget ' . ($type === 'Text' ? 'Text' : 'HTML') . '">';
            if ($title !== '') {
                echo '<h2 class="title">' . e($title) . '</h2>';
            }
            echo '<div class="widget-content">' . $content . '</div></div>';
        } elseif ($type === 'PopularPosts') {
            $config = json_decode((string)($widget['config_json'] ?? ''), true) ?: [];
            $limit = max(1, min(20, (int)($config['numItemsToShow'] ?? 6)));
            $showThumbnails = ($config['showThumbnails'] ?? 'true') === 'true';
            $showSnippets = ($config['showSnippets'] ?? 'false') === 'true';
            $posts = db()->query(
                'SELECT p.*, u.username AS author FROM posts p JOIN users u ON u.id=p.author_id
                 WHERE p.status="published" AND p.published_at<=NOW()
                 ORDER BY p.views DESC, p.published_at DESC LIMIT ' . $limit
            )->fetchAll();
            if (!$posts) {
                continue;
            }
            echo '<div class="widget PopularPosts"><h2>' . e($title ?: 'Popular Posts') . '</h2><div class="widget-content popular-posts">';
            if ($showThumbnails) {
                echo '<div class="popular-carousel">';
            } else {
                echo '<ul class="popular-post-list">';
            }
            foreach ($posts as $post) {
                $image = post_image_url($post);
                if ($showThumbnails) {
                    echo '<a href="' . e(post_url($post)) . '" title="' . e($post['title']) . '">';
                    if ($image !== '') {
                        echo '<img src="' . e($image) . '" alt="' . e($post['title']) . '" loading="lazy">';
                    } else {
                        echo '<span class="popular-fallback"></span>';
                    }
                    echo '<div class="popular-title">' . e($post['title']);
                    if ($showSnippets) {
                        echo '<p>' . e(excerpt($post, 100)) . '</p>';
                    }
                    echo '</div></a>';
                } else {
                    echo '<li><a href="' . e(post_url($post)) . '">' . e($post['title']) . '</a>';
                    if ($showSnippets) {
                        echo '<p>' . e(excerpt($post, 100)) . '</p>';
                    }
                    echo '</li>';
                }
            }
            echo $showThumbnails ? '</div></div></div>' : '</ul></div></div>';
        } elseif ($type === 'Label') {
            $categories = db()->query(
                'SELECT c.id, c.name, c.slug FROM categories c JOIN post_categories pc ON pc.category_id=c.id
                 JOIN posts p ON p.id=pc.post_id WHERE p.status="published" AND p.published_at<=NOW()
                 GROUP BY c.id ORDER BY c.name'
            )->fetchAll();
            if (!$categories) {
                continue;
            }
            echo '<div class="widget Label"><div class="widget-content list-label-widget-content"><ul>';
            foreach ($categories as $category) {
                echo '<li><a href="' . e(category_url($category)) . '">' . e($category['name']) . '</a></li>';
            }
            echo '</ul></div></div>';
        } elseif (in_array($type, ['LinkList', 'PageList'], true)) {
            $links = json_decode($content, true) ?: [];
            if (!$links && $type === 'PageList') {
                $links = db()->query('SELECT title AS name, slug FROM pages WHERE status="published" ORDER BY title')->fetchAll();
                foreach ($links as &$link) {
                    $link['url'] = url('p/' . rawurlencode($link['slug']) . '.html');
                }
                unset($link);
            }
            if (!$links) {
                if ($type !== 'PageList' || $section !== 'menu') {
                    continue;
                }
            }
            $isMenu = $section === 'menu';
            echo '<div class="widget ' . ($isMenu ? 'LinkList menu-section' : 'LinkList') . '">';
            if ($title !== '' && !$isMenu) {
                echo '<h3 class="title">' . e($title) . '</h3>';
            }
            echo '<div class="widget-content"><ul class="' . ($isMenu ? 'menu' : '') . '">';
            if ($type === 'PageList' && $isMenu) {
                echo '<li class="home"><a href="' . e(url()) . '">Home</a></li>';
            }
            foreach ($links as $link) {
                $href = safe_href((string)($link['url'] ?? $link['target'] ?? '#'));
                if (isset($link['slug']) && !isset($link['url'])) {
                    $href = safe_href(page_url($link));
                }
                echo '<li><a href="' . e($href) . '">' . e((string)($link['name'] ?? $link['title'] ?? 'Tautan')) . '</a></li>';
            }
            echo '</ul></div></div>';
        } elseif ($type === 'FeaturedPost') {
            $featured = db()->query('SELECT p.*, u.username AS author FROM posts p JOIN users u ON u.id=p.author_id WHERE p.status="published" AND p.published_at<=NOW() AND p.featured=1 ORDER BY p.published_at DESC LIMIT 1')->fetch();
            if ($featured) {
                echo '<div class="widget FeaturedPost"><h2>' . e($title ?: 'Artikel Unggulan') . '</h2><div class="post-summary">';
                $image = post_image_url($featured);
                if ($image !== '') {
                    echo '<img class="image" src="' . e($image) . '" alt="' . e($featured['title']) . '" loading="lazy">';
                }
                echo '<h3><a href="' . e(post_url($featured)) . '">' . e($featured['title']) . '</a></h3><p>' . e(excerpt($featured)) . '</p></div></div>';
            }
        }
    }
}


function pagination(int $page, int $pages, string $query = ''): void
{
    if ($pages <= 1) {
        return;
    }

    $page = max(1, min($page, $pages));

    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $queryParams = [];
    parse_str(
        (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? ''),
        $queryParams
    );

    if ($query !== '') {
        $queryParams['q'] = $query;
    }

    // Generate pagination URL
    $pageUrl = static function (int $number) use ($requestPath, $queryParams): string {
        $params = $queryParams;
        $params['page'] = $number;

        return e($requestPath . '?' . http_build_query($params));
    };

    // Pagination window
    $window = 3;

    $start = max(1, min($page - 2, $pages - $window + 1));
    $end = min($pages, $start + $window - 1);

    // Collect page numbers
    $numbers = array_unique(array_merge(
        [1],
        range($start, $end),
        [$pages]
    ));

    sort($numbers);

    echo '<div class="blog-pager" id="blog-pager">';

    // Previous
    if ($page > 1) {
        echo '<div class="mobile-link-button previous-link">';
        echo '<a href="' . $pageUrl($page - 1) . '" aria-label="Previous page">';
        echo '‹ Prev';
        echo '</a>';
        echo '</div>';
    }

    // Page Numbers
    echo '<div class="pager-numbers">';

    $previous = 0;

    foreach ($numbers as $number) {

        // Ellipsis
        if ($previous > 0 && $number > $previous + 1) {
            echo '<span class="pager-ellipsis" aria-hidden="true">...</span>';
        }

        // Active Page
        if ($number === $page) {
            echo '<span class="page-number current-page" aria-current="page">';
            echo $number;
            echo '</span>';
        } else {
            echo '<span class="page-number">';
            echo '<a href="' . $pageUrl($number) . '" aria-label="Page ' . $number . '">';
            echo $number;
            echo '</a>';
            echo '</span>';
        }

        $previous = $number;
    }

    echo '</div>';

    // Next
    if ($page < $pages) {
        echo '<div class="mobile-link-button next-link">';
        echo '<a href="' . $pageUrl($page + 1) . '" aria-label="Next page">';
        echo 'Next ›';
        echo '</a>';
        echo '</div>';
    }

    echo '</div>';
}

function render_post_card(array $post, bool $single = false): void
{
    $categories = post_categories((int)$post['id']);
    $image = post_image_url($post);
    echo '<article class="post hentry">';
    if (!$single) {
        echo '<div class="first-thumb"><a href="' . e(post_url($post)) . '">';
        if ($image !== '') {
            echo '<img alt="' . e($post['title']) . '" src="' . e($image) . '" loading="lazy" style="width:100%;height:100%;object-fit:cover">';
        } else {
            echo '<div class="popular-fallback" style="width:100%;height:100%"></div>';
        }
        echo '</a></div>';
    }
    echo '<div class="post-inner">';
    echo $single ? '<h1 class="post-title entry-title">' . e($post['title']) . '</h1>' : '<h2 class="post-title entry-title"><a href="' . e(post_url($post)) . '">' . e($post['title']) . '</a></h2>';
    echo '<div class="title-secondary">
    <span class="post-author vcard">
        <img class="verified-badge" src="https://blog.v-dey.com/badge-check-svgrepo-com.svg" alt="Verified">
        <span class="fn">' . e($post['author'] ?? '') . '</span>
    </span>
    <span class="post-timestamp"> • ' . e(date('d M Y', strtotime((string)$post['published_at']))) . '</span>
</div>';
    echo '<div class="post-body entry-content">';
    if ($single) {
    $shareUrl = post_url($post);
    $shareTitle = trim($post['title']);

    $encodedUrl = rawurlencode($shareUrl);
    $encodedTitle = rawurlencode($shareTitle);
    $encodedMessage = rawurlencode($shareTitle . "\n\n" . $shareUrl);

    echo '<div class="share-but">

        <!-- Facebook -->
        <a href="https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl . '" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13.5 21v-8h2.7l.4-3.1h-3.1v-2c0-.9.3-1.5 1.6-1.5h1.6V3.6c-.8-.1-1.6-.2-2.4-.2-2.4 0-4 1.5-4 4.2v2.3H7.6V13h2.7v8h3.2z"/></svg>
        </a>

        <!-- WhatsApp -->
        <a href="https://wa.me/?text=' . $encodedMessage . '" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" title="WhatsApp">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-3-.2-.3A8 8 0 1 1 12 20zm4.4-5.9c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1-.2.2-.6.8-.8.9-.1.2-.3.2-.5.1-1.5-.7-2.5-1.7-3.2-3.1-.1-.2 0-.3.1-.5l.4-.4c.1-.1.2-.3.3-.4.1-.2 0-.3 0-.5l-.7-1.7c-.2-.4-.4-.4-.5-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 1.9s.8 2.2.9 2.3c.1.2 1.6 2.5 3.9 3.5.5.2.9.4 1.2.4.5.2 1 .2 1.4.1.4-.1 1.4-.6 1.6-1.1.2-.5.2-1 .1-1.1-.1-.1-.3-.2-.5-.3z"/></svg>
        </a>

        <!-- X -->
        <a href="https://twitter.com/intent/tweet?text=' . $encodedTitle . '&url=' . $encodedUrl . '" target="_blank" rel="noopener noreferrer" aria-label="X" title="X">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18.9 2H22l-6.8 7.8L23.2 22h-6.3L12 14.6 5.5 22H2.4l7.3-8.4L1.9 2h6.5l4.4 6.9L18.9 2zm-1.1 18h1.7L7.4 3.9H5.6L17.8 20z"/></svg>
        </a>

        <!-- Telegram -->
        <a href="https://t.me/share/url?url=' . $encodedUrl . '&text=' . $encodedTitle . '" target="_blank" rel="noopener noreferrer" aria-label="Telegram" title="Telegram">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21.9 3.1 18.7 20c-.2 1.2-.9 1.5-1.8.9l-5-3.7-2.4 2.3c-.3.3-.5.5-1 .5l.4-5.1 9.3-8.4c.4-.4-.1-.6-.6-.2L6.1 13.5l-4.9-1.5c-1.1-.3-1.1-1 .2-1.5L20.5 3c.9-.3 1.7.2 1.4 1z"/></svg>
        </a>

        <!-- LinkedIn -->
        <a href="https://www.linkedin.com/sharing/share-offsite/?url=' . $encodedUrl . '" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5.2 3a2.2 2.2 0 1 0 0 4.4 2.2 2.2 0 0 0 0-4.4zM3.3 9h3.8v12H3.3zm6.2 0h3.6v1.6h.1a4 4 0 0 1 3.6-2c3.9 0 4.6 2.5 4.6 5.7V21h-3.8v-6c0-1.4 0-3.2-2-3.2s-2.3 1.5-2.3 3.1V21H9.5z"/></svg>
        </a>

    </div>';
    echo $post['body'];
    } else {
        echo '<div class="post-snippet"><p>' . e(excerpt($post)) . '</p></div>';
    }
    echo '<div class="clear"></div></div>';
    if ($single && $categories) {
        echo '<div class="post-footer"><span class="post-labels">';
        foreach ($categories as $category) {
            echo '<a href="' . e(category_url($category)) . '" rel="tag">' . e($category['name']) . '</a>';
        }
        echo '</span></div>';
    }
    if ($single) {
        $tagsStmt = db()->prepare('SELECT t.name FROM tags t JOIN post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=? ORDER BY t.name');
        $tagsStmt->execute([(int)$post['id']]);
        $tags = $tagsStmt->fetchAll();
        if ($tags) {
            echo '<div class="post-labels">';
            foreach ($tags as $tag) {
                echo '<a class="label-tag" href="' . e(url('search?q=' . rawurlencode($tag['name']))) . '">' . e($tag['name']) . '</a>';
            }
            echo '</div>';
        }
        echo '<div class="subscriptz"><h5>Get updates in your Inbox</h5><a href="' . e(url('feed.xml')) . '" rel="noopener">Subscribe</a></div>';
    }
    echo '</div></article>';
}
