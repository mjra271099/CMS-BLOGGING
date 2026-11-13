<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';

$route = (string)($_GET['route'] ?? '');
$requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$basePath = trim((string)(parse_url(base_url(), PHP_URL_PATH) ?? ''), '/');
if ($basePath !== '') {
    $trimmedPath = trim($requestPath, '/');
    if ($trimmedPath === $basePath) {
        $requestPath = '/';
    } elseif (str_starts_with($trimmedPath, $basePath . '/')) {
        $requestPath = substr($trimmedPath, strlen($basePath));
    }
}
$path = trim($requestPath, '/');
if ($route === '') {
    if ($path === 'search') {
        $route = 'search';
    } elseif (preg_match('~^search/label/([^/]+)$~', $path, $match)) {
        $route = 'category';
        $_GET['slug'] = $match[1];
    } elseif (preg_match('~^p/([^/]+)\.html$~', $path, $match)) {
        $route = 'page';
        $_GET['slug'] = $match[1];
    } elseif (preg_match('~^\d{4}/\d{2}/([^/]+)\.html$~', $path, $match)) {
        $route = 'post';
        $_GET['slug'] = $match[1];
    } elseif ($path !== '') {
        $route = 'not-found';
    }
}

if ($route === 'robots') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /install.php\nSitemap: " . url('sitemap.xml') . "\n";
    exit;
}
if ($route === 'sitemap') {
    header('Content-Type: application/xml; charset=utf-8');
    $posts = db()->query('SELECT slug, published_at FROM posts WHERE status="published" AND published_at<=NOW() ORDER BY published_at DESC')->fetchAll();
    $pages = db()->query('SELECT slug, updated_at FROM pages WHERE status="published" AND published_at<=NOW() ORDER BY updated_at DESC')->fetchAll();
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">";
    echo '<url><loc>' . e(url()) . '</loc></url>';
    foreach ($posts as $post) {
        echo '<url><loc>' . e(post_url($post)) . '</loc><lastmod>' . date(DATE_W3C, strtotime($post['published_at'])) . '</lastmod></url>';
    }
    foreach ($pages as $page) {
        echo '<url><loc>' . e(page_url($page)) . '</loc><lastmod>' . date(DATE_W3C, strtotime($page['updated_at'])) . '</lastmod></url>';
    }
    echo '</urlset>';
    exit;
}
if ($route === 'feed') {
    header('Content-Type: application/rss+xml; charset=utf-8');
    $posts = published_posts(20);
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>';
    echo '<title>' . e(setting('site_name')) . '</title><link>' . e(url()) . '</link><description>' . e(setting('site_description')) . '</description>';
    foreach ($posts as $post) {
        echo '<item><title>' . e($post['title']) . '</title><link>' . e(post_url($post)) . '</link><guid>' . e(post_url($post)) . '</guid><pubDate>' . date(DATE_RSS, strtotime($post['published_at'])) . '</pubDate><description>' . e(excerpt($post)) . '</description></item>';
    }
    echo '</channel></rss>';
    exit;
}

$pageTitle = setting('site_name');
$pageDescription = setting('site_description');
$canonical = url();
$socialImage = '';
$isArticle = false;
if ($route === 'post') {
    $slug = (string)($_GET['slug'] ?? '');
    $preview = (string)($_GET['preview'] ?? '') === '1' && current_user() !== null;
    $postSql = 'SELECT p.*, u.username AS author FROM posts p JOIN users u ON u.id=p.author_id WHERE p.slug=?';
    if (!$preview) {
        $postSql .= ' AND p.status="published" AND p.published_at<=NOW()';
    }
    $stmt = db()->prepare($postSql);
    $stmt->execute([$slug]);
    $post = $stmt->fetch();
    if (!$post) {
        http_response_code(404);
        $route = 'not-found';
        $pageTitle = 'Page Not Found - ' . setting('site_name');
    } else {
        if (!$preview) {
            db()->prepare('UPDATE posts SET views=views+1 WHERE id=?')->execute([(int)$post['id']]);
        }
        $pageTitle = $post['meta_title'] ?: $post['title'] . ' - ' . setting('site_name');
        $pageDescription = $post['meta_description'] ?: excerpt($post);
        $canonical = post_url($post);
        $socialImage = (string)$post['thumbnail'];
        $isArticle = true;
    }
} elseif ($route === 'page') {
    $preview = (string)($_GET['preview'] ?? '') === '1' && current_user() !== null;
    $pageSql = 'SELECT * FROM pages WHERE slug=?';
    if (!$preview) {
        $pageSql .= ' AND status="published" AND published_at<=NOW()';
    }
    $stmt = db()->prepare($pageSql);
    $stmt->execute([(string)($_GET['slug'] ?? '')]);
    $page = $stmt->fetch();
    if (!$page) {
        http_response_code(404);
        $route = 'not-found';
        $pageTitle = 'Page Not Found - ' . setting('site_name');
    } else {
        $pageTitle = $page['meta_title'] ?: $page['title'] . ' - ' . setting('site_name');
        $pageDescription = $page['meta_description'] ?: setting('site_description');
        $canonical = page_url($page);
    }
} elseif ($route === 'category') {
    $stmt = db()->prepare('SELECT name, slug FROM categories WHERE slug=?');
    $stmt->execute([(string)($_GET['slug'] ?? '')]);
    $category = $stmt->fetch();
    if (!$category) {
        http_response_code(404);
        $route = 'not-found';
    } else {
        $pageTitle = $category['name'] . ' - ' . setting('site_name');
        $pageDescription = 'Artikel kategori ' . $category['name'] . ' di ' . setting('site_name');
        $canonical = category_url($category);
    }
}

if ($route === 'search') {
    $term = trim((string)($_GET['q'] ?? ''));
    $pageTitle = ($term !== '' ? 'Search: ' . $term . ' - ' : '') . setting('site_name');
    $pageDescription = setting('site_description');
    $canonical = url('search?q=' . rawurlencode($term));
}
$homepageContent = null;
if ($route === '' && setting('homepage_mode', 'posts') === 'page') {
    $homepagePageId = (int)setting('homepage_page_id', '0');
    if ($homepagePageId > 0) {
        $stmt = db()->prepare('SELECT * FROM pages WHERE id=? AND status="published" AND published_at<=NOW()');
        $stmt->execute([$homepagePageId]);
        $homepageContent = $stmt->fetch() ?: null;
        if ($homepageContent) {
            $pageTitle = $homepageContent['meta_title'] ?: $homepageContent['title'] . ' - ' . setting('site_name');
            $pageDescription = $homepageContent['meta_description'] ?: setting('site_description');
            $canonical = url();
        }
    }
}
require __DIR__ . '/header.php';
ob_start();
?>
<div class="header-atas"><div class="header-batas"><div class="header-inner-wrap">
<?php if (header_widget_is_enabled()): ?><div id="header"><div id="header-inner"><div class="titlewrapper">
<?php if (setting('logo_url') !== ''): ?><img id="logo" src="<?= e(setting('logo_url')) ?>" alt=""><?php else: ?>
<svg id="logo" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg"><g fill="none" fill-rule="evenodd"><path d="M0 15c0 8.284 6.716 15 15 15 8.285 0 15-6.716 15-15 0-8.284-6.715-15-15-15C6.716 0 0 6.716 0 15z" fill="#FFF"/><path d="M13.85 24.098h-1.14l1.128-6.823-3.49.005h-.05a.57.57 0 0 1-.568-.569c0-.135.125-.363.125-.363l6.272-10.46 1.16.005-1.156 6.834 3.508-.004h.056c.314 0 .569.254.569.568 0 .128-.05.24-.121.335L13.85 24.098zM15 0C6.716 0 0 6.716 0 15c0 8.284 6.716 15 15 15 8.285 0 15-6.716 15-15 0-8.284-6.715-15-15-15z" fill="#4285f4"/></g></svg>
<?php endif; ?>
<h1 class="title"><a href="<?= e(url()) ?>" title="<?= e(setting('site_name')) ?>"><span><?= e(setting('site_name')) ?></span></a></h1>
</div><?php if (setting('site_description') !== ''): ?><div class="descriptionwrapper"><p class="description"><span><?= e(setting('site_description')) ?></span></p></div><?php endif; ?></div></div><?php endif; ?>
<div class="social-iconic">
<a href="https://www.facebook.com/profile.php?id=61592563659430&amp;locale=id_ID" rel="nofollow noopener" target="_blank" title="Like Our Facebook"><svg class="ic-fb" viewBox="0 0 24 24"><path d="M17 2h-3a4 4 0 0 0-4 4v4H7v4h3v8h4v-8h3l1-4h-4V6c0-.6.4-1 1-1h2V2z" fill="#fff"/></svg></a>
<a href="https://www.instagram.com/USERNAME-INSTAGRAM/" rel="nofollow noopener" target="_blank" title="Follow Our Instagram"><svg class="ic-ig" viewBox="0 0 24 24"><path d="M7.8 2h8.4A5.8 5.8 0 0 1 22 7.8v8.4a5.8 5.8 0 0 1-5.8 5.8H7.8A5.8 5.8 0 0 1 2 16.2V7.8A5.8 5.8 0 0 1 7.8 2zm0 2A3.8 3.8 0 0 0 4 7.8v8.4A3.8 3.8 0 0 0 7.8 20h8.4a3.8 3.8 0 0 0 3.8-3.8V7.8A3.8 3.8 0 0 0 16.2 4H7.8zM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10z" fill="#fff"/></svg></a>
<a href="<?= e(url('feed.xml')) ?>" rel="nofollow noopener" title="RSS Feed"><svg class="ic-feed" viewBox="0 0 24 24"><path d="M5 3a16 16 0 0 1 16 16h-4A12 12 0 0 0 5 7V3zm0 7a9 9 0 0 1 9 9h-4a5 5 0 0 0-5-5v-4zm2 7a2 2 0 1 1 0 4 2 2 0 0 1 0-4z" fill="#fff"/></svg></a>
<a href="https://www.youtube.com/@videykuplay" rel="nofollow noopener" target="_blank" title="Subscribe Our Channel"><svg class="ic-yt" viewBox="0 0 24 24"><path d="M21.6 7.2a2.5 2.5 0 0 0-1.8-1.8C18.2 5 12 5 12 5s-6.2 0-7.8.4a2.5 2.5 0 0 0-1.8 1.8A26 26 0 0 0 2 12s0 3.2.4 4.8a2.5 2.5 0 0 0 1.8 1.8C5.8 19 12 19 12 19s6.2 0 7.8-.4a2.5 2.5 0 0 0 1.8-1.8A26 26 0 0 0 22 12s0-3.2-.4-4.8zM10 15.5v-7l6 3.5-6 3.5z" fill="#fff"/></svg></a>
</div>
<div id="navigation"><nav class="main-nav" role="navigation"><?php render_widgets('menu'); ?></nav></div>
</div></div>
<?php
$headerMarkup = ob_get_clean();

ob_start();
if (section_widget_is_enabled('search', 'BlogSearch')):
?>
<div class="search-atas"><form action="<?= e(url('search')) ?>" method="get" role="search">
<input aria-label="Search" autocomplete="off" class="query" name="q" placeholder="Search here..." required type="text" value="<?= e((string)($_GET['q'] ?? '')) ?>">
<button aria-label="Search" type="submit"><svg viewBox="0 0 24 24"><path d="M9.5,3A6.5,6.5 0 0,1 16,9.5C16,11.11 15.41,12.59 14.44,13.73L14.71,14H15.5L20.5,19L19,20.5L14,15.5V14.71L13.73,14.44C12.59,15.41 11.11,16 9.5,16A6.5,6.5 0 0,1 3,9.5A6.5,6.5 0 0,1 9.5,3M9.5,5C7,5 5,7 5,9.5C5,12,7,14,9.5,14C12,14,14,12,14,9.5C14,7,12,5,9.5,5Z" fill="#fff"/></svg></button>
</form></div>
<?php endif;
$searchMarkup = ob_get_clean();

ob_start();
render_widgets('top-banner');
$topBannerMarkup = ob_get_clean();

ob_start();
if ($route === '') {
    render_widgets('featured');
}
$featuredMarkup = ob_get_clean();

ob_start();
if ($route === 'post') {
    require __DIR__ . '/post.php';
} elseif ($route === 'page') {
    require __DIR__ . '/page.php';
} elseif ($route === 'category') {
    require __DIR__ . '/category.php';
} elseif ($route === 'search') {
    require __DIR__ . '/search.php';
} elseif ($route === 'not-found') {
    echo '<div class="error-wrapper" id="error-wrapper"><div id="error-page"><div id="error-inner"><div class="box-404"><div>404</div></div><h2>Page Not Found</h2></div></div></div>';
} else {
    if ($homepageContent) {
        echo '<article class="post page"><div class="post-inner"><h1 class="post-title entry-title">' . e($homepageContent['title']) . '</h1><div class="post-body entry-content">' . $homepageContent['body'] . '</div></div></article>';
    } else {
        $perPage = max(1, min(50, (int)setting('posts_per_page', '7')));
        $current = max(1, (int)($_GET['page'] ?? 1));
        $total = (int)db()->query('SELECT COUNT(*) FROM posts WHERE status="published" AND published_at<=NOW()')->fetchColumn();
        foreach (published_posts($perPage, ($current - 1) * $perPage) as $post) {
            render_post_card($post);
        }
        if ($total === 0) {
            echo '<div class="status-msg-wrap">Belum ada postingan.</div>';
        }
        pagination($current, (int)ceil($total / $perPage));
    }
}
?>
<?php
$mainMarkup = ob_get_clean();
ob_start();
render_widgets('middle-content');
$middleMarkup = ob_get_clean();
ob_start();
require __DIR__ . '/sidebar.php';
$sidebarMarkup = ob_get_clean();
ob_start();
require __DIR__ . '/footer.php';
$footerMarkup = ob_get_clean();

$layoutTemplate = setting('theme_template_html');
if ($layoutTemplate !== '') {
    if (!str_contains($layoutTemplate, '{{main_content}}')) {
        throw new RuntimeException('Template HTML tidak memiliki token {{main_content}}.');
    }
    echo strtr($layoutTemplate, [
        '{{header}}' => $headerMarkup,
        '{{search}}' => $searchMarkup,
        '{{top_banner}}' => $topBannerMarkup,
        '{{featured}}' => $featuredMarkup,
        '{{main_content}}' => $mainMarkup,
        '{{middle_content}}' => $middleMarkup,
        '{{sidebar}}' => $sidebarMarkup,
        '{{footer}}' => $footerMarkup,
    ]);
} else {
    echo $headerMarkup;
    echo '<main class="amz-wrapper">' . $searchMarkup . $topBannerMarkup . $featuredMarkup;
    echo '<div class="container"><div class="outer-wrapper"><div class="main-wrapper"><div class="main-inner-wrap">';
    echo $mainMarkup . $middleMarkup;
    echo '</div></div></div></div></main>';
    echo $sidebarMarkup . $footerMarkup;
}
?>
</body>
</html>
