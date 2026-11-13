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
            'language' => $this->language($route['params']),
            'tag' => $this->tag($route['params']),
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
            // Legacy Blogger numeric permalink: /?p=<id> resolves to a post.
            $numeric = (int)($_GET['p'] ?? 0);
            if ($numeric > 0) {
                return ['name' => 'post', 'params' => ['id' => $numeric]];
            }
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
        if (preg_match('~^language/([a-z]{2})$~', $path, $match)) {
            return ['name' => 'language', 'params' => ['code' => $match[1]]];
        }
        // Legacy Blogger numeric permalinks on any path: ?p=<id>.
        $numeric = (int)($_GET['p'] ?? 0);
        if ($numeric > 0) {
            return ['name' => 'post', 'params' => ['id' => $numeric]];
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
        if (preg_match('~^label/([^/]+)$~', $path, $match)) {
            return ['name' => 'category', 'params' => ['slug' => urldecode($match[1])]];
        }
        if (preg_match('~^tag/([^/]+)$~', $path, $match)) {
            return ['name' => 'tag', 'params' => ['slug' => urldecode($match[1])]];
        }
        if (preg_match('~^author/([^/]+)$~', $path, $match)) {
            return ['name' => 'author', 'params' => ['slug' => urldecode($match[1])]];
        }
        if ($path === 'archive' || $path === 'arsip') {
            return ['name' => 'archive', 'params' => []];
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
        $content .= '<div class="section-heading"><h2>' . e(__('latest_articles')) . '</h2>'
            . '<a class="section-link" href="' . e(url('archive')) . '">' . e(__('full_archive')) . ' →</a></div>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $outOfRange = $this->pageOutOfRange($current, $perPage, $total);
        if ($outOfRange !== null) { return $outOfRange; }
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
        $id = (int)($params['id'] ?? 0);
        $preview = (string)($_GET['preview'] ?? '') === '1' && Auth::check();
        $post = $id > 0 ? Post::find($id) : Post::findBySlug($slug, null, $preview);
        if ($post && !$preview && ($post['status'] !== 'published' || ($post['published_at'] ?? null) > date('Y-m-d H:i:s'))) {
            $post = null;
        }
        if (!$post) {
            return $this->notFound();
        }
        // Legacy numeric permalinks canonicalise to the pretty URL.
        if ($id > 0 && !$preview && !headers_sent()) {
            header('Location: ' . post_url($post), true, 301);
            return '';
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
            $content .= '<section class="related-section"><h2>' . e(__('related_articles')) . '</h2>';
            $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($related, 'grid');
            $content .= '</section>';
        }
        $categories = Taxonomy::postCategories((int)$post['id']);
        $breadcrumbs = [['name' => __('home'), 'url' => url()]];
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
        $breadcrumbs = [['name' => __('home'), 'url' => url()], ['name' => $page['title'], 'url' => page_url($page)]];
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
            // Blogger treats /search/label/<x> as a catch-all for categories
            // and post labels, so fall back to the tag of the same slug.
            $tag = Taxonomy::tagBySlug($slug);
            if ($tag) {
                return $this->tag(['slug' => $slug]);
            }
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
        $content = '<header class="archive-header"><span class="eyebrow">' . e(__('category')) . '</span><h1>' . e($category['name']) . '</h1>';
        if (!empty($category['description'])) {
            $content .= '<p>' . e((string)$category['description']) . '</p>';
        }
        $content .= '<span class="archive-count">' . $total . ' ' . e(__('articles')) . '</span></header>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $outOfRange = $this->pageOutOfRange($current, $perPage, $total);
        if ($outOfRange !== null) { return $outOfRange; }
        $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        $breadcrumbs = [['name' => __('home'), 'url' => url()], ['name' => $category['name'], 'url' => category_url($category)]];
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

    private function tag(array $params): string
    {
        $slug = trim((string)($params['slug'] ?? ''));
        if ($slug === '') {
            return $this->search();
        }
        // Tags share the archive shell with categories.
        $tag = Taxonomy::tagBySlug($slug);
        if (!$tag) {
            return $this->notFound();
        }
        $perPage = max(1, min(50, Settings::int('posts_per_page', 9)));
        $current = max(1, (int)($_GET['page'] ?? 1));
        $filters = ['status' => 'published', 'tag_id' => (int)$tag['id']];
        $posts = Post::query($filters, $perPage, ($current - 1) * $perPage);
        $total = Post::count($filters);
        $label = mb_convert_case((string)$tag['name'], MB_CASE_TITLE, 'UTF-8');
        $content = '<header class="archive-header"><span class="eyebrow">' . e(__('tag')) . '</span><h1>' . e($label) . '</h1>';
        if (!empty($tag['description'])) {
            $content .= '<p>' . e((string)$tag['description']) . '</p>';
        }
        $content .= '<span class="archive-count">' . $total . ' ' . e(__('articles')) . '</span></header>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $outOfRange = $this->pageOutOfRange($current, $perPage, $total);
        if ($outOfRange !== null) { return $outOfRange; }
        $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        return $this->renderTheme([
            'route' => 'tag',
            'slug' => $slug,
            'content' => $content,
            'breadcrumbs' => [['name' => __('home'), 'url' => url()], ['name' => $label, 'url' => url('tag/' . rawurlencode($slug))]],
            'meta' => Seo::meta([
                'title' => $label,
                'description' => __('tag') . ' ' . $label,
                'canonical' => url('tag/' . rawurlencode($slug)),
                'robots' => 'index,follow',
            ]),
        ]);
    }

    /**
     * Persist the visitor language choice and return them to the page they
     * came from (falling back to the home page).
     */
    private function language(array $params): string
    {
        $code = \App\Core\Lang::setLocale((string)($params['code'] ?? 'id'));
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $target = url();
        if ($referer !== '') {
            $host = (string)(parse_url($referer, PHP_URL_HOST) ?? '');
            $self = (string)(parse_url(base_url(), PHP_URL_HOST) ?? '');
            if ($host !== '' && $host === $self) {
                $target = $referer;
            }
        }
        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        }
        return '';
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
            . '<input type="search" name="q" value="' . e($term) . '" placeholder="' . e(__('search_keywords')) . '" aria-label="' . e(__('keywords')) . '" autofocus>'
            . '<button type="submit">' . e(__('search')) . '</button></form>';
        if ($term === '') {
            $content .= (new \App\Theme\Ui(Theme::instance(), []))->emptyState(__('enter_keyword'));
        } else {
            $content .= '<p class="search-summary">' . e(__('results_for', ['total' => $total, 'term' => $term])) . '</p>';
            $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'list');
        $outOfRange = $this->pageOutOfRange($current, $perPage, $total);
        if ($outOfRange !== null) { return $outOfRange; }
            $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        }
        $content .= '</div>';
        return $this->renderTheme([
            'route' => 'search',
            'content' => $content,
            'context' => ['route' => 'search'],
            'meta' => Seo::meta([
                'title' => $term !== '' ? __('search_results') . ': ' . $term : __('searching'),
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
            if (\App\Core\Slug::make($name) === $slug) {
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
            . '<span class="eyebrow">' . e(__('author')) . '</span><h1>' . e($name) . '</h1>'
            . '<p>' . e((string)($author['bio'] ?: __('author_bio', ['site' => Settings::get('site_name')]))) . '</p>'
            . '<span class="archive-count">' . $total . ' ' . e(__('articles')) . '</span></header>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $outOfRange = $this->pageOutOfRange($current, $perPage, $total);
        if ($outOfRange !== null) { return $outOfRange; }
        $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        $breadcrumbs = [['name' => __('home'), 'url' => url()], ['name' => $name, 'url' => Helpers::authorUrl($name)]];
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
        if ($year <= 0 || $month < 1 || $month > 12) {
            return $this->archiveIndex($current, $perPage);
        }
        $filters = ['status' => 'published'];
        $monthKey = sprintf('%04d-%02d', $year, $month);
        $posts = Post::query($filters + ['month' => $monthKey], $perPage, ($current - 1) * $perPage);
        $total = Post::count($filters + ['month' => $monthKey]);
        $label = \App\Core\Helpers::date(date('Y-m-d H:i:s', mktime(0, 0, 0, $month, 1, $year)), 'F Y');
        $content = '<header class="archive-header"><span class="eyebrow">' . e(__('archive')) . '</span><h1>' . e($label) . '</h1>'
            . '<span class="archive-count">' . $total . ' ' . e(__('articles')) . '</span></header>';
        $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $outOfRange = $this->pageOutOfRange($current, $perPage, $total);
        if ($outOfRange !== null) { return $outOfRange; }
        $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        return $this->renderTheme([
            'route' => 'archive',
            'content' => $content,
            'meta' => Seo::meta([
                'title' => __('archive') . ' ' . $label,
                'description' => __('archive') . ' ' . $label,
                'canonical' => url('archive/' . $year . '/' . str_pad((string)$month, 2, '0', STR_PAD_LEFT)),
                'robots' => 'index,follow',
            ]),
        ]);
    }

    /**
     * Month-by-month archive index (/archive). Lists every month that has
     * published posts, newest first, plus the latest posts below it.
     */
    private function archiveIndex(int $current, int $perPage): string
    {
        $rows = Database::fetchAll(
            "SELECT DATE_FORMAT(published_at, '%Y-%m') AS ym, COUNT(*) AS total
             FROM posts WHERE blog_id = ? AND status = 'published' AND published_at IS NOT NULL
             GROUP BY ym ORDER BY ym DESC",
            [Context::blogId()]
        );
        $groups = [];
        foreach ($rows as $row) {
            $ym = (string)$row['ym'];
            [$y, $m] = array_map('intval', explode('-', $ym) + [0, 0]);
            $groups[$y][] = ['month' => $m, 'total' => (int)$row['total'], 'url' => url('archive/' . $y . '/' . str_pad((string)$m, 2, '0', STR_PAD_LEFT))];
        }
        $content = '<header class="archive-header"><span class="eyebrow">' . e(__('archive')) . '</span><h1>' . e(__('all_articles')) . '</h1>'
            . '<span class="archive-count">' . (int)Post::count(['status' => 'published']) . ' ' . e(__('articles')) . '</span></header>';
        if ($groups === []) {
            $content .= (new \App\Theme\Ui(Theme::instance(), []))->emptyState(__('no_posts'));
        } else {
            $content .= '<div class="archive-months">';
            foreach ($groups as $y => $months) {
                $content .= '<section class="archive-year"><h2>' . (int)$y . '</h2><ul class="archive-month-list">';
                foreach ($months as $entry) {
                    $label = \App\Core\Helpers::date(date('Y-m-d H:i:s', mktime(0, 0, 0, $entry['month'], 1, (int)$y)), 'F');
                    $content .= '<li><a href="' . e($entry['url']) . '">' . e($label) . '</a>'
                        . '<span class="pill">' . (int)$entry['total'] . '</span></li>';
                }
                $content .= '</ul></section>';
            }
            $content .= '</div>';
            $posts = Post::query(['status' => 'published'], $perPage, ($current - 1) * $perPage);
            $total = Post::count(['status' => 'published']);
            $content .= '<div class="section-heading"><h2>' . e(__('latest_articles')) . '</h2></div>';
            $content .= (new \App\Theme\Ui(Theme::instance(), []))->postGrid($posts, 'grid');
        $outOfRange = $this->pageOutOfRange($current, $perPage, $total);
        if ($outOfRange !== null) { return $outOfRange; }
            $content .= $this->pager($current, (int)ceil(max(1, $total) / $perPage));
        }
        return $this->renderTheme([
            'route' => 'archive',
            'content' => $content,
            'meta' => Seo::meta([
                'title' => __('archive') . ' — ' . Settings::get('site_name'),
                'description' => __('archive') . ' ' . Settings::get('site_name'),
                'canonical' => url('archive'),
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
        $code = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                \App\Core\Csrf::verify();
                $result = Newsletter::subscribe((string)$_POST['email'], (string)($_POST['name'] ?? ''));
                $code = (string)$result['message'];
            } catch (\Throwable $e) {
                $code = 'newsletter_error';
            }
        }
        if (!headers_sent()) {
            $back = $_SERVER['HTTP_REFERER'] ?? url();
            $safe = str_starts_with($back, base_url()) ? $back : url();
            // Strip any old status, keep other query params intact.
            $parts = parse_url($safe);
            parse_str((string)($parts['query'] ?? ''), $query);
            $query['newsletter'] = $code;
            $target = ($parts['scheme'] ?? '') !== '' ? ($parts['scheme'] . '://' . ($parts['host'] ?? '')) : '';
            $basePath = (string)($parts['path'] ?? '/');
            header('Location: ' . ($target !== '' ? $target : '') . $basePath . ($query !== [] ? '?' . http_build_query($query) : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : ''), true, 303);
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
                $moderated = (bool)Settings::get('comment_moderation', '1');
                $notice = $moderated ? __('comment_pending') : __('comment_thanks');
                // Refresh the list so an auto-approved comment shows at once.
                $comments = Comment::forPost((int)$post['id'], Settings::int('comments_per_page', 100));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $out = '<section class="comments" id="comments"><h2>' . e(__('comments')) . ' (' . count($comments) . ')</h2>';
        if ($notice !== '') {
            $out .= '<p class="comment-notice">' . e($notice) . '</p>';
        }
        if (!$comments) {
            $out .= '<p class="comment-empty">' . e(__('no_comments')) . '</p>';
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
                . '<h3>' . e(__('leave_comment')) . '</h3>'
                . ($error !== '' ? '<p class="form-error">' . e($error) . '</p>' : '')
                . '<div class="form-row"><input name="author" placeholder="' . e(__('comment_name')) . '" required maxlength="120">'
                . '<input name="email" type="email" placeholder="' . e(__('comment_email')) . '" required maxlength="190"></div>'
                . '<textarea name="body" rows="5" placeholder="' . e(__('comment_body')) . '…" required maxlength="8000"></textarea>'
                . '<button type="submit" name="comment_submit" value="1">' . e(__('post_comment')) . '</button></form>';
        } else {
            $out .= '<p class="comment-closed">' . e(__('comment_closed')) . '</p>';
        }
        return $out . '</section>';
    }

    private function pager(int $current, int $total): string
    {
        return (new \App\Theme\Ui(Theme::instance(), []))->pagination($current, $total);
    }

    /**
     * Returns a 404 body when the requested page is past the last one, so
     * /page/999 and ?page=999 never render an empty listing with status 200.
     *
     * @return string|null null when the page is in range or unpaginated
     */
    private function pageOutOfRange(int $current, int $perPage, int $total): ?string
    {
        if ($total <= 0) {
            return null;
        }
        $totalPages = (int)ceil($total / max(1, $perPage));
        return $current > $totalPages ? $this->notFound() : null;
    }

    private function notFound(): string
    {
        if (!headers_sent()) {
            http_response_code(404);
        }
        $content = '<div class="error-page"><span class="error-code">404</span><h1>' . e(__('not_found_title')) . '</h1>'
            . '<p>' . e(__('not_found_text')) . '</p>'
            . '<form class="error-search" action="' . e(url('search')) . '" method="get">'
            . '<input type="search" name="q" placeholder="' . e(__('not_found_search')) . '" aria-label="' . e(__('search')) . '">'
            . '<button type="submit">' . e(__('search')) . '</button></form>'
            . '<a class="btn btn-primary" href="' . e(url()) . '">' . e(__('back_to_home')) . '</a></div>';
        return $this->renderTheme([
            'route' => 'not-found',
            'content' => $content,
            'meta' => Seo::meta([
                'title' => __('not_found_title'),
                'robots' => 'noindex,nofollow',
                'canonical' => url(),
            ]),
        ]);
    }

    private function maintenance(): string
    {
        $content = '<div class="error-page"><span class="error-code">503</span><h1>' . e(__('maintenance_title')) . '</h1>'
            . '<p>' . e(__('maintenance_text')) . '</p></div>';
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
