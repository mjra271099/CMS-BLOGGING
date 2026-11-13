<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Context;
use App\Core\Database;
use App\Core\Helpers;
use App\Core\Logger;
use App\Core\NotFoundException;
use App\Core\SecurityException;
use App\Core\Settings;
use App\Core\Theme;
use App\Core\ThemeEngine;
use App\Core\View;
use App\Models\Comment;
use App\Models\Page;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Services\Newsletter;
use App\Services\Seo;
use App\Services\Builder;

/**
 * Front-end router. Resolves legacy Blogger-style URLs and renders pages
 * through the active theme.
 */
final class FrontController
{
    public function handle(): string
    {
        $path = $this->relativePath();
        $this->ensureSchema();
        $route = $this->resolve($path);

        // Redirect manager runs before content resolution.
        $redirect = Seo::redirectFor('/' . ltrim($path, '/'));
        if ($redirect) {
            $target = (string)$redirect['to_path'];
            $location = str_starts_with($target, 'http') ? $target : url(ltrim($target, '/'));
            if (!headers_sent()) {
                header('Location: ' . $location, true, (int)$redirect['status_code']);
            }
            return '';
        }

        if (Settings::get('maintenance_mode') === '1' && !Auth::check()) {
            if (!headers_sent()) {
                header('Retry-After: 3600');
                http_response_code(503);
            }
            return $this->maintenance();
        }

        return match ($route['name']) {
            'home' => $this->home(),
            'post' => $this->post($route['params']),
            'page' => $this->page($route['params']),
            'category' => $this->category($route['params']),
            'search' => $this->search(),
            'tag' => $this->search(),
            'author' => $this->author($route['params']),
            'archive' => $this->archive($route['params']),
            'sitemap' => $this->sitemap(),
            'robots' => $this->robots(),
            'feed' => $this->feed(),
            'newsletter' => $this->newsletter(),
            'preview' => $this->post($route['params']),
            'not-found' => $this->notFound(),
            default => $this->notFound(),
        };
    }

    private function relativePath(): string
    {
        $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $basePath = trim((string)(parse_url(base_url(), PHP_URL_PATH) ?: ''), '/');
        $trimmed = trim($requestPath, '/');
        if ($basePath !== '') {
            if ($trimmed === $basePath) {
                return '';
            }
            if (str_starts_with($trimmed, $basePath . '/')) {
                return substr($trimmed, strlen($basePath) + 1);
            }
        }
        return $trimmed;
    }

    private function resolve(string $path): array
    {
        if ($path === '') {
            return ['name' => 'home', 'params' => []];
        }
        if ($path === 'sitemap.xml') {
            return ['name' => 'sitemap', 'params' => []];
        }
        if ($path === 'robots.txt') {
            return ['name' => 'robots', 'params' => []];
        }
        if ($path === 'feed.xml' || $path === 'rss.xml' || $path === 'atom.xml' || $path === 'feed' || $path === 'rss') {
            return ['name' => 'feed', 'params' => []];
        }
        if ($path === 'search') {
            return ['name' => 'search', 'params' => []];
        }
        if (preg_match('~^page/(\d+)$~', $path, $match)) {
            $_GET['page'] = max(1, (int)$match[1]);
            return ['name' => 'home', 'params' => []];
        }
        if ($path === 'newsletter') {
            return ['name' => 'newsletter', 'params' => []];
        }
        if (preg_match('~^search/label/([^/]+)$~', $path, $match)) {
            return ['name' => 'category', 'params' => ['slug' => urldecode($match[1])]];
        }
        if (preg_match('~^tag/([^/]+)$~', $path, $match)) {
            $_GET['q'] = urldecode($match[1]);
            return ['name' => 'tag', 'params' => []];
        }
        if (preg_match('~^author/([^/]+)$~', $path, $match)) {
            return ['name' => 'author', 'params' => ['slug' => urldecode($match[1])]];
        }
        if (preg_match('~^archive/(\d{4})/(\d{2})$~', $path, $match)) {
            return ['name' => 'archive', 'params' => ['year' => (int)$match[1], 'month' => (int)$match[2]]];
        }
        if (preg_match('~^p/([^/]+)\.html$~', $path, $match)) {
            return ['name' => 'page', 'params' => ['slug' => urldecode($match[1])]];
        }
        if (preg_match('~^\d{4}/\d{2}/([^/]+)\.html$~', $path, $match)) {
            return ['name' => 'post', 'params' => ['slug' => urldecode($match[1])]];
        }
        if (preg_match('~^post/([^/]+)$~', $path, $match)) {
            return ['name' => 'post', 'params' => ['slug' => urldecode($match[1])]];
        }
        if (preg_match('~^page/([^/]+)$~', $path, $match)) {
            return ['name' => 'page', 'params' => ['slug' => urldecode($match[1])]];
        }
        // A top-level slug may be a custom page permalink (maps to p/slug.html).
        $page = Page::findBySlug($path);
        if ($page) {
            return ['name' => 'page', 'params' => ['slug' => $path]];
        }
        $post = Post::findBySlug($path, null, Auth::check());
        if ($post) {
            return ['name' => 'post', 'params' => ['slug' => $path]];
        }
        return ['name' => 'not-found', 'params' => []];
    }

    /**
     * Apply pending migrations exactly once per release. Cheap after that.
     */
    private function ensureSchema(): void
    {
        $check = (int)Cache::get('schema_version', 0);
        if ($check === (int)getenv('CMS_SCHEMA_VERSION') && $check > 0) {
            return;
        }
        try {
            $applied = \App\Core\Migrator::run();
            Cache::put('schema_version', (int)getenv('CMS_SCHEMA_VERSION') ?: 2, 3600);
            if ($applied) {
                Logger::info('migrations applied', ['versions' => $applied]);
            }
        } catch (\Throwable $e) {
            Logger::exception($e);
        }
    }

    // ------------------------------------------------------------------
    // Views
    // ------------------------------------------------------------------
    private function home(): string
    {
        $mode = Settings::get('homepage_mode', 'posts');
        $hero = '';
        if ($mode === 'page') {
            $pageId = Settings::int('homepage_page_id');
            $page = $pageId > 0 ? Page::find($pageId) : null;
            if ($page && $page['status'] === 'published') {
                $content = $this->renderPageBody($page);
                $pageTitle = (string)($page['meta_title'] ?: $page['title']);
                return $this->renderTheme([
                    'route' => 'home',
                    'slug' => $page['slug'],
                    'content' => $content,
                    'layout_mode' => $page['template'] === 'builder' ? 'full' : 'standard',
                    'meta' => Seo::meta([
                        'title' => $pageTitle,
                        'description' => $page['meta_description'] ?: excerpt($page),
                        'canonical' => url(),
                        'image' => (string)($page['og_image'] ?? ''),
                        'type' => 'website',
                    ]),
                    'context' => ['route' => 'home'],
                ]);
            }
        }
        $perPage = max(1, min(50, Settings::int('posts_per_page', 9)));
        $current = max(1, (int)($_GET['page'] ?? 1));
        $posts = Post::query(['status' => 'published', 'sort' => 'sticky'], $perPage, ($current - 1) * $perPage);
        $total = Post::count(['status' => 'published']);
        $totalPages = (int)ceil(max(1, $total) / $perPage);
        $content = $this->heroArea() . $hero;
        $content .= '<div class="section-heading"><h2>Artikel Terbaru</h2>'
            . '<a class="section-link" href="' . e(url('archive')) . '">Arsip lengkap →</a></div>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $content .= $this->pager($current, $totalPages);
        return $this->renderTheme([
            'route' => 'home',
            'content' => $content,
            'layout_mode' => 'standard',
            'meta' => Seo::meta([
                'title' => Settings::get('site_name'),
                'description' => Settings::get('site_description'),
                'canonical' => url(),
                'image' => Settings::get('social_image'),
                'type' => 'website',
            ]),
            'context' => ['route' => 'home'],
        ]);
    }

    private function heroArea(): string
    {
        $widgets = (new \App\Theme\Ui(Theme::instance(), []))->widgetArea('featured');
        return $widgets !== '' ? '<section class="hero-area">' . $widgets . '</section>' : '';
    }

    private function post(array $params): string
    {
        $slug = (string)($params['slug'] ?? '');
        $preview = (string)($_GET['preview'] ?? '') === '1' && Auth::check();
        $post = Post::findBySlug($slug, null, $preview);
        if (!$post) {
            return $this->notFound();
        }
        if ($post['visibility'] === 'password' && !$preview) {
            return $this->passwordGate($post);
        }
        if (!$preview) {
            Post::incrementViews((int)$post['id']);
        }
        $ui = new \App\Theme\Ui(Theme::instance(), []);
        $content = $ui->article($post) . $this->commentsSection($post);
        $related = Post::related((int)$post['id'], 3);
        if ($related) {
            $content .= '<section class="related-section"><h2>Artikel Terkait</h2>';
            $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($related, 'grid');
            $content .= '</section>';
        }
        $categories = Taxonomy::postCategories((int)$post['id']);
        $breadcrumbs = [['name' => 'Beranda', 'url' => url()]];
        if ($categories) {
            $breadcrumbs[] = ['name' => $categories[0]['name'], 'url' => category_url($categories[0])];
        }
        $breadcrumbs[] = ['name' => $post['title'], 'url' => post_url($post)];
        $description = $post['meta_description'] ?: excerpt($post, 160);
        return $this->renderTheme([
            'route' => 'post',
            'slug' => $post['slug'],
            'content' => $content,
            'layout_mode' => 'standard',
            'breadcrumbs' => $breadcrumbs,
            'context' => ['route' => 'post', 'post_id' => (int)$post['id'], 'author_id' => (int)$post['author_id'], 'body' => (string)$post['body'], 'slug' => (string)$post['slug']],
            'meta' => Seo::meta([
                'title' => (string)($post['meta_title'] ?: $post['title']),
                'description' => $description,
                'canonical' => (string)($post['canonical_url'] ?: post_url($post)),
                'image' => (string)($post['og_image'] ?: post_image_url($post)),
                'type' => 'article',
                'robots' => (string)($post['robots'] ?: ($preview ? 'noindex,nofollow' : Settings::get('robots_policy', 'index,follow'))),
                'published' => Helpers::isoDate((string)$post['published_at']),
                'modified' => Helpers::isoDate((string)$post['updated_at']),
                'author' => (string)($post['author_name'] ?? $post['author'] ?? ''),
                'article' => [
                    'title' => $post['title'],
                    'body' => $post['body'],
                    'categories' => array_column($categories, 'name'),
                ],
                'breadcrumbs' => $breadcrumbs,
            ]),
        ]);
    }

    private function passwordGate(array $post): string
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_password'])) {
            \App\Core\Csrf::verify();
            if (password_verify((string)$_POST['post_password'], (string)$post['access_password'])) {
                $_SESSION['unlocked_posts'][(int)$post['id']] = true;
            } else {
                $error = 'Password salah.';
            }
        }
        if (!empty($_SESSION['unlocked_posts'][(int)$post['id']])) {
            return $this->post(['slug' => $post['slug']] + ['preview' => 0]);
        }
        $content = '<div class="protected-post"><h1>' . e($post['title']) . '</h1>'
            . '<p>Postingan ini dilindungi password.</p>'
            . ($error !== '' ? '<p class="form-error">' . e($error) . '</p>' : '')
            . '<form method="post">' . \App\Core\Csrf::field()
            . '<input type="password" name="post_password" placeholder="Password" required>'
            . '<button type="submit">Buka</button></form></div>';
        return $this->renderTheme([
            'route' => 'post',
            'content' => $content,
            'meta' => Seo::meta(['title' => $post['title'], 'robots' => 'noindex,nofollow', 'canonical' => post_url($post)]),
        ]);
    }

    private function page(array $params): string
    {
        $slug = (string)($params['slug'] ?? '');
        $preview = (string)($_GET['preview'] ?? '') === '1' && Auth::check();
        $page = Page::findBySlug($slug, $preview);
        if (!$page) {
            return $this->notFound();
        }
        $content = $this->renderPageBody($page);
        $layout = in_array((string)$page['template'], ['full-width', 'landing', 'builder'], true) ? 'full' : 'standard';
        $breadcrumbs = [['name' => 'Beranda', 'url' => url()], ['name' => $page['title'], 'url' => page_url($page)]];
        return $this->renderTheme([
            'route' => 'page',
            'slug' => $page['slug'],
            'content' => $content,
            'layout_mode' => $layout,
            'breadcrumbs' => $breadcrumbs,
            'context' => ['route' => 'page', 'slug' => (string)$page['slug']],
            'meta' => Seo::meta([
                'title' => (string)($page['meta_title'] ?: $page['title']),
                'description' => (string)($page['meta_description'] ?: excerpt($page)),
                'canonical' => (string)($page['canonical_url'] ?: page_url($page)),
                'image' => (string)($page['og_image'] ?? ''),
                'type' => 'website',
                'robots' => (string)($page['robots'] ?: Settings::get('robots_policy', 'index,follow')),
                'breadcrumbs' => $breadcrumbs,
            ]),
        ]);
    }

    private function renderPageBody(array $page): string
    {
        $builder = Builder::decode((string)($page['content_json'] ?? ''));
        $body = trim((string)$page['body']);
        $out = '';
        if ($body === '' && !empty($builder['sections'])) {
            $body = Builder::render((string)$page['content_json']);
        } elseif ($body !== '' && (string)($page['template'] ?? '') === 'builder') {
            $body = Builder::render((string)$page['content_json']) . '<div class="prose">' . $body . '</div>';
        }
        $out .= '<article class="single-page page-' . e((string)($page['template'] ?? 'default')) . '">';
        $out .= '<header class="page-header"><h1>' . e($page['title']) . '</h1>';
        if ((string)($page['excerpt'] ?? '') !== '') {
            $out .= '<p class="article-lede">' . e((string)$page['excerpt']) . '</p>';
        }
        $out .= '</header><div class="article-body prose">' . $body . '</div></article>';
        if (!empty($page['custom_css'])) {
            $out .= '<style>' . strip_tags((string)$page['custom_css'], '<style>') . '</style>';
        }
        if (!empty($page['custom_script']) && Auth::isAdmin()) {
            $out .= (string)$page['custom_script'];
        }
        return $out;
    }

    private function category(array $params): string
    {
        $slug = (string)($params['slug'] ?? '');
        $category = Taxonomy::categoryBySlug($slug);
        if (!$category) {
            return $this->notFound();
        }
        $perPage = max(1, min(50, Settings::int('posts_per_page', 9)));
        $current = max(1, (int)($_GET['page'] ?? 1));
        $posts = Post::query(['status' => 'published', 'category_id' => (int)$category['id']], $perPage, ($current - 1) * $perPage);
        $total = (int)Database::value(
            "SELECT COUNT(DISTINCT p.id) FROM posts p JOIN post_categories pc ON pc.post_id = p.id
             WHERE p.blog_id = ? AND pc.category_id = ? AND p.status = 'published' AND p.published_at <= NOW()",
            [Context::blogId(), (int)$category['id']]
        );
        $content = '<header class="archive-header"><span class="eyebrow">Kategori</span><h1>' . e($category['name']) . '</h1>';
        if (!empty($category['description'])) {
            $content .= '<p>' . e((string)$category['description']) . '</p>';
        }
        $content .= '<span class="archive-count">' . $total . ' artikel</span></header>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        $breadcrumbs = [['name' => 'Beranda', 'url' => url()], ['name' => $category['name'], 'url' => category_url($category)]];
        return $this->renderTheme([
            'route' => 'category',
            'slug' => $category['slug'],
            'content' => $content,
            'breadcrumbs' => $breadcrumbs,
            'context' => ['route' => 'category', 'slug' => (string)$category['slug']],
            'meta' => Seo::meta([
                'title' => (string)($category['meta_title'] ?: $category['name']),
                'description' => (string)($category['meta_description'] ?: $category['description'] ?: 'Artikel kategori ' . $category['name']),
                'canonical' => category_url($category),
                'image' => (string)($category['image'] ?? ''),
                'type' => 'website',
                'breadcrumbs' => $breadcrumbs,
            ]),
        ]);
    }

    private function search(): string
    {
        $term = trim((string)($_GET['q'] ?? ''));
        $perPage = max(1, min(50, Settings::int('posts_per_page', 9)));
        $current = max(1, (int)($_GET['page'] ?? 1));
        $posts = [];
        $total = 0;
        if ($term !== '') {
            $posts = Post::query(['status' => 'published', 'search' => $term], $perPage, ($current - 1) * $perPage);
            $total = Post::count(['status' => 'published', 'search' => $term]);
        }
        $content = '<div class="search-page"><form class="search-form" action="' . e(url('search')) . '" method="get" role="search">'
            . '<input type="search" name="q" value="' . e($term) . '" placeholder="Cari artikel, topik, atau kata kunci…" aria-label="Kata kunci" autofocus>'
            . '<button type="submit">Cari</button></form>';
        if ($term === '') {
            $content .= (new \App\Theme\Ui(Theme::instance(), []))->emptyState('Masukkan kata kunci untuk mulai mencari.');
        } else {
            $content .= '<p class="search-summary">' . $total . ' hasil untuk <strong>“' . e($term) . '”</strong></p>';
            $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'list');
            $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        }
        $content .= '</div>';
        return $this->renderTheme([
            'route' => 'search',
            'content' => $content,
            'context' => ['route' => 'search'],
            'meta' => Seo::meta([
                'title' => $term !== '' ? 'Hasil pencarian: ' . $term : 'Pencarian',
                'description' => 'Hasil pencarian untuk ' . $term,
                'canonical' => url('search?q=' . rawurlencode($term)),
                'robots' => 'noindex,follow',
            ]),
        ]);
    }

    private function author(array $params): string
    {
        $slug = (string)($params['slug'] ?? '');
        $authors = Database::fetchAll(
            'SELECT DISTINCT u.id, u.username, u.display_name, u.bio, u.avatar FROM users u
             JOIN posts p ON p.author_id = u.id WHERE p.blog_id = ? AND p.status = "published"',
            [Context::blogId()]
        );
        $author = null;
        foreach ($authors as $candidate) {
            $name = (string)($candidate['display_name'] ?: $candidate['username']);
            if (App\Core\Slug::make($name) === $slug) {
                $author = $candidate;
                break;
            }
        }
        if (!$author) {
            return $this->notFound();
        }
        $perPage = max(1, min(50, Settings::int('posts_per_page', 9)));
        $current = max(1, (int)($_GET['page'] ?? 1));
        $posts = Post::query(['status' => 'published', 'author_id' => (int)$author['id']], $perPage, ($current - 1) * $perPage);
        $total = Post::count(['status' => 'published', 'author_id' => (int)$author['id']]);
        $name = (string)($author['display_name'] ?: $author['username']);
        $content = '<header class="archive-header author-header"><div class="author-avatar">' . e(mb_substr($name, 0, 1)) . '</div>'
            . '<span class="eyebrow">Penulis</span><h1>' . e($name) . '</h1>'
            . '<p>' . e((string)($author['bio'] ?: 'Penulis di ' . Settings::get('site_name') . '.')) . '</p>'
            . '<span class="archive-count">' . $total . ' artikel</span></header>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        $breadcrumbs = [['name' => 'Beranda', 'url' => url()], ['name' => $name, 'url' => Helpers::authorUrl($name)]];
        return $this->renderTheme([
            'route' => 'author',
            'slug' => $slug,
            'content' => $content,
            'breadcrumbs' => $breadcrumbs,
            'context' => ['route' => 'author', 'author_id' => (int)$author['id']],
            'meta' => Seo::meta([
                'title' => $name,
                'description' => (string)($author['bio'] ?? ''),
                'canonical' => Helpers::authorUrl($name),
                'type' => 'profile',
                'breadcrumbs' => $breadcrumbs,
            ]),
        ]);
    }

    private function archive(array $params): string
    {
        $year = (int)($params['year'] ?? 0);
        $month = (int)($params['month'] ?? 0);
        $perPage = max(1, min(50, Settings::int('posts_per_page', 9)));
        $current = max(1, (int)($_GET['page'] ?? 1));
        $filters = ['status' => 'published'];
        $monthKey = sprintf('%04d-%02d', $year, $month);
        $posts = Post::query($filters + ['month' => $monthKey], $perPage, ($current - 1) * $perPage);
        $total = Post::count($filters + ['month' => $monthKey]);
        $label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
        $content = '<header class="archive-header"><span class="eyebrow">Arsip</span><h1>' . e($label) . '</h1>'
            . '<span class="archive-count">' . $total . ' artikel</span></header>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        return $this->renderTheme([
            'route' => 'archive',
            'content' => $content,
            'meta' => Seo::meta([
                'title' => 'Arsip ' . $label,
                'description' => 'Artikel dalam arsip ' . $label,
                'canonical' => url('archive/' . $year . '/' . str_pad((string)$month, 2, '0', STR_PAD_LEFT)),
                'robots' => 'index,follow',
            ]),
        ]);
    }

    private function sitemap(): string
    {
        if (!headers_sent()) {
            header('Content-Type: application/xml; charset=utf-8');
            header('Cache-Control: public, max-age=900');
        }
        return Seo::sitemap();
    }

    private function robots(): string
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
        }
        return Seo::robots();
    }

    private function feed(): string
    {
        if (!headers_sent()) {
            header('Content-Type: application/rss+xml; charset=utf-8');
            header('Cache-Control: public, max-age=600');
        }
        return Seo::feed(Settings::int('feed_items', 20));
    }

    private function newsletter(): string
    {
        $message = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                \App\Core\Csrf::verify();
                $result = Newsletter::subscribe((string)$_POST['email'], (string)($_POST['name'] ?? ''));
                $message = $result['message'];
            } catch (\Throwable $e) {
                $message = $e->getMessage();
            }
        }
        if (!headers_sent()) {
            $back = $_SERVER['HTTP_REFERER'] ?? url();
            $safe = str_starts_with($back, base_url()) ? $back : url();
            header('Location: ' . $safe . (str_contains($safe, '?') ? '&' : '?') . 'newsletter=' . rawurlencode($message));
            http_response_code(303);
        }
        return '';
    }

    private function commentsSection(array $post): string
    {
        $comments = Comment::forPost((int)$post['id'], Settings::int('comments_per_page', 100));
        $open = Settings::get('allow_comments', '1') === '1' && $post['comment_status'] !== 'closed';
        $notice = (string)($_GET['comment'] ?? '');
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_submit']) && $open) {
            try {
                \App\Core\Csrf::verify();
                Comment::create([
                    'post_id' => (int)$post['id'],
                    'author' => (string)($_POST['author'] ?? ''),
                    'email' => (string)($_POST['email'] ?? ''),
                    'body' => (string)($_POST['body'] ?? ''),
                    'parent_id' => (int)($_POST['parent_id'] ?? 0),
                ]);
                $notice = 'Komentar terkirim dan menunggu moderasi.';
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $out = '<section class="comments" id="comments"><h2>Komentar (' . count($comments) . ')</h2>';
        if ($notice !== '') {
            $out .= '<p class="comment-notice">' . e($notice) . '</p>';
        }
        if (!$comments) {
            $out .= '<p class="comment-empty">Belum ada komentar. Jadilah yang pertama.</p>';
        } else {
            $out .= '<div class="comment-list">';
            foreach ($comments as $comment) {
                $out .= '<article class="comment"><div class="comment-avatar">' . e(mb_substr((string)$comment['author'], 0, 1)) . '</div>'
                    . '<div class="comment-body"><strong>' . e($comment['author']) . '</strong>'
                    . '<time>' . e(Helpers::date((string)$comment['created_at'])) . '</time>'
                    . '<p>' . nl2br(e((string)$comment['body'])) . '</p></div></article>';
            }
            $out .= '</div>';
        }
        if ($open) {
            $out .= '<form class="comment-form" method="post" action="' . e(post_url($post)) . '#comments">' . \App\Core\Csrf::field()
                . '<h3>Tinggalkan komentar</h3>'
                . ($error !== '' ? '<p class="form-error">' . e($error) . '</p>' : '')
                . '<div class="form-row"><input name="author" placeholder="Nama" required maxlength="120">'
                . '<input name="email" type="email" placeholder="Email" required maxlength="190"></div>'
                . '<textarea name="body" rows="5" placeholder="Tulis komentar…" required maxlength="8000"></textarea>'
                . '<button type="submit" name="comment_submit" value="1">Kirim komentar</button></form>';
        } else {
            $out .= '<p class="comment-closed">Komentar ditutup untuk artikel ini.</p>';
        }
        return $out . '</section>';
    }

    private function pager(int $current, int $total): string
    {
        return (new \App\Theme\Ui(Theme::instance(), []))->pagination($current, $total);
    }

    private function notFound(): string
    {
        if (!headers_sent()) {
            http_response_code(404);
        }
        $content = '<div class="error-page"><span class="error-code">404</span><h1>Halaman tidak ditemukan</h1>'
            . '<p>URL mungkin salah, atau konten telah dipindahkan.</p>'
            . '<form class="error-search" action="' . e(url('search')) . '" method="get">'
            . '<input type="search" name="q" placeholder="Cari di blog ini…" aria-label="Cari">'
            . '<button type="submit">Cari</button></form>'
            . '<a class="btn btn-primary" href="' . e(url()) . '">Kembali ke beranda</a></div>';
        return $this->renderTheme([
            'route' => 'not-found',
            'content' => $content,
            'meta' => Seo::meta([
                'title' => 'Halaman tidak ditemukan',
                'robots' => 'noindex,nofollow',
                'canonical' => url(),
            ]),
        ]);
    }

    private function maintenance(): string
    {
        $content = '<div class="error-page"><span class="error-code">503</span><h1>Blog sedang dalam pemeliharaan</h1>'
            . '<p>Kami akan segera kembali. Terima kasih atas kesabaran Anda.</p></div>';
        return $this->renderTheme([
            'route' => 'not-found',
            'content' => $content,
            'meta' => ['title' => 'Pemeliharaan', 'robots' => 'noindex,nofollow', 'canonical' => url()],
        ]);
    }

    private function renderTheme(array $page): string
    {
        \App\Core\Response::securityHeaders();
        $page += ['context' => [], 'breadcrumbs' => [], 'meta' => []];
        return Theme::instance()->render($page);
    }
}
