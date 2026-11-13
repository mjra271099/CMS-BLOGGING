<?php
declare(strict_types=1);

namespace App\Theme;

use App\Core\Context;
use App\Core\Csrf;
use App\Core\Helpers;
use App\Core\Settings;
use App\Core\Str;
use App\Core\Theme;
use App\Core\Widget;

/**
 * Theme UI kit. Every theme layout calls these methods; themes only decide
 * arrangement and CSS, never data access. This keeps themes portable and
 * safe to install from third parties.
 */
final class Ui
{
    public function __construct(
        private Theme $theme,
        private array $page = [],
    ) {
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    public function page(): array
    {
        return $this->page;
    }

    public function route(): string
    {
        return (string)($this->page['route'] ?? '');
    }

    public function setting(string $key, string $default = ''): string
    {
        return Settings::get($key, $default);
    }

    public function title(): string
    {
        return (string)($this->page['meta']['title'] ?? Settings::get('site_name'));
    }

    // ------------------------------------------------------------------
    // Document shell
    // ------------------------------------------------------------------
    public function head(): string
    {
        $meta = (array)($this->page['meta'] ?? []);
        $siteName = Settings::get('site_name', (string)(Context::blog()['name'] ?? 'Blog'));
        $blog = Context::blog();
        $lang = Settings::get('language', (string)($blog['language'] ?? 'id'));
        $bodyClass = trim($this->theme->bodyClasses() . ' ' . (string)($this->page['body_class'] ?? ''));

        $head = '<!doctype html><html lang="' . e($lang) . '" data-theme="' . e($this->theme->slug()) . '">' . "\n<head>";
        $head .= '<meta charset="utf-8">';
        $head .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $head .= '<title>' . e((string)($meta['title'] ?? $siteName)) . '</title>';
        if (!empty($meta['description'])) {
            $head .= '<meta name="description" content="' . e((string)$meta['description']) . '">';
        }
        if (!empty($meta['robots'])) {
            $head .= '<meta name="robots" content="' . e((string)$meta['robots']) . '">';
        }
        if (!empty($meta['canonical'])) {
            $head .= '<link rel="canonical" href="' . e((string)$meta['canonical']) . '">';
        }
        $head .= '<meta property="og:site_name" content="' . e($siteName) . '">';
        $head .= '<meta property="og:title" content="' . e((string)($meta['title'] ?? $siteName)) . '">';
        $head .= '<meta property="og:description" content="' . e((string)($meta['description'] ?? '')) . '">';
        $head .= '<meta property="og:type" content="' . e((string)($meta['type'] ?? 'website')) . '">';
        if (!empty($meta['canonical'])) {
            $head .= '<meta property="og:url" content="' . e((string)$meta['canonical']) . '">';
        }
        if (!empty($meta['image'])) {
            $head .= '<meta property="og:image" content="' . e((string)$meta['image']) . '">';
            $head .= '<meta name="twitter:card" content="summary_large_image">';
            $head .= '<meta name="twitter:image" content="' . e((string)$meta['image']) . '">';
        }
        $head .= '<meta property="og:locale" content="' . e((string)($meta['locale'] ?? 'id_ID')) . '">';
        $head .= '<meta name="twitter:title" content="' . e((string)($meta['title'] ?? $siteName)) . '">';
        $head .= '<meta name="twitter:description" content="' . e((string)($meta['description'] ?? '')) . '">';
        if (!empty($meta['twitter'])) {
            $head .= '<meta name="twitter:site" content="' . e((string)$meta['twitter']) . '">';
        }
        foreach (['google' => 'google_site_verification', 'msvalidate.01' => 'bing_site_verification', 'yandex' => 'yandex_site_verification'] as $name => $key) {
            if (Settings::get($key) !== '') {
                $head .= '<meta name="' . $name . '" content="' . e(Settings::get($key)) . '">';
            }
        }
        $favicon = Settings::get('favicon_url', (string)($blog['favicon'] ?? ''));
        if ($favicon === '') {
            $favicon = url('favicon.ico');
        }
        $head .= '<link rel="icon" href="' . e($favicon) . '">';
        $head .= '<link rel="alternate" type="application/rss+xml" title="' . e($siteName) . ' RSS" href="' . e(url('feed.xml')) . '">';
        $head .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
        $head .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';

        // Theme stylesheets: base (primitives) → system (shared professional
        // design language) → theme (tokens + layout) → customizer (inline).
        $head .= '<link rel="stylesheet" href="' . e(url('assets/front/base.css')) . '">';
        $system = (defined('CMS_ROOT') ? CMS_ROOT : dirname(__DIR__, 2)) . '/assets/front/system.css';
        $systemVersion = is_file($system) ? (int)filemtime($system) : 1;
        $head .= '<link rel="stylesheet" href="' . e(url('assets/front/system.css')) . '?v=' . $systemVersion . '">';
        $css = $this->theme->asset('style.css');
        if ($css !== '') {
            $head .= '<link rel="stylesheet" href="' . e($css) . '">';
        }
        $font = $this->theme->setting('font_pair');
        if ($font !== '' && str_starts_with($font, 'https://')) {
            $head .= '<link rel="stylesheet" href="' . e($font) . '">';
        }
        $custom = $this->theme->customizerCss();
        if (trim($custom) !== '') {
            $head .= '<style id="theme-customizer">' . $custom . '</style>';
        }
        $structured = \App\Services\Seo::structuredData($this->page['meta'] + $this->page);
        $head .= '<script type="application/ld+json">'
            . json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>';
        if (Settings::get('header_code') !== '') {
            $head .= Settings::get('header_code');
        }
        if (Settings::get('analytics_code') !== '') {
            $head .= Settings::get('analytics_code');
        }
        $head .= '</head><body class="' . e($bodyClass) . '">';
        $head .= $this->announcement();
        return $head;
    }

    public function footer(): string
    {
        $out = $this->widgetArea('footer-1', 'footer-col')
            . $this->widgetArea('footer-2', 'footer-col')
            . $this->widgetArea('footer-3', 'footer-col');
        $siteName = Settings::get('site_name', (string)(Context::blog()['name'] ?? 'Blog'));
        $footerText = Settings::get('footer_text', '© ' . date('Y') . ' ' . $siteName . '. Semua hak dilindungi.');
        $html = '<footer class="site-footer"><div class="shell">';
        if (trim($out) !== '') {
            $html .= '<div class="footer-grid">' . $out . '</div>';
        }
        $html .= '<div class="footer-bottom"><p>' . e($footerText) . '</p>';
        $html .= '<p class="footer-links">' . $this->widgetArea('footer-bottom') . '</p></div>';
        $html .= '</div></footer>';
        if (Settings::get('footer_code') !== '') {
            $html .= Settings::get('footer_code');
        }
        $html .= '<script src="' . e(url('assets/front/app.js')) . '" defer></script>';
        $html .= '</body></html>';
        return $html;
    }

    public function announcement(): string
    {
        $widgets = $this->widgetArea('announcement');
        return $widgets !== '' ? '<div class="announcement-bar">' . $widgets . '</div>' : '';
    }

    // ------------------------------------------------------------------
    // Header / navigation
    // ------------------------------------------------------------------
    public function header(): string
    {
        $blog = Context::blog();
        $siteName = Settings::get('site_name', (string)($blog['name'] ?? 'Blog'));
        $tagline = Settings::get('site_description', '');
        $logo = Settings::get('logo_url', (string)($blog['logo'] ?? ''));
        $style = $this->theme->setting('header_style', 'classic');
        $out = '<header class="site-header header-' . e($style) . '"><div class="shell header-inner">';
        $out .= '<div class="brand">';
        if ($logo !== '') {
            $out .= '<a class="brand-logo" href="' . e(url()) . '"><img src="' . e($logo) . '" alt="' . e($siteName) . '"></a>';
        } else {
            $out .= '<a class="brand-mark" href="' . e(url()) . '" aria-label="' . e($siteName) . '">' . e(mb_substr($siteName, 0, 1)) . '</a>';
        }
        $out .= '<div class="brand-text"><a class="brand-name" href="' . e(url()) . '">' . e($siteName) . '</a>';
        if ($tagline !== '' && $style !== 'compact') {
            $out .= '<span class="brand-tagline">' . e($tagline) . '</span>';
        }
        $out .= '</div></div>';
        $out .= $this->navigation();
        $out .= $this->headerActions();
        $out .= '</div></header>';
        return $out;
    }

    public function navigation(): string
    {
        $menu = $this->widgetArea('menu');
        if (trim($menu) === '') {
            $menu = $this->defaultNavigation();
        }
        return '<nav class="site-nav" aria-label="Navigasi utama"><button class="nav-toggle" aria-expanded="false" aria-label="Buka menu">'
            . '<span></span><span></span><span></span></button><div class="nav-panel">' . $menu . '</div></nav>';
    }

    private function defaultNavigation(): string
    {
        $items = [['label' => 'Beranda', 'url' => url()]];
        foreach (\App\Models\Page::all(['status' => 'published']) as $page) {
            $items[] = ['label' => $page['title'], 'url' => page_url($page)];
        }
        $out = '<ul class="menu-list">';
        foreach ($items as $item) {
            $out .= '<li><a href="' . e($item['url']) . '">' . e($item['label']) . '</a></li>';
        }
        return $out . '</ul>';
    }

    public function headerActions(): string
    {
        $out = '<div class="header-actions">';
        $search = $this->widgetArea('search');
        if (trim($search) === '') {
            $out .= '<form class="header-search" action="' . e(url('search')) . '" method="get" role="search">'
                . '<input type="search" name="q" placeholder="Cari…" aria-label="Cari artikel">'
                . '<button type="submit" aria-label="Cari">⌕</button></form>';
        } else {
            $out .= $search;
        }
        $out .= '</div>';
        return $out;
    }

    // ------------------------------------------------------------------
    // Layout areas
    // ------------------------------------------------------------------
    public function widgetArea(string $section, string $extraClass = ''): string
    {
        $widgets = $this->page['context'] ?? [];
        $html = Widget::renderSection($section, $widgets + ['route' => $this->route(), 'slug' => (string)($this->page['slug'] ?? '')]);
        if (trim($html) === '') {
            return '';
        }
        return '<aside class="widget-area ' . e($extraClass) . '" data-area="' . e($section) . '">' . $html . '</aside>';
    }

    public function topBanner(): string
    {
        $banner = $this->widgetArea('top-banner');
        return $banner !== '' ? '<div class="top-banner shell">' . $banner . '</div>' : '';
    }

    /**
     * Full-width call-to-action band composed from footer-about widgets.
     * Themes that use it place it between the main content and the footer.
     */
    public function bottomBand(): string
    {
        $band = $this->widgetArea('footer-about');
        return $band !== '' ? '<section class="bottom-band"><div class="shell">' . $band . '</div></section>' : '';
    }

    public function beforeContent(): string
    {
        return $this->widgetArea('before-content');
    }

    public function afterContent(): string
    {
        return $this->widgetArea('after-content');
    }

    public function sidebar(string $position = 'right'): string
    {
        $content = $this->widgetArea('sidebar');
        if (trim($content) === '') {
            return '';
        }
        return '<div class="sidebar sidebar-' . e($position) . '">' . $content . '</div>';
    }

    public function hasSidebar(): bool
    {
        return Widget::forSection('sidebar') !== [];
    }

    /**
     * Render the main content region (usually $ui->content()).
     */
    public function content(): string
    {
        $inner = (string)($this->page['content'] ?? '');
        $layout = (string)($this->page['layout_mode'] ?? 'standard');
        $supports = $this->theme->supports('sidebar', 'right');
        $sidebarEnabled = !in_array($supports, [false, '', 'none'], true);
        $sidebarVisible = $sidebarEnabled && $this->hasSidebar() && in_array($layout, ['standard'], true);
        $position = $this->theme->setting('sidebar_position', 'right');
        $out = '<main id="main" class="site-main shell">';
        $breadcrumbs = $this->breadcrumbs();
        if ($breadcrumbs !== '') {
            $out .= $breadcrumbs;
        }
        $out .= $this->beforeContent();
        $out .= $this->widgetArea('ad-top');
        if ($sidebarVisible) {
            $out .= '<div class="layout-grid sidebar-' . e($position) . '">';
            if ($position === 'left') {
                $out .= $this->sidebar('left');
            }
            $out .= '<div class="content-column"><div class="content-inner">' . $inner . '</div>'
                . $this->widgetArea('middle-content')
                . $this->widgetArea('ad-bottom') . '</div>';
            if ($position !== 'left') {
                $out .= $this->sidebar($position);
            }
            $out .= '</div>';
        } else {
            $out .= '<div class="content-column full">' . $inner . '</div>';
        }
        $out .= $this->afterContent();
        $out .= '</main>';
        return $out;
    }

    /**
     * Render the content column only. Theme layouts that own the <main>
     * landmark (and their own sidebar/breadcrumb placement) use this instead
     * of content(), which wraps everything in <main>.
     */
    public function contentInner(): string
    {
        $inner = (string)($this->page['content'] ?? '');
        $out = $this->beforeContent();
        $out .= $this->widgetArea('ad-top');
        $out .= '<div class="content-column full"><div class="content-inner">' . $inner . '</div>'
            . $this->widgetArea('middle-content')
            . $this->widgetArea('ad-bottom') . '</div>';
        $out .= $this->afterContent();
        return $out;
    }

    public function hero(): string
    {
        $hero = $this->widgetArea('featured');
        return $hero !== '' ? '<section class="hero-area shell">' . $hero . '</section>' : '';
    }

    public function breadcrumbs(): string
    {
        $crumbs = (array)($this->page['breadcrumbs'] ?? []);
        if ($crumbs === []) {
            return '';
        }
        $out = '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>';
        foreach ($crumbs as $index => $crumb) {
            $out .= '<li>' . ($index < count($crumbs) - 1
                ? '<a href="' . e((string)($crumb['url'] ?? url())) . '">' . e((string)($crumb['name'] ?? '')) . '</a>'
                : '<span aria-current="page">' . e((string)($crumb['name'] ?? '')) . '</span>') . '</li>';
        }
        return $out . '</ol></nav>';
    }

    // ------------------------------------------------------------------
    // Post rendering
    // ------------------------------------------------------------------
    public function postCard(array $post, ?string $variant = null): string
    {
        $image = post_image_url($post);
        $categories = \App\Models\Taxonomy::postCategories((int)$post['id']);
        $author = (string)($post['author_name'] ?? $post['author'] ?? $post['username'] ?? '');
        $variant = $variant ?? $this->theme->setting('card_style', 'card');
        $classes = 'post-card card-' . $variant . (((string)($post['sticky'] ?? '0') === '1') ? ' is-sticky' : '');
        $out = '<article class="' . e($classes) . '">';
        $out .= '<a class="card-media" href="' . e(post_url($post)) . '" aria-hidden="true">';
        $out .= $image !== ''
            ? '<img src="' . e($image) . '" alt="' . e($post['title']) . '" loading="lazy" decoding="async">'
            : '<span class="media-fallback"><span>' . e(mb_substr((string)$post['title'], 0, 1)) . '</span></span>';
        $out .= '</a>';
        $out .= '<div class="card-body">';
        if ($categories) {
            $out .= '<div class="card-labels">';
            foreach (array_slice($categories, 0, 2) as $category) {
                $out .= '<a class="label" href="' . e(category_url($category)) . '">' . e($category['name']) . '</a>';
            }
            $out .= '</div>';
        }
        $out .= '<h2 class="card-title"><a href="' . e(post_url($post)) . '">' . e($post['title']) . '</a></h2>';
        $out .= '<p class="card-excerpt">' . e(excerpt($post, 140)) . '</p>';
        $out .= '<div class="card-meta">';
        $out .= '<span class="meta-author">' . e($author) . '</span>';
        $out .= '<span class="meta-dot" aria-hidden="true">·</span>';
        $out .= '<time datetime="' . e(Helpers::isoDate((string)$post['published_at'])) . '">'
            . e(Helpers::date((string)$post['published_at'])) . '</time>';
        if ((int)($post['reading_time'] ?? 0) > 0) {
            $out .= '<span class="meta-dot" aria-hidden="true">·</span><span class="meta-reading">' . (int)$post['reading_time'] . ' menit</span>';
        }
        $out .= '</div></div></article>';
        return $out;
    }

    public function postGrid(array $posts, string $variant = 'grid'): string
    {
        if ($posts === []) {
            return $this->emptyState('Belum ada artikel yang dipublikasikan.');
        }
        $out = '<div class="post-grid grid-' . e($variant) . '">';
        foreach ($posts as $post) {
            $out .= $this->postCard($post);
        }
        return $out . '</div>';
    }

    public function emptyState(string $message): string
    {
        return '<div class="empty-state"><svg viewBox="0 0 24 24" width="34" height="34" aria-hidden="true"><path fill="currentColor" d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2M9 17H7v-7h2zm4 0h-2V7h2zm4 0h-2v-4h2z"/></svg>'
            . '<p>' . e($message) . '</p></div>';
    }

    public function pagination(int $current, int $total, string $baseQuery = ''): string
    {
        if ($total <= 1) {
            return '';
        }
        $current = max(1, min($current, $total));
        $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        parse_str((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? ''), $query);
        unset($query['page']);
        $link = static function (int $page) use ($path, $query): string {
            $query['page'] = $page;
            return e($path . '?' . http_build_query($query));
        };
        $out = '<nav class="pagination" aria-label="Navigasi halaman">';
        if ($current > 1) {
            $out .= '<a class="page-prev" href="' . $link($current - 1) . '" rel="prev">‹ Sebelumnya</a>';
        }
        $window = 2;
        $start = max(1, $current - $window);
        $end = min($total, $current + $window);
        if ($start > 1) {
            $out .= '<a class="page-num" href="' . $link(1) . '">1</a>';
            if ($start > 2) {
                $out .= '<span class="page-ellipsis">…</span>';
            }
        }
        for ($page = $start; $page <= $end; $page++) {
            $out .= $page === $current
                ? '<span class="page-num current" aria-current="page">' . $page . '</span>'
                : '<a class="page-num" href="' . $link($page) . '">' . $page . '</a>';
        }
        if ($end < $total) {
            if ($end < $total - 1) {
                $out .= '<span class="page-ellipsis">…</span>';
            }
            $out .= '<a class="page-num" href="' . $link($total) . '">' . $total . '</a>';
        }
        if ($current < $total) {
            $out .= '<a class="page-next" href="' . $link($current + 1) . '" rel="next">Berikutnya ›</a>';
        }
        return $out . '</nav>';
    }

    /**
     * Full article rendering for the single post view.
     */
    public function article(array $post): string
    {
        $categories = \App\Models\Taxonomy::postCategories((int)$post['id']);
        $tags = \App\Models\Taxonomy::postTags((int)$post['id']);
        $author = (string)($post['author_name'] ?? $post['author'] ?? $post['username'] ?? '');
        $image = post_image_url($post);
        $out = '<article class="single-article">';
        $out .= '<header class="article-header">';
        if ($categories) {
            $out .= '<div class="article-labels">';
            foreach ($categories as $category) {
                $out .= '<a class="label" href="' . e(category_url($category)) . '">' . e($category['name']) . '</a>';
            }
            $out .= '</div>';
        }
        $out .= '<h1 class="article-title">' . e($post['title']) . '</h1>';
        if ((string)($post['excerpt'] ?? '') !== '') {
            $out .= '<p class="article-lede">' . e((string)$post['excerpt']) . '</p>';
        }
        $out .= '<div class="article-meta"><span class="avatar-initial">' . e(mb_substr($author !== '' ? $author : 'A', 0, 1)) . '</span>'
            . '<span class="meta-block"><strong>' . e($author) . '</strong>'
            . '<span><time datetime="' . e(Helpers::isoDate((string)$post['published_at'])) . '">'
            . e(Helpers::date((string)$post['published_at'], 'd F Y')) . '</time>'
            . ' · ' . max(1, (int)($post['reading_time'] ?? 1)) . ' menit baca</span></span></div>';
        $out .= '</header>';
        if ($image !== '') {
            $out .= '<figure class="article-cover"><img src="' . e($image) . '" alt="' . e($post['title']) . '" loading="eager" decoding="async"></figure>';
        }
        $out .= '<div class="article-body prose" id="article-body">' . (string)$post['body'] . '</div>';
        if ($tags) {
            $out .= '<div class="article-tags">';
            foreach ($tags as $tag) {
                $out .= '<a class="tag" href="' . e(url('search?q=' . rawurlencode((string)$tag['name']))) . '">#' . e($tag['name']) . '</a>';
            }
            $out .= '</div>';
        }
        $out .= $this->shareButtons($post);
        $out .= $this->authorBox($post);
        $out .= '</article>';
        return $out;
    }

    public function shareButtons(array $post): string
    {
        $url = post_url($post);
        $title = (string)$post['title'];
        $encodedUrl = rawurlencode($url);
        $encodedTitle = rawurlencode($title);
        $links = [
            ['name' => 'Facebook', 'href' => 'https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl, 'icon' => 'f'],
            ['name' => 'X', 'href' => 'https://twitter.com/intent/tweet?text=' . $encodedTitle . '&url=' . $encodedUrl, 'icon' => 'X'],
            ['name' => 'WhatsApp', 'href' => 'https://wa.me/?text=' . rawurlencode($title . "\n" . $url), 'icon' => 'W'],
            ['name' => 'Telegram', 'href' => 'https://t.me/share/url?url=' . $encodedUrl . '&text=' . $encodedTitle, 'icon' => 'T'],
            ['name' => 'LinkedIn', 'href' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encodedUrl, 'icon' => 'L'],
            ['name' => 'Pinterest', 'href' => 'https://pinterest.com/pin/create/button/?url=' . $encodedUrl . '&description=' . $encodedTitle, 'icon' => 'P'],
        ];
        $out = '<div class="share-bar"><span class="share-label">Bagikan</span>';
        foreach ($links as $link) {
            $out .= '<a href="' . e($link['href']) . '" target="_blank" rel="noopener noreferrer" title="' . e($link['name']) . '" aria-label="Bagikan ke ' . e($link['name']) . '">'
                . '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="10.5" fill="none" stroke="currentColor"/><text x="12" y="16" text-anchor="middle" font-size="10" font-family="sans-serif" fill="currentColor">'
                . e($link['icon']) . '</text></svg></a>';
        }
        return $out . '</div>';
    }

    public function authorBox(array $post): string
    {
        $name = (string)($post['author_name'] ?? $post['author'] ?? '');
        if ($name === '') {
            return '';
        }
        $bio = (string)($post['author_bio'] ?? '');
        $avatar = (string)($post['author_avatar'] ?? '');
        $out = '<div class="author-box"><div class="author-avatar">';
        $out .= $avatar !== '' ? '<img src="' . e($avatar) . '" alt="' . e($name) . '">' : e(mb_substr($name, 0, 1));
        $out .= '</div><div class="author-info"><h3>' . e($name) . '</h3>';
        $out .= '<p>' . e($bio !== '' ? $bio : 'Penulis di ' . Settings::get('site_name') . '.') . '</p>';
        $out .= '<a href="' . e(Helpers::authorUrl($name)) . '">Lihat semua artikel</a></div></div>';
        return $out;
    }

    /**
     * The whole front page body (excluding head/footer).
     */
    public function body(): string
    {
        $route = $this->route();
        $out = $this->widgetArea('header');
        $out .= $this->header();
        $out .= $this->widgetArea('top-banner');
        if ($route === '' || $route === 'home') {
            $out .= $this->hero();
        }
        $out .= $this->content();
        return $out;
    }
}
