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
        $lang = \App\Core\Lang::htmlLocale();
        $bodyClass = trim($this->theme->bodyClasses() . ' ' . (string)($this->page['body_class'] ?? ''));

        // Cache-bust shared front assets by file mtime so a CSS/JS change is
        // picked up immediately instead of being served from a stale cache.
        $assetVersion = static function (string $name): int {
            $path = (defined('CMS_ROOT') ? CMS_ROOT : dirname(__DIR__, 2)) . '/assets/front/' . $name;
            return is_file($path) ? (int)filemtime($path) : 1;
        };

        $head = '<!doctype html><html lang="' . e($lang) . '" data-theme="' . e($this->theme->slug()) . '">' . "\n<head>";
        $head .= '<meta charset="utf-8">';
        $head .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $head .= '<title>' . e((string)($meta['title'] ?? $siteName)) . '</title>';
        if (!empty($meta['description'])) {
            $head .= '<meta name="description" content="' . e((string)$meta['description']) . '">';
        }
        if (!empty($meta['keywords'])) {
            $head .= '<meta name="keywords" content="' . e((string)$meta['keywords']) . '">';
        }
        if (!empty($meta['robots'])) {
            $head .= '<meta name="robots" content="' . e((string)$meta['robots']) . '">';
        }
        if (!empty($meta['canonical'])) {
            $head .= '<link rel="canonical" href="' . e((string)$meta['canonical']) . '">';
        }
        $head .= '<meta property="og:site_name" content="' . e($siteName) . '">';
        $head .= '<meta property="og:title" content="' . e((string)($meta['title'] ?? $siteName)) . '">';
        if (!empty($meta['description'])) {
            $head .= '<meta property="og:description" content="' . e((string)$meta['description']) . '">';
        }
        $ogType = (string)($meta['type'] ?? 'website');
        $head .= '<meta property="og:type" content="' . e($ogType) . '">';
        if ($ogType === 'article') {
            if (!empty($meta['published'])) {
                $head .= '<meta property="article:published_time" content="' . e((string)$meta['published']) . '">';
            }
            if (!empty($meta['modified'])) {
                $head .= '<meta property="article:modified_time" content="' . e((string)$meta['modified']) . '">';
            }
        }
        if (!empty($meta['canonical'])) {
            $head .= '<meta property="og:url" content="' . e((string)$meta['canonical']) . '">';
        }
        if (!empty($meta['image'])) {
            $head .= '<meta property="og:image" content="' . e((string)$meta['image']) . '">';
            if (!empty($meta['image_width'])) {
                $head .= '<meta property="og:image:width" content="' . (int)$meta['image_width'] . '">';
            }
            if (!empty($meta['image_height'])) {
                $head .= '<meta property="og:image:height" content="' . (int)$meta['image_height'] . '">';
            }
            $head .= '<meta name="twitter:card" content="summary_large_image">';
            $head .= '<meta name="twitter:image" content="' . e((string)$meta['image']) . '">';
        } else {
            // Twitter tetap butuh kartu walau tanpa gambar agar tautan tampil rapi.
            $head .= '<meta name="twitter:card" content="summary">';
        }
        $head .= '<meta property="og:locale" content="' . e((string)($meta['locale'] ?? 'id_ID')) . '">';
        $head .= '<meta name="twitter:title" content="' . e((string)($meta['title'] ?? $siteName)) . '">';
        if (!empty($meta['description'])) {
            $head .= '<meta name="twitter:description" content="' . e((string)$meta['description']) . '">';
        }
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
        // hreflang alternates: emiten di <head> sebagai <link rel="alternate">,
        // bukan di dalam <body>. Di body, penanda ini hanya berada di dalam
        // <a>, yang tidak dibaca mesin pencari sebagai isyarat hreflang.
        foreach (array_keys(\App\Core\Lang::SUPPORTED) as $langCode) {
            $head .= '<link rel="alternate" hreflang="' . e((string)$langCode) . '" href="' . e(url('language/' . $langCode)) . '">';
        }
        $head .= '<link rel="alternate" hreflang="x-default" href="' . e((string)($meta['canonical'] ?? url())) . '">';
        $head .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
        $head .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';

        // Theme stylesheets: base (primitives) → system (shared professional
        // design language) → theme (tokens + layout) → customizer (inline).
        $head .= '<link rel="stylesheet" href="' . e(url('assets/front/base.css')) . '?v=' . $assetVersion('base.css') . '">';
        $head .= '<link rel="stylesheet" href="' . e(url('assets/front/system.css')) . '?v=' . $assetVersion('system.css') . '">';
        $css = $this->theme->asset('style.css');
        if ($css !== '') {
            $head .= '<link rel="stylesheet" href="' . e($css) . '">';
        }
        $font = $this->theme->setting('font_pair');
        if ($font !== '' && str_starts_with($font, 'https://')) {
            // Muat font tanpa memblokir render (menghindari render-blocking CSS
            // pihak ketiga). base.css sudah menyediakan fallback system-font,
            // jadi swap ini tidak menyebabkan layout shift yang berarti.
            $head .= '<link rel="stylesheet" href="' . e($font) . '" media="print" onload="this.media=\'all\'">';
            $head .= '<noscript><link rel="stylesheet" href="' . e($font) . '"></noscript>';
        }        $custom = $this->theme->customizerCss();
        if (trim($custom) !== '') {
            $head .= '<style id="theme-customizer">' . $custom . '</style>';
        }
        $head .= \App\Services\StructuredData::script($this->page);
        $qrAssets = \App\Services\QrPage::headHtml((string)($this->page['template'] ?? ''));
        if ($qrAssets !== '') {
            $head .= $qrAssets;
        }
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
        $html .= $this->scrollTopButton();
        if (Settings::get('footer_code') !== '') {
            $html .= Settings::get('footer_code');
        }
        $appJs = (defined('CMS_ROOT') ? CMS_ROOT : dirname(__DIR__, 2)) . '/assets/front/app.js';
        $appVer = is_file($appJs) ? (int)filemtime($appJs) : 1;
        $html .= '<script src="' . e(url('assets/front/app.js')) . '?v=' . $appVer . '" defer></script>';
        $html .= '</body></html>';
        return $html;
    }

    /**
     * Floating "back to top" button, rendered on single articles only. It is
     * hidden until the reader scrolls; app.js toggles .is-visible and handles
     * the smooth scroll (falling back to an anchor without JS).
     */
    private function scrollTopButton(): string
    {
        if ($this->route() !== 'post') {
            return '';
        }
        return '<button type="button" class="scroll-top" data-scroll-top aria-label="' . e(__('back_to_top')) . '" title="' . e(__('back_to_top')) . '">'
            . '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>'
            . '<span class="scroll-top-label">' . e(__('scroll_top')) . '</span></button>';
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
            $out .= '<a class="brand-logo" href="' . e(url()) . '"><img src="' . e($logo) . '" alt="' . e($siteName) . '" height="34" style="width:auto;max-height:34px;object-fit:contain"></a>';
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
        // The panel carries copies of the search box and the language switch so
        // mobile users reach them from the dropdown; CSS shows each copy only
        // below 860px, keeping a single visible control at any width.
        return '<nav class="site-nav" aria-label="' . e(__('main_navigation')) . '"><button class="nav-toggle" aria-expanded="false" aria-label="' . e(__('open_menu')) . '">'
            . '<span></span><span></span><span></span></button><div class="nav-panel">' . $menu
            . $this->mobileSearch() . $this->languageSwitcher('lang-switch-mobile') . '</div></nav>';
    }

    private function mobileSearch(): string
    {
        $search = $this->widgetArea('search');
        if (trim($search) !== '') {
            // Mark the panel copy so the shared rule can style it, and strip
            // duplicate ids to keep the document unique.
            $tagged = (string)preg_replace('/\bwidget-area\b/', 'widget-area header-search', $search, 1);
            return $this->stripIds($tagged);
        }
        return $this->searchForm(__('search_here'), 'nav-search');
    }

    /**
     * Remove id attributes from a fragment so the same widget can appear in
     * both the header and the mobile panel without duplicate ids.
     */
    private function stripIds(string $html): string
    {
        return (string)preg_replace('/\s+id="[^"]*"/i', '', $html);
    }

    private function defaultNavigation(): string
    {
        $items = [['label' => __('home'), 'url' => url()]];
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
        // The mobile panel already carries a search box (see navigation()),
        // and that widget area is not scoped per location, so rendering the
        // same widget here would duplicate its field ids on the page.
        $search = $this->widgetArea('search');
        $isWidget = trim($search) !== '';
        $out = '<div class="header-actions' . ($isWidget ? '' : ' no-mobile-menu') . '">';
        $out .= $this->languageSwitcher();
        if (!$isWidget) {
            $out .= $this->searchForm(__('search_placeholder'), 'header-search');
        } else {
            // Tag the real widget so CSS can hide it on mobile even if its
            // markup differs from the built-in form. The mobile panel already
            // renders its own copy (see navigation()), so this desktop copy
            // must carry the same class the responsive rule targets, otherwise
            // it stays visible on mobile and overflows the header.
            $tagged = (string)preg_replace('/\bwidget-area\b/', 'widget-area header-search', $search, 1);
            // Hindari kelas ganda bila widget sudah memuat header-search.
            $tagged = (string)preg_replace('/\bheader-search\s+header-search\b/', 'header-search', $tagged, 1);
            $out .= $tagged;
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * Compact ID/EN switch. The choice is stored in a cookie and honoured by
     * App\Core\Lang, so it works for guests without touching blog settings.
     *
     * @param string $extraClass "lang-switch-mobile" marks the copy rendered
     *                           inside the mobile dropdown.
     */
    public function languageSwitcher(string $extraClass = ''): string
    {
        $current = \App\Core\Lang::locale();
        $label = $extraClass === 'lang-switch-mobile' ? __('language') : __('switch_language');
        $out = '<div class="lang-switch' . ($extraClass !== '' ? ' ' . e($extraClass) : '') . '" role="group" aria-label="' . e($label) . '">';
        foreach (\App\Core\Lang::SUPPORTED as $code => $optionLabel) {
            $out .= '<a class="lang-option' . ($code === $current ? ' is-active' : '') . '" rel="alternate" hreflang="' . e($code) . '"'
                . ' href="' . e(url('language/' . $code)) . '" aria-current="' . ($code === $current ? 'true' : 'false') . '">'
                . e(strtoupper($code)) . '</a>';
        }
        return $out . '</div>';
    }

    private function searchForm(string $placeholder, string $extraClass = ''): string
    {
        // Terima $extraClass yang menambahkan kelas; bila sama dengan kelas
        // dasar (header-search), jangan hasilkan token kelas ganda.
        $classes = 'header-search';
        if ($extraClass !== '' && $extraClass !== 'header-search') {
            $classes .= ' ' . e($extraClass);
        }
        return '<form class="' . $classes . '" action="' . e(url('search')) . '" method="get" role="search">'
            . '<input type="search" name="q" placeholder="' . e($placeholder) . '" aria-label="' . e(__('search_articles')) . '">'
            . '<button type="submit" aria-label="' . e(__('search')) . '">⌕</button></form>';
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
        // Visibilitas diperhitungkan: sidebar hanya dianggap ada bila setidaknya
        // satu widget lolos filter halaman/perangkat/peran untuk konteks ini,
        // sehingga tidak menyisakan kolom sidebar kosong.
        $context = ($this->page['context'] ?? []) + [
            'route' => $this->route(),
            'slug' => (string)($this->page['slug'] ?? ''),
        ];
        foreach (Widget::forSection('sidebar') as $widget) {
            if (Widget::isVisible($widget, $context)) {
                return true;
            }
        }
        return false;
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
        $out .= $this->widgetArea('main');
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
        $out .= $this->widgetArea('main');
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

    /**
     * Wrap every table in a horizontal scroll container.
     *
     * A wide table (many columns, or a cell min-width) can be wider than the
     * article column; without a scroll wrapper it would push the whole page
     * sideways on narrow screens. The wrapper keeps the table a real <table>
     * while confining any overflow to the wrapper itself.
     */
    public static function wrapTables(string $html): string
    {
        if (stripos($html, '<table') === false) {
            return $html;
        }
        // Tables do not nest, so a non-greedy match from <table> to </table>
        // is safe. Wrap only tables that are not already wrapped.
        return (string)preg_replace_callback(
            '#(<table\b[^>]*>.*?</table>)#is',
            static function (array $m): string {
                return '<div class="table-scroll">' . $m[1] . '</div>';
            },
            $html
        );
    }

    /** Kategori per post, di-cache per request agar tidak query berulang. */
    private static array $cardCategoryCache = [];

    /** Kategori untuk satu post (memakai cache bila sudah dimuat batch). */
    private static function categoriesForPost(int $postId): array
    {
        if (array_key_exists($postId, self::$cardCategoryCache)) {
            return self::$cardCategoryCache[$postId];
        }
        return self::$cardCategoryCache[$postId] = \App\Models\Taxonomy::postCategories($postId);
    }

    /**
     * Muat kategori untuk sekumpulan post dalam SATU query, lalu isi cache per
     * post. Menghindari N+1 pada grid/daftar artikel.
     *
     * @param array<int,array> $posts
     */
    private static function primeCategories(array $posts): void
    {
        $ids = [];
        foreach ($posts as $post) {
            $id = (int)($post['id'] ?? 0);
            if ($id > 0 && !array_key_exists($id, self::$cardCategoryCache)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return;
        }
        $byPost = \App\Models\Taxonomy::categoriesForPosts($ids);
        foreach ($ids as $id) {
            self::$cardCategoryCache[$id] = $byPost[$id] ?? [];
        }
    }

    public function postCard(array $post, ?string $variant = null): string
    {
        $image = post_image_url($post);
        $categories = self::categoriesForPost((int)$post['id']);
        $author = (string)($post['author_name'] ?? $post['author'] ?? $post['username'] ?? '');
        $variant = $variant ?? $this->theme->setting('card_style', 'card');
        $classes = 'post-card card-' . $variant . (((string)($post['sticky'] ?? '0') === '1') ? ' is-sticky' : '');
        $out = '<article class="' . e($classes) . '">';
        // The cover is decorative in the a11y tree; the title link below is
        // stretched over the whole card (see `.card-title a::after`), so the
        // image and every empty area still open the post without nesting links.
        $out .= '<div class="card-media" aria-hidden="true">';
        $out .= $image !== ''
            ? '<img src="' . e($image) . '" alt="" loading="lazy" decoding="async">'
            : '<span class="media-fallback"><span>' . e(mb_substr((string)$post['title'], 0, 1)) . '</span></span>';
        $out .= '</div>';
        $out .= '<div class="card-body">';
        if ($categories) {
            // Category chips sit above the stretched title link so they open
            // the archive instead of the post.
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
            $out .= '<span class="meta-dot" aria-hidden="true">·</span><span class="meta-reading">' . e(__('min_read', ['minutes' => max(1, (int)$post['reading_time'])])) . '</span>';
        }
        $out .= '</div></div></article>';
        return $out;
    }

    public function postGrid(array $posts, string $variant = 'grid'): string
    {
        if ($posts === []) {
            return $this->emptyState(__('no_posts'));
        }
        // Satu query untuk semua kategori kartu (hindari N+1).
        self::primeCategories($posts);
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
        $out = '<nav class="pagination" aria-label="' . e(__('pagination')) . '">';
        if ($current > 1) {
            $out .= '<a class="page-prev" href="' . $link($current - 1) . '" rel="prev">' . e(__('previous_page')) . '</a>';
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
            $out .= '<a class="page-next" href="' . $link($current + 1) . '" rel="next">' . e(__('next_page')) . '</a>';
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
            . ' · ' . e(__('min_read', ['minutes' => max(1, (int)($post['reading_time'] ?? 1))])) . '</span></span></div>';
        $out .= '</header>';
        if ($image !== '') {
            $out .= '<figure class="article-cover"><img src="' . e($image) . '" alt="' . e($post['title']) . '" loading="eager" fetchpriority="high"></figure>';
        }
        $out .= '<div class="article-body prose" id="article-body">' . self::wrapTables((string)$post['body']) . '</div>';
        if ($tags) {
            $out .= '<div class="article-tags">';
            foreach ($tags as $tag) {
                $out .= '<a class="tag" href="' . e(url('tag/' . rawurlencode((string)($tag['slug'] ?? $tag['name'])))) . '">#' . e($tag['name']) . '</a>';
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
        $out = '<div class="share-bar"><span class="share-label">' . e(__('share')) . '</span>';
        foreach ($links as $link) {
            $out .= '<a href="' . e($link['href']) . '" target="_blank" rel="noopener noreferrer" title="' . e($link['name']) . '" aria-label="' . e(__('share') . ' → ' . $link['name']) . '">'
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
        $out .= $avatar !== '' ? '<img src="' . e($avatar) . '" alt="' . e($name) . '" width="44" height="44" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover">' : e(mb_substr($name, 0, 1));
        $out .= '</div><div class="author-info"><h3>' . e($name) . '</h3>';
        $out .= '<p>' . e($bio !== '' ? $bio : __('author_bio', ['site' => Settings::get('site_name')])) . '</p>';
        $out .= '<a href="' . e(Helpers::authorUrl($name)) . '">' . e(__('view_all_articles')) . '</a></div></div>';
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
