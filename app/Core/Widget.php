<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Taxonomy;

/**
 * Widget engine: registry, visibility resolution, ordering and rendering.
 * Widgets live in the `widgets` table per blog and per layout area.
 */
final class Widget
{
    private static ?array $cache = null;
    private static ?array $runtime = null;

    /**
     * Type registry. `fields` drives the dynamic settings form in the admin.
     */
    public static function types(): array
    {
        return [
            'PopularPosts' => [
                'label' => 'Postingan Populer',
                'icon' => 'trending',
                'description' => 'Artikel dengan kunjungan terbanyak.',
                'fields' => [
                    'numItemsToShow' => ['type' => 'number', 'label' => 'Jumlah item', 'default' => 5, 'min' => 1, 'max' => 20],
                    'showThumbnails' => ['type' => 'checkbox', 'label' => 'Tampilkan thumbnail', 'default' => 1],
                    'showSnippets' => ['type' => 'checkbox', 'label' => 'Tampilkan ringkasan', 'default' => 0],
                    'timeRange' => ['type' => 'select', 'label' => 'Rentang waktu', 'default' => 'all', 'options' => ['all' => 'Semua waktu', '30d' => '30 hari', '7d' => '7 hari']],
                ],
            ],
            'RecentPosts' => [
                'label' => 'Postingan Terbaru',
                'icon' => 'clock',
                'description' => 'Daftar artikel terbaru.',
                'fields' => [
                    'numItemsToShow' => ['type' => 'number', 'label' => 'Jumlah item', 'default' => 5, 'min' => 1, 'max' => 20],
                    'showThumbnails' => ['type' => 'checkbox', 'label' => 'Tampilkan thumbnail', 'default' => 1],
                    'showDate' => ['type' => 'checkbox', 'label' => 'Tampilkan tanggal', 'default' => 1],
                ],
            ],
            'FeaturedPosts' => [
                'label' => 'Postingan Unggulan',
                'icon' => 'star',
                'description' => 'Artikel bertanda unggulan.',
                'fields' => [
                    'numItemsToShow' => ['type' => 'number', 'label' => 'Jumlah item', 'default' => 3, 'min' => 1, 'max' => 12],
                    'layout' => ['type' => 'select', 'label' => 'Gaya', 'default' => 'list', 'options' => ['list' => 'Daftar', 'cards' => 'Kartu', 'carousel' => 'Carousel horizontal']],
                ],
            ],
            'FeaturedCategory' => [
                'label' => 'Unggulan per Kategori',
                'icon' => 'tag',
                'description' => 'Artikel terbaru dari satu kategori.',
                'fields' => [
                    'category' => ['type' => 'select', 'label' => 'Kategori', 'source' => 'categories', 'default' => ''],
                    'numItemsToShow' => ['type' => 'number', 'label' => 'Jumlah item', 'default' => 3, 'min' => 1, 'max' => 12],
                    'layout' => ['type' => 'select', 'label' => 'Gaya', 'default' => 'list', 'options' => ['list' => 'Daftar', 'cards' => 'Kartu', 'carousel' => 'Carousel horizontal']],
                    'showMore' => ['type' => 'checkbox', 'label' => 'Tombol "Lihat semua" (gaya kartu)', 'default' => 1],
                ],
            ],
            'Label' => [
                'label' => 'Label / Kategori',
                'icon' => 'tag',
                'description' => 'Daftar kategori blog.',
                'fields' => [
                    'display' => ['type' => 'select', 'label' => 'Tampilan', 'default' => 'list', 'options' => ['list' => 'Daftar', 'cloud' => 'Cloud', 'chips' => 'Chips']],
                    'showCount' => ['type' => 'checkbox', 'label' => 'Tampilkan jumlah', 'default' => 1],
                    'sorting' => ['type' => 'select', 'label' => 'Urutan', 'default' => 'name', 'options' => ['name' => 'Nama', 'count' => 'Jumlah']],
                ],
            ],
            'BlogSearch' => [
                'label' => 'Pencarian',
                'icon' => 'search',
                'description' => 'Form pencarian artikel.',
                'fields' => ['placeholder' => ['type' => 'text', 'label' => 'Placeholder', 'default' => 'Cari artikel…']],
            ],
            'Archive' => [
                'label' => 'Arsip',
                'icon' => 'archive',
                'description' => 'Arsip bulanan/tahunan.',
                'fields' => [
                    'style' => ['type' => 'select', 'label' => 'Gaya', 'default' => 'list', 'options' => ['list' => 'Daftar', 'dropdown' => 'Dropdown', 'flat' => 'Flat']],
                ],
            ],
            'AuthorProfile' => [
                'label' => 'Profil Penulis',
                'icon' => 'user',
                'description' => 'Kartu profil penulis.',
                'fields' => [
                    'userId' => ['type' => 'number', 'label' => 'ID penulis', 'default' => 0],
                    'showBio' => ['type' => 'checkbox', 'label' => 'Tampilkan bio', 'default' => 1],
                    'showSocial' => ['type' => 'checkbox', 'label' => 'Tampilkan sosial', 'default' => 1],
                ],
            ],
            'Social' => [
                'label' => 'Media Sosial',
                'icon' => 'share',
                'description' => 'Tautan sosial dengan ikon.',
                'fields' => [
                    'style' => ['type' => 'select', 'label' => 'Gaya', 'default' => 'icons', 'options' => ['icons' => 'Ikon', 'buttons' => 'Tombol', 'list' => 'Daftar']],
                ],
            ],
            'HTML' => [
                'label' => 'HTML Kustom',
                'icon' => 'code',
                'description' => 'Konten HTML bebas (dipercaya admin).',
                'fields' => [],
            ],
            'Text' => [
                'label' => 'Teks',
                'icon' => 'text',
                'description' => 'Teks atau HTML ringan.',
                'fields' => [],
            ],
            'Image' => [
                'label' => 'Gambar',
                'icon' => 'image',
                'description' => 'Gambar dengan tautan opsional.',
                'fields' => [
                    'src' => ['type' => 'image', 'label' => 'URL gambar', 'default' => ''],
                    'href' => ['type' => 'text', 'label' => 'URL tautan', 'default' => ''],
                    'caption' => ['type' => 'text', 'label' => 'Keterangan', 'default' => ''],
                ],
            ],
            'Ad' => [
                'label' => 'Iklan',
                'icon' => 'megaphone',
                'description' => 'Slot iklan (HTML/JS admin).',
                'fields' => ['label' => ['type' => 'text', 'label' => 'Label slot', 'default' => 'Iklan']],
            ],
            'Newsletter' => [
                'label' => 'Newsletter',
                'icon' => 'mail',
                'description' => 'Form berlangganan email.',
                'fields' => [
                    'button' => ['type' => 'text', 'label' => 'Teks tombol', 'default' => 'Berlangganan'],
                    'note' => ['type' => 'text', 'label' => 'Catatan', 'default' => 'Tanpa spam. Berhenti kapan saja.'],
                ],
            ],
            'RelatedPosts' => [
                'label' => 'Artikel Terkait',
                'icon' => 'link',
                'description' => 'Artikel terkait kategori yang sama.',
                'fields' => ['numItemsToShow' => ['type' => 'number', 'label' => 'Jumlah item', 'default' => 3, 'min' => 1, 'max' => 9]],
            ],
            'TableOfContents' => [
                'label' => 'Daftar Isi',
                'icon' => 'list',
                'description' => 'Daftar isi otomatis dari heading artikel.',
                'fields' => ['depth' => ['type' => 'number', 'label' => 'Kedalaman heading', 'default' => 3, 'min' => 1, 'max' => 6]],
            ],
            'LinkList' => [
                'label' => 'Daftar Tautan',
                'icon' => 'link',
                'description' => 'Tautan kustom.',
                'fields' => [],
            ],
            'PageList' => [
                'label' => 'Daftar Halaman',
                'icon' => 'file',
                'description' => 'Halaman statis blog.',
                'fields' => [],
            ],
            'Menu' => [
                'label' => 'Menu Navigasi',
                'icon' => 'menu',
                'description' => 'Menu yang dibuat di Menu Builder.',
                'fields' => [
                    'location' => ['type' => 'text', 'label' => 'Lokasi menu', 'default' => 'primary'],
                ],
            ],
            'TagCloud' => [
                'label' => 'Tag Cloud',
                'icon' => 'tags',
                'description' => 'Awan tag.',
                'fields' => ['limit' => ['type' => 'number', 'label' => 'Jumlah tag', 'default' => 20, 'min' => 3, 'max' => 60]],
            ],
            'Stats' => [
                'label' => 'Statistik Blog',
                'icon' => 'chart',
                'description' => 'Jumlah artikel, kategori, komentar.',
                'fields' => [],
            ],
            'Ticker' => [
                'label' => 'Ticker / Marquee',
                'icon' => 'activity',
                'description' => 'Teks berjalan untuk headline.',
                'fields' => ['speed' => ['type' => 'number', 'label' => 'Kecepatan (detik)', 'default' => 28, 'min' => 8, 'max' => 80]],
            ],
        ];
    }

    public static function renderSection(string $section, array $context = []): string
    {
        $widgets = self::forSection($section);
        $output = '';
        foreach ($widgets as $widget) {
            if (!self::isVisible($widget, $context)) {
                continue;
            }
            $uniqueId = 'widget-' . (int)$widget['id'];
            $body = self::render($widget, $context);
            // Mark widgets whose header carries a trailing action so the
            // stylesheet can float it to the right of the title.
            $hasAction = str_contains($body, 'class="featured-more"');
            $output .= '<div id="' . $uniqueId . '" class="widget widget-' . strtolower((string)$widget['type'])
                . ' area-' . e($section) . ($hasAction ? ' has-widget-action' : '') . '">';
            $output .= $body;
            $output .= '</div>';
        }
        return $output;
    }

    public static function forSection(string $section): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(array $widget): bool => $widget['section_id'] === $section && (int)$widget['enabled'] === 1
        ));
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $rows = Database::run(
                'SELECT * FROM widgets WHERE blog_id = ? ORDER BY section_id, sort_order, id',
                [Context::blogId()]
            )->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['enabled'] = (int)$row['enabled'];
            $row['sort_order'] = (int)$row['sort_order'];
            $row['config'] = self::config($row);
        }
        unset($row);
        return self::$cache = $rows;
    }

    public static function flush(): void
    {
        self::$cache = null;
        self::$runtime = null;
    }

    public static function find(int $id): ?array
    {
        foreach (self::all() as $widget) {
            if ($widget['id'] === $id) {
                return $widget;
            }
        }
        return null;
    }

    public static function sections(): array
    {
        return [
            'announcement' => 'Announcement Bar',
            'header' => 'Header',
            'menu' => 'Navigasi',
            'top-banner' => 'Banner Atas',
            'featured' => 'Area Unggulan',
            'before-content' => 'Sebelum Konten',
            'main' => 'Konten Utama',
            'middle-content' => 'Tengah Konten',
            'after-content' => 'Setelah Konten',
            'sidebar' => 'Sidebar',
            'sidebar-left' => 'Sidebar Kiri',
            'ad-top' => 'Iklan Atas',
            'ad-bottom' => 'Iklan Bawah',
            'footer-about' => 'Footer Tentang',
            'footer-links' => 'Footer Tautan',
            'footer-social' => 'Footer Sosial',
            'footer-bottom' => 'Footer Bawah',
            'footer-1' => 'Footer Kolom 1',
            'footer-2' => 'Footer Kolom 2',
            'footer-3' => 'Footer Kolom 3',
            'custom' => 'Area Kustom',
        ];
    }

    public static function sectionLabel(string $id): string
    {
        return self::sections()[$id] ?? $id;
    }

    public static function config(array $widget): array
    {
        $raw = $widget['config_json'] ?? '';
        if ($widget['type'] === 'LinkList') {
            $links = json_decode((string)$widget['content'], true);
            return is_array($links) ? ['links' => $links] : [];
        }
        $decoded = json_decode((string)$raw, true);
        $type = self::types()[$widget['type']] ?? null;
        $defaults = [];
        foreach ((array)($type['fields'] ?? []) as $key => $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$key] = $field['default'];
            }
        }
        return array_replace($defaults, is_array($decoded) ? $decoded : []);
    }

    /**
     * Resolve device/page visibility. Rules stored on the widget row.
     */
    public static function isVisible(array $widget, array $context = []): bool
    {
        $visibility = (string)($widget['visibility'] ?? 'all');
        $route = (string)($context['route'] ?? '');
        $isMobile = self::detectMobile();
        $devices = array_filter(explode(',', (string)($widget['devices'] ?? 'all'))) ?: ['all'];
        if (!in_array('all', $devices, true)) {
            if ($isMobile && !in_array('mobile', $devices, true) && !in_array('both', $devices, true)) {
                return false;
            }
            if (!$isMobile && !in_array('desktop', $devices, true) && !in_array('both', $devices, true)) {
                return false;
            }
        }
        if ($visibility === 'none') {
            return false;
        }
        if ($visibility !== 'all') {
            $map = [
                'home' => $route === '' || $route === 'home',
                'post' => $route === 'post',
                'page' => $route === 'page',
                'archive' => in_array($route, ['category', 'archive', 'author', 'tag', 'search'], true),
                'search' => $route === 'search',
                'notfound' => $route === 'not-found',
            ];
            if ($visibility === 'custom') {
                $pages = array_filter(array_map('trim', explode(',', (string)($widget['pages'] ?? ''))));
                $current = (string)($context['slug'] ?? '');
                if ($pages !== [] && !in_array($current, $pages, true) && !in_array('*', $pages, true)) {
                    return false;
                }
            } elseif (!($map[$visibility] ?? true)) {
                return false;
            }
        }
        $roles = array_filter(explode(',', (string)($widget['roles'] ?? 'all'))) ?: ['all'];
        if (!in_array('all', $roles, true)) {
            $role = Auth::role();
            if (!in_array($role, $roles, true)) {
                return false;
            }
        }
        return true;
    }

    private static function detectMobile(): bool
    {
        if (isset(self::$runtime['mobile'])) {
            return (bool)self::$runtime['mobile'];
        }
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return self::$runtime['mobile'] = (bool)preg_match('/mobile|android|iphone|ipad|ipod|opera mini|kindle/', $ua);
    }

    public static function render(array $widget, array $context = []): string
    {
        $type = (string)$widget['type'];
        $title = (string)$widget['title'];
        $content = (string)$widget['content'];
        $config = $widget['config'];
        $heading = $title !== '' ? '<h2 class="widget-title">' . e($title) . '</h2>' : '';
        try {
            $body = match ($type) {
                'PopularPosts' => self::renderPostList($config, 'views', $context),
                'RecentPosts' => self::renderPostList($config, 'recent', $context),
                'FeaturedPosts' => self::renderFeatured($config, $context),
                'FeaturedCategory' => self::renderFeaturedCategory($config, $context),
                'Label', 'Categories' => self::renderLabels($config),
                'BlogSearch' => self::renderSearch($config),
                'Archive' => self::renderArchive($config),
                'AuthorProfile' => self::renderAuthor($config, $context),
                'Social' => self::renderSocial($config, $content),
                'HTML' => '<div class="widget-content html-widget">' . $content . '</div>',
                'Text' => '<div class="widget-content text-widget">' . nl2br(e($content)) . '</div>',
                'Image' => self::renderImage($config),
                'Ad' => '<div class="ad-slot" data-slot="' . e((string)($config['label'] ?? 'ad')) . '">' . $content . '</div>',
                'Newsletter' => self::renderNewsletter($config),
                'RelatedPosts' => self::renderRelated($config, $context),
                'TableOfContents' => self::renderToc($config, $context),
                'LinkList' => self::renderLinks($config, $content, false),
                'PageList' => self::renderPageList($title, $context),
                'Menu' => self::renderMenu($config, $context),
                'TagCloud' => self::renderTagCloud($config),
                'Stats' => self::renderStats(),
                'Ticker' => self::renderTicker($config, $content),
                default => '',
            };
        } catch (\Throwable $e) {
            Logger::warning('widget render failed', ['type' => $type, 'error' => $e->getMessage()]);
            return '';
        }
        if ($body === '') {
            return '';
        }
        return $heading . $body;
    }

    // ---------------------------------------------------------------------
    // Renderers
    // ---------------------------------------------------------------------
    private static function renderPostList(array $config, string $mode, array $context): string
    {
        $limit = max(1, min(20, (int)($config['numItemsToShow'] ?? 5)));
        $threshold = '';
        if (($config['timeRange'] ?? 'all') === '30d') {
            $threshold = ' AND p.published_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        } elseif (($config['timeRange'] ?? 'all') === '7d') {
            $threshold = ' AND p.published_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        }
        $order = $mode === 'views' ? 'p.views DESC, p.published_at DESC' : 'p.published_at DESC';
        $posts = Database::fetchAll(
            "SELECT p.id, p.title, p.slug, p.thumbnail, p.body, p.excerpt, p.published_at, p.views
             FROM posts p WHERE p.blog_id = ? AND p.status = 'published' AND p.published_at <= NOW()"
             . $threshold . "
             ORDER BY {$order} LIMIT {$limit}",
            [Context::blogId()]
        );
        if (!$posts) {
            return '';
        }
        $thumbs = !empty($config['showThumbnails']);
        $snippets = !empty($config['showSnippets']);
        $output = '<div class="widget-content post-list' . ($thumbs ? ' with-thumbs' : '') . '">';
        foreach ($posts as $post) {
            $image = post_image_url($post);
            $output .= '<a class="mini-post" href="' . e(post_url($post)) . '">';
            if ($thumbs && $image !== '') {
                $output .= '<span class="mini-thumb"><img src="' . e($image) . '" alt="' . e($post['title']) . '" loading="lazy" decoding="async"></span>';
            }
            $output .= '<span class="mini-body"><span class="mini-title">' . e($post['title']) . '</span>';
            if (!empty($config['showDate'])) {
                $output .= '<span class="mini-date">' . e(date('d M Y', strtotime((string)$post['published_at']))) . '</span>';
            }
            if ($snippets) {
                $output .= '<span class="mini-excerpt">' . e(excerpt($post, 90)) . '</span>';
            }
            $output .= '</span></a>';
        }
        return $output . '</div>';
    }

    private static function renderFeatured(array $config, array $context): string
    {
        $limit = max(1, min(12, (int)($config['numItemsToShow'] ?? 3)));
        $posts = Database::fetchAll(
            "SELECT p.*, u.display_name AS author, u.username AS username FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.blog_id = ? AND p.status = 'published' AND p.published_at <= NOW() AND p.featured = 1
             ORDER BY p.published_at DESC LIMIT {$limit}",
            [Context::blogId()]
        );
        if (!$posts) {
            return '';
        }
        return self::renderFeaturedItems($posts, $config);
    }

    /**
     * Featured-style block limited to one category.
     *
     * Unlike FeaturedPosts this ignores the `featured` flag: it lists the
     * newest published posts filed under the chosen category, so it stays
     * useful without anyone tagging posts by hand.
     */
    private static function renderFeaturedCategory(array $config, array $context): string
    {
        $slug = trim((string)($config['category'] ?? ''));
        if ($slug === '') {
            return '';
        }
        $category = Taxonomy::categoryBySlug($slug);
        if (!$category) {
            return '';
        }
        $limit = max(1, min(12, (int)($config['numItemsToShow'] ?? 3)));
        $posts = Database::fetchAll(
            "SELECT p.*, u.display_name AS author, u.username AS username FROM posts p
             JOIN users u ON u.id = p.author_id
             JOIN post_categories pc ON pc.post_id = p.id
             WHERE p.blog_id = ? AND p.status = 'published' AND p.published_at <= NOW()
               AND pc.category_id = ?
             ORDER BY p.published_at DESC LIMIT {$limit}",
            [Context::blogId(), (int)$category['id']]
        );
        if (!$posts) {
            return '';
        }
        // In card style the block is a preview grid, so offer a link through
        // to the full category archive. Other styles keep their compact shape.
        $layout = (string)($config['layout'] ?? 'list');
        $more = ($layout === 'cards' && !empty($config['showMore'])) ? $category : null;
        return self::renderFeaturedItems($posts, $config, $more);
    }

    /**
     * Shared markup for the featured-style blocks (posts + category).
     *
     * When $more is a category row a trailing "see all" button links to that
     * category's archive, letting the block advertise content beyond the
     * preview without a separate page.
     *
     * @param array<int, array<string, mixed>> $posts
     * @param array<string, mixed>            $config
     * @param array<string, mixed>|null       $more
     */
    private static function renderFeaturedItems(array $posts, array $config, ?array $more = null): string
    {
        $layout = (string)($config['layout'] ?? 'list');
        if ($layout === 'carousel') {
            return self::renderFeaturedCarousel($posts);
        }
        // The "see all" link sits in the widget header, on the same row as the
        // title (left = title, right = link), so it is emitted before the grid
        // and the widget's flex layout lets the grid wrap onto its own line.
        $button = '';
        if ($more !== null) {
            // Visible text stays a short "More"; the aria-label carries the
            // category so the link is not ambiguous when read out of context.
            $name = trim((string)($more['name'] ?? ''));
            $aria = $name !== ''
                ? __('featured_more_label', ['category' => $name])
                : '';
            $button = '<a class="featured-more" href="' . e(category_url($more)) . '"'
                . ($aria !== '' ? ' aria-label="' . e($aria) . '"' : '') . '>'
                . '<span aria-hidden="true">' . e(__('featured_more')) . '</span></a>';
        }
        $output = $button . '<div class="widget-content featured-posts featured-' . e($layout) . '">';
        foreach ($posts as $post) {
            $output .= self::featuredItem($post);
        }
        return $output . '</div>';
    }

    /**
     * One card of a featured-style block.
     *
     * Posts without an image get an initial placeholder instead of losing the
     * whole thumbnail slot, so cards stay aligned and clickable.
     *
     * @param array<string, mixed> $post
     */
    private static function featuredItem(array $post): string
    {
        $image = post_image_url($post);
        $url = e(post_url($post));
        $title = (string)$post['title'];
        $output = '<article class="featured-item">';
        if ($image !== '') {
            $output .= '<a class="featured-thumb" href="' . $url . '">'
                . '<img src="' . e($image) . '" alt="' . e($title) . '" loading="lazy" decoding="async"></a>';
        } else {
            // No image: show the initial in a non-interactive block. It must
            // not be an <a>, because a link inside aria-hidden would still be
            // focusable and breaks screen readers. The title link below is
            // the single affordance, so nothing is lost.
            $initial = mb_strtoupper(mb_substr(trim($title), 0, 1));
            $output .= '<div class="featured-thumb is-placeholder" aria-hidden="true">'
                . '<span class="featured-initial">' . e($initial) . '</span></div>';
        }
        $output .= '<div class="featured-body"><h3><a href="' . $url . '">' . e($title) . '</a></h3>'
            . '<p>' . e(excerpt($post, 110)) . '</p></div></article>';
        return $output;
    }

    /**
     * Horizontal carousel of featured posts.
     *
     * Native horizontal scrolling with scroll-snap does the sliding, so it
     * works without JavaScript and keeps keyboard and trackpad behaviour. The
     * arrow buttons are a progressive enhancement wired up in app.js.
     *
     * @param array<int, array<string, mixed>> $posts
     */
    private static function renderFeaturedCarousel(array $posts): string
    {
        $output = '<div class="widget-content featured-posts featured-carousel" data-carousel>';
        $output .= '<div class="carousel-track" data-carousel-track tabindex="0"'
            . ' role="group" aria-label="' . e(__('featured_carousel')) . '">';
        foreach ($posts as $post) {
            $output .= self::featuredItem($post);
        }
        $output .= '</div>';
        $output .= '<button type="button" class="carousel-nav carousel-nav--prev" data-carousel-prev'
            . ' aria-label="' . e(__('carousel_prev')) . '" hidden>'
            . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
            . '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M15 5l-7 7 7 7"/></svg></button>';
        $output .= '<button type="button" class="carousel-nav carousel-nav--next" data-carousel-next'
            . ' aria-label="' . e(__('carousel_next')) . '" hidden>'
            . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
            . '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></button>';
        return $output . '</div>';
    }

    private static function renderLabels(array $config): string
    {
        $rows = Database::fetchAll(
            "SELECT c.name, c.slug, COUNT(pc.post_id) AS total FROM categories c
             LEFT JOIN post_categories pc ON pc.category_id = c.id
             LEFT JOIN posts p ON p.id = pc.post_id AND p.status = 'published' AND p.blog_id = c.blog_id
             WHERE c.blog_id = ? GROUP BY c.id HAVING total > 0 ORDER BY c.name",
            [Context::blogId()]
        );
        if (!$rows) {
            return '';
        }
        if (($config['sorting'] ?? 'name') === 'count') {
            usort($rows, static fn(array $a, array $b): int => (int)$b['total'] <=> (int)$a['total']);
        }
        $display = (string)($config['display'] ?? 'list');
        $showCount = !empty($config['showCount']);
        $output = '<div class="widget-content labels labels-' . e($display) . '"><ul>';
        foreach ($rows as $row) {
            $output .= '<li><a href="' . e(category_url($row)) . '"><span>' . e($row['name']) . '</span>'
                . ($showCount ? '<span class="count">' . (int)$row['total'] . '</span>' : '') . '</a></li>';
        }
        return $output . '</ul></div>';
    }

    private static function renderSearch(array $config): string
    {
        $placeholder = (string)($config['placeholder'] ?? 'Cari artikel…');
        return '<form class="widget-content search-widget" action="' . e(url('search')) . '" method="get" role="search">'
            . '<input type="search" name="q" placeholder="' . e($placeholder) . '" aria-label="Pencarian" required>'
            . '<button type="submit" aria-label="Cari"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14"/></svg></button></form>';
    }

    private static function renderArchive(array $config): string
    {
        $rows = Database::fetchAll(
            "SELECT YEAR(published_at) AS y, MONTH(published_at) AS m, COUNT(*) AS total
             FROM posts WHERE blog_id = ? AND status = 'published' AND published_at <= NOW()
             GROUP BY y, m ORDER BY y DESC, m DESC LIMIT 24",
            [Context::blogId()]
        );
        if (!$rows) {
            return '';
        }
        $output = '<div class="widget-content archive"><ul>';
        foreach ($rows as $row) {
            $label = date('F Y', mktime(0, 0, 0, (int)$row['m'], 1, (int)$row['y']));
            $output .= '<li><a href="' . e(url('archive/' . $row['y'] . '/' . str_pad((string)$row['m'], 2, '0', STR_PAD_LEFT))) . '">'
                . e($label) . '<span class="count">' . (int)$row['total'] . '</span></a></li>';
        }
        return $output . '</ul></div>';
    }

    private static function renderAuthor(array $config, array $context): string
    {
        $userId = (int)($config['userId'] ?? 0);
        if ($userId === 0) {
            $userId = (int)($context['author_id'] ?? 0);
        }
        $author = $userId > 0
            ? Database::fetch('SELECT * FROM users WHERE id = ?', [$userId])
            : Database::fetch('SELECT * FROM users ORDER BY id LIMIT 1');
        if (!$author) {
            return '';
        }
        $output = '<div class="widget-content author-card">';
        $initial = e(strtoupper(substr((string)$author['username'], 0, 1)));
        $output .= '<div class="author-avatar">'
            . ($author['avatar'] ? '<img src="' . e($author['avatar']) . '" alt="">' : $initial)
            . '</div>';
        $output .= '<h3>' . e($author['display_name'] ?: $author['username']) . '</h3>';
        if (!empty($config['showBio'])) {
            $output .= '<p>' . e($author['bio'] ?: 'Penulis blog ini.') . '</p>';
        }
        $output .= '<a class="author-link" href="' . e(Helpers::authorUrl((string)($author['display_name'] ?: $author['username']))) . '">Lihat semua artikel</a>';
        return $output . '</div>';
    }

    private static function renderSocial(array $config, string $content): string
    {
        $links = json_decode($content, true);
        if (!is_array($links)) {
            $settings = Settings::all();
            $links = [];
            foreach (['facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'tiktok', 'github', 'telegram'] as $network) {
                if (!empty($settings['social_' . $network])) {
                    $links[] = ['name' => ucfirst($network), 'url' => $settings['social_' . $network], 'icon' => $network];
                }
            }
        }
        if (!$links) {
            return '';
        }
        $style = (string)($config['style'] ?? 'icons');
        $output = '<div class="widget-content social-links social-' . e($style) . '">';
        foreach ($links as $link) {
            $name = (string)($link['name'] ?? ($link['icon'] ?? 'link'));
            $href = safe_href((string)($link['url'] ?? $link['href'] ?? '#'));
            $output .= '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer" title="' . e($name) . '" data-network="' . e(strtolower($name)) . '">'
                . '<span class="social-icon">' . e(strtoupper(substr($name, 0, 1))) . '</span><span class="social-label">' . e($name) . '</span></a>';
        }
        return $output . '</div>';
    }

    private static function renderImage(array $config): string
    {
        $src = (string)($config['src'] ?? '');
        if ($src === '' || !valid_asset_url($src)) {
            return '';
        }
        $href = (string)($config['href'] ?? '');
        $caption = (string)($config['caption'] ?? '');
        $img = '<img src="' . e($src) . '" alt="' . e($caption) . '" loading="lazy">';
        $body = $href !== '' ? '<a href="' . e(safe_href($href)) . '" rel="noopener">' . $img . '</a>' : $img;
        return '<figure class="widget-content image-widget">' . $body . ($caption !== '' ? '<figcaption>' . e($caption) . '</figcaption>' : '') . '</figure>';
    }

    private static function renderNewsletter(array $config): string
    {
        // The /newsletter handler redirects back with ?newsletter=<key>; show
        // the matching message above the form so the visitor gets feedback.
        $notice = (string)($_GET['newsletter'] ?? '');
        $allowed = ['newsletter_ok' => 'ok', 'newsletter_already' => 'info', 'newsletter_error' => 'error'];
        $banner = '';
        if ($notice !== '' && isset($allowed[$notice])) {
            $banner = '<p class="newsletter-notice ' . $allowed[$notice] . '">' . e(__($notice)) . '</p>';
        }
        return '<div class="widget-content newsletter">' . $banner
            . '<form method="post" action="' . e(url('newsletter')) . '" class="newsletter-form" data-ajax'
            . ' data-ok="' . e(__('newsletter_ok')) . '" data-error="' . e(__('newsletter_error')) . '">'
            . Csrf::field()
            . '<input type="email" name="email" placeholder="' . e(__('email_address')) . '" required autocomplete="email">'
            . '<button type="submit">' . e((string)($config['button'] ?? __('subscribe'))) . '</button>'
            . '<small>' . e((string)($config['note'] ?? '')) . '</small></form></div>';
    }

    private static function renderRelated(array $config, array $context): string
    {
        $postId = (int)($context['post_id'] ?? 0);
        if ($postId === 0) {
            return '';
        }
        $limit = max(1, min(9, (int)($config['numItemsToShow'] ?? 3)));
        $posts = Database::fetchAll(
            "SELECT DISTINCT p.id, p.title, p.slug, p.published_at FROM posts p
             JOIN post_categories pc ON pc.post_id = p.id
             WHERE p.blog_id = ? AND p.id <> ? AND p.status = 'published' AND p.published_at <= NOW()
               AND pc.category_id IN (SELECT category_id FROM post_categories WHERE post_id = ?)
             ORDER BY p.published_at DESC LIMIT {$limit}",
            [Context::blogId(), $postId, $postId]
        );
        if (!$posts) {
            return '';
        }
        $output = '<div class="widget-content related-list">';
        foreach ($posts as $post) {
            $output .= '<a href="' . e(post_url($post)) . '">' . e($post['title']) . '<span>' . e(date('d M Y', strtotime((string)$post['published_at']))) . '</span></a>';
        }
        return $output . '</div>';
    }

    private static function renderToc(array $config, array $context): string
    {
        $body = (string)($context['body'] ?? '');
        $depth = max(1, min(6, (int)($config['depth'] ?? 3)));
        // The closing tag used to be written as `</h...`, whose slash ended
        // the pattern early and made preg_match_all() fail with "Unknown
        // modifier 'h'". Escaping it keeps the whole tag inside the regex.
        if ($body === '' || !preg_match_all(
            '/<h([1-' . $depth . '])[^>]*>(.*?)<\/h[1-' . $depth . ']>/is',
            $body,
            $matches,
            PREG_SET_ORDER
        )) {
            return '';
        }
        $output = '<nav class="widget-content toc"><ul>';
        $seen = [];
        foreach ($matches as $match) {
            $label = trim(Html::plain($match[2]));
            if ($label === '') {
                continue;
            }
            // Mirror the slug the front-end builds from the heading text so
            // both produce the same anchor. Duplicates get a numeric suffix to
            // stay unique, again matching the front-end behaviour.
            $slug = 'h-' . trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($label)) ?? '', '-');
            $slug = substr($slug, 0, 60) ?: 'h-section';
            if (isset($seen[$slug])) {
                $seen[$slug]++;
                $slug .= '-' . $seen[$slug];
            } else {
                $seen[$slug] = 1;
            }
            $output .= '<li><a href="#' . e($slug) . '">' . e($label) . '</a></li>';
        }
        if ($output === '<nav class="widget-content toc"><ul>') {
            return '';
        }
        return $output . '</ul></nav>';
    }

    private static function renderLinks(array $config, string $content, bool $isMenu): string
    {
        $links = $config['links'] ?? json_decode($content, true);
        if (!is_array($links) || $links === []) {
            return '';
        }
        $output = '<div class="widget-content link-list"><ul>';
        foreach ($links as $link) {
            $label = (string)($link['name'] ?? $link['label'] ?? $link['title'] ?? 'Tautan');
            $href = safe_href((string)($link['url'] ?? $link['target'] ?? '#'));
            $output .= '<li><a href="' . e($href) . '">' . e($label) . '</a></li>';
        }
        return $output . '</ul></div>';
    }

    private static function renderPageList(string $title, array $context): string
    {
        $pages = Database::fetchAll(
            "SELECT title, slug FROM pages WHERE blog_id = ? AND status = 'published' AND published_at <= NOW() ORDER BY sort_order, title",
            [Context::blogId()]
        );
        if (!$pages) {
            return '';
        }
        $output = '<div class="widget-content page-list"><ul>';
        foreach ($pages as $page) {
            $output .= '<li><a href="' . e(page_url($page)) . '">' . e($page['title']) . '</a></li>';
        }
        return $output . '</ul></div>';
    }

    private static function renderMenu(array $config, array $context): string
    {
        $location = (string)($config['location'] ?? 'primary');
        $menu = Database::fetch(
            'SELECT id FROM menus WHERE blog_id = ? AND (location = ? OR slug = ?) ORDER BY id LIMIT 1',
            [Context::blogId(), $location, $location]
        );
        if (!$menu) {
            return '';
        }
        $items = Database::fetchAll(
            'SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order, id',
            [(int)$menu['id']]
        );
        if (!$items) {
            return '';
        }
        $tree = self::buildTree($items);
        return '<nav class="widget-content menu-nav"><ul class="menu-list">' . self::renderMenuItems($tree) . '</ul></nav>';
    }

    private static function buildTree(array $items, int $parent = 0): array
    {
        $branch = [];
        foreach ($items as $item) {
            if ((int)($item['parent_id'] ?? 0) === $parent) {
                $item['children'] = self::buildTree($items, (int)$item['id']);
                $branch[] = $item;
            }
        }
        return $branch;
    }

    private static function renderMenuItems(array $items): string
    {
        $output = '';
        foreach ($items as $item) {
            $output .= '<li><a href="' . e(safe_href((string)$item['url'] ?: url())) . '" target="' . e((string)($item['target'] ?: '_self')) . '">'
                . e((string)$item['label']) . '</a>';
            if (!empty($item['children'])) {
                $output .= '<ul class="submenu">' . self::renderMenuItems($item['children']) . '</ul>';
            }
            $output .= '</li>';
        }
        return $output;
    }

    private static function renderTagCloud(array $config): string
    {
        $limit = max(3, min(60, (int)($config['limit'] ?? 20)));
        $tags = Database::fetchAll(
            "SELECT t.name, t.slug, COUNT(pt.post_id) AS total FROM tags t
             JOIN post_tags pt ON pt.tag_id = t.id JOIN posts p ON p.id = pt.post_id AND p.status = 'published'
             WHERE t.blog_id = ? GROUP BY t.id ORDER BY total DESC LIMIT {$limit}",
            [Context::blogId()]
        );
        if (!$tags) {
            return '';
        }
        $output = '<div class="widget-content tag-cloud">';
        foreach ($tags as $tag) {
            $size = min(1.6, 0.9 + ((int)$tag['total'] / 10));
            $output .= '<a href="' . e(url('tag/' . rawurlencode((string)($tag['slug'] ?? $tag['name'])))) . '" style="font-size:' . number_format($size, 2, '.', '') . 'em">' . e($tag['name']) . '</a>';
        }
        return $output . '</div>';
    }

    private static function renderStats(): string
    {
        $posts = (int)Database::value("SELECT COUNT(*) FROM posts WHERE blog_id = ? AND status = 'published'", [Context::blogId()]);
        $comments = (int)Database::value("SELECT COUNT(*) FROM comments WHERE blog_id = ? AND status = 'approved'", [Context::blogId()]);
        $categories = (int)Database::value('SELECT COUNT(*) FROM categories WHERE blog_id = ?', [Context::blogId()]);
        return '<div class="widget-content stat-grid">'
            . '<div><strong>' . $posts . '</strong><span>' . e(__('articles')) . '</span></div>'
            . '<div><strong>' . $categories . '</strong><span>' . e(__('categories')) . '</span></div>'
            . '<div><strong>' . $comments . '</strong><span>' . e(__('comments')) . '</span></div></div>';
    }

    private static function renderTicker(array $config, string $content): string
    {
        $plain = trim(strip_tags($content));
        if ($plain === '') {
            return '';
        }
        $speed = max(8, min(80, (int)($config['speed'] ?? 28)));
        return '<div class="widget-content ticker" style="--ticker-duration:' . $speed . 's"><div class="ticker-track"><span>'
            . $content . '</span><span aria-hidden="true">' . $content . '</span></div></div>';
    }

    /**
     * Persist widget ordering (drag & drop endpoint).
     */
    public static function reorder(array $positions): void
    {
        Database::transaction(static function () use ($positions): void {
            foreach ($positions as $position) {
                $id = (int)($position['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $section = (string)($position['section'] ?? '');
                $order = (int)($position['order'] ?? 0);
                if (!array_key_exists($section, self::sections())) {
                    continue;
                }
                Database::run(
                    'UPDATE widgets SET section_id = ?, sort_order = ? WHERE id = ? AND blog_id = ?',
                    [$section, $order, $id, Context::blogId()]
                );
            }
        });
        self::flush();
    }

    public static function duplicate(int $id): int
    {
        $widget = self::find($id);
        if (!$widget) {
            throw new ValidationException('Widget tidak ditemukan.');
        }
        $key = 'custom-' . bin2hex(random_bytes(6));
        return Database::insert('widgets', [
            'blog_id' => Context::blogId(),
            'widget_key' => $key,
            'title' => $widget['title'] . ' (salinan)',
            'type' => $widget['type'],
            'section_id' => $widget['section_id'],
            'content' => $widget['content'],
            'config_json' => $widget['config_json'],
            'enabled' => $widget['enabled'],
            'sort_order' => $widget['sort_order'] + 1,
            'visibility' => $widget['visibility'],
            'pages' => $widget['pages'],
            'devices' => $widget['devices'],
            'roles' => $widget['roles'],
        ]);
    }
}
