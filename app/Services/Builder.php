<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Database;
use App\Core\Html;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\Theme;

/**
 * Page builder: JSON block document → sanitized HTML.
 * Block documents are portable and render identically in the front end,
 * admin live preview and export.
 */
final class Builder
{
    public static function blockTypes(): array
    {
        return [
            'hero' => ['label' => 'Hero Section', 'icon' => 'panel-top', 'fields' => ['title', 'subtitle', 'image', 'button_text', 'button_url', 'align', 'overlay']],
            'heading' => ['label' => 'Heading', 'icon' => 'heading', 'fields' => ['text', 'level', 'align']],
            'text' => ['label' => 'Teks', 'icon' => 'text', 'fields' => ['text', 'align']],
            'image' => ['label' => 'Gambar', 'icon' => 'image', 'fields' => ['src', 'alt', 'caption', 'width']],
            'gallery' => ['label' => 'Galeri', 'icon' => 'images', 'fields' => ['images', 'columns'],
                // Editor baris: setiap gambar punya URL + teks alternatif.
                'itemFields' => ['src' => ['label' => 'URL gambar', 'type' => 'image'], 'alt' => ['label' => 'Teks alternatif', 'type' => 'text']],
                'itemLabel' => 'gambar'],
            'button' => ['label' => 'Tombol', 'icon' => 'mouse-pointer', 'fields' => ['text', 'url', 'style', 'size', 'align']],
            'cta' => ['label' => 'Call To Action', 'icon' => 'megaphone', 'fields' => ['title', 'text', 'button_text', 'button_url', 'background']],
            'columns' => ['label' => 'Kolom', 'icon' => 'columns', 'fields' => ['count', 'gap', 'content']],
            'features' => ['label' => 'Fitur', 'icon' => 'grid', 'fields' => ['items', 'columns'],
                'itemFields' => [
                    'icon' => ['label' => 'Ikon', 'type' => 'text', 'placeholder' => '★'],
                    'title' => ['label' => 'Judul', 'type' => 'text'],
                    'text' => ['label' => 'Keterangan', 'type' => 'textarea'],
                ],
                'itemLabel' => 'fitur'],
            'video' => ['label' => 'Video Embed', 'icon' => 'video', 'fields' => ['url', 'ratio']],
            'quote' => ['label' => 'Kutipan', 'icon' => 'quote', 'fields' => ['text', 'author']],
            'divider' => ['label' => 'Pemisah', 'icon' => 'minus', 'fields' => ['style']],
            'spacer' => ['label' => 'Spacer', 'icon' => 'space', 'fields' => ['height']],
            'html' => ['label' => 'HTML Kustom', 'icon' => 'code', 'fields' => ['html']],
            'posts' => ['label' => 'Postingan', 'icon' => 'file-text', 'fields' => ['category', 'limit', 'layout']],
            'faq' => ['label' => 'FAQ', 'icon' => 'help', 'fields' => ['items'],
                'itemFields' => [
                    'question' => ['label' => 'Pertanyaan', 'type' => 'text'],
                    'answer' => ['label' => 'Jawaban', 'type' => 'textarea'],
                ],
                'itemLabel' => 'pertanyaan'],
            'testimonials' => ['label' => 'Testimoni', 'icon' => 'message', 'fields' => ['items'],
                'itemFields' => [
                    'text' => ['label' => 'Isi testimoni', 'type' => 'textarea'],
                    'name' => ['label' => 'Nama', 'type' => 'text'],
                ],
                'itemLabel' => 'testimoni'],
            'newsletter' => ['label' => 'Newsletter', 'icon' => 'mail', 'fields' => ['title', 'text', 'button']],
        ];
    }

    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return ['version' => 1, 'sections' => []];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['sections']) || !is_array($decoded['sections'])) {
            return ['version' => 1, 'sections' => []];
        }
        return $decoded;
    }

    /**
     * Normalise a posted document, dropping unknown blocks and unsafe HTML.
     */
    public static function normalize(string $json, bool $allowRawHtml = false): string
    {
        $document = self::decode($json);
        $types = self::blockTypes();
        $sections = [];
        foreach ($document['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }
            $blocks = [];
            foreach ((array)($section['blocks'] ?? []) as $block) {
                if (!is_array($block) || !isset($types[(string)($block['type'] ?? '')])) {
                    continue;
                }
                $type = (string)$block['type'];
                $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
                $settings = self::sanitizeSettings($type, $settings, $allowRawHtml);
                $blocks[] = ['id' => (string)($block['id'] ?? bin2hex(random_bytes(4))), 'type' => $type, 'settings' => $settings];
            }
            $sections[] = [
                'id' => (string)($section['id'] ?? bin2hex(random_bytes(4))),
                'settings' => [
                    'background' => self::color((string)($section['settings']['background'] ?? '')),
                    'padding' => (string)($section['settings']['padding'] ?? 'md'),
                    'width' => (string)($section['settings']['width'] ?? 'normal'),
                    'class' => preg_replace('/[^a-z0-9_-]/i', '', (string)($section['settings']['class'] ?? '')),
                ],
                'blocks' => $blocks,
            ];
        }
        return (string)json_encode(['version' => 1, 'sections' => $sections], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sanitizeSettings(string $type, array $settings, bool $allowRawHtml): array
    {
        $clean = [];
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = self::sanitizeArray($value, $allowRawHtml);
                continue;
            }
            $value = (string)$value;
            if (in_array($key, ['items', 'images'], true)) {
                $decoded = json_decode($value, true);
                $clean[$key] = is_array($decoded) ? self::sanitizeArray($decoded, $allowRawHtml) : [];
                continue;
            }
            $clean[$key] = match (true) {
                in_array($key, ['url', 'button_url', 'src'], true) => valid_asset_url($value) ? $value : '',
                $key === 'html' && $allowRawHtml => $value,
                $key === 'html' => Html::sanitize($value),
                in_array($key, ['text', 'subtitle', 'caption', 'author', 'title'], true) => Html::sanitize($value),
                in_array($key, ['align', 'style', 'size', 'ratio', 'padding', 'width', 'layout', 'background'], true) => preg_replace('/[^a-z0-9#_-]/i', '', $value),
                in_array($key, ['level', 'count', 'columns', 'height', 'limit', 'gap'], true) => (string)max(0, min(12, (int)$value)),
                default => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            };
        }
        if ($type === 'video' && !empty($clean['url']) && !Html::isSafeEmbed((string)$clean['url'])) {
            $clean['url'] = '';
        }
        return $clean;
    }

    private static function sanitizeArray(array $values, bool $allowRawHtml): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $safeKey = is_int($key) ? $key : (string)preg_replace('/[^a-z0-9_-]/i', '', (string)$key);
            if (is_array($value)) {
                $result[$safeKey] = self::sanitizeArray($value, $allowRawHtml);
            } else {
                $result[$safeKey] = Html::sanitize((string)$value);
            }
        }
        return $result;
    }

    private static function color(string $value): string
    {
        return preg_match('/^#?[0-9a-f]{3,8}$/i', $value) ? $value : '';
    }

    /**
     * Render a builder document to HTML.
     */
    public static function render(?string $json): string
    {
        $document = self::decode($json);
        if (empty($document['sections'])) {
            return '';
        }
        $output = '<div class="builder-document">';
        foreach ($document['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }
            $settings = (array)($section['settings'] ?? []);
            $styles = [];
            if (!empty($settings['background'])) {
                $background = (string)$settings['background'];
                $styles[] = 'background:' . (str_starts_with($background, '#') ? $background : '#' . $background);
            }
            $classes = implode(' ', array_filter([
                'builder-section',
                'padding-' . preg_replace('/[^a-z0-9-]/i', '', (string)($settings['padding'] ?? 'md')),
                'width-' . preg_replace('/[^a-z0-9-]/i', '', (string)($settings['width'] ?? 'normal')),
                (string)($settings['class'] ?? ''),
            ]));
            $output .= '<section class="' . e($classes) . '"' . ($styles ? ' style="' . e(implode(';', $styles)) . '"' : '') . '>';
            foreach ((array)($section['blocks'] ?? []) as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $output .= self::renderBlock((string)($block['type'] ?? ''), (array)($block['settings'] ?? []));
            }
            $output .= '</section>';
        }
        return $output . '</div>';
    }

    public static function renderBlock(string $type, array $settings): string
    {
        $align = e((string)($settings['align'] ?? 'left'));
        return match ($type) {
            'hero' => self::hero($settings),
            'heading' => '<h' . max(1, min(6, (int)($settings['level'] ?? 2))) . ' class="builder-heading" style="text-align:' . $align . '">'
                . Html::sanitize((string)($settings['text'] ?? '')) . '</h' . max(1, min(6, (int)($settings['level'] ?? 2))) . '>',
            'text' => '<div class="builder-text" style="text-align:' . $align . '">' . Html::sanitize((string)($settings['text'] ?? '')) . '</div>',
            'image' => self::image($settings),
            'gallery' => self::gallery($settings),
            'button' => self::button($settings),
            'cta' => self::cta($settings),
            'columns' => self::columns($settings),
            'features' => self::features($settings),
            'video' => self::video($settings),
            'quote' => '<blockquote class="builder-quote"><p>' . Html::sanitize((string)($settings['text'] ?? '')) . '</p>'
                . (!empty($settings['author']) ? '<cite>— ' . Html::sanitize((string)$settings['author']) . '</cite>' : '') . '</blockquote>',
            'divider' => '<hr class="builder-divider ' . e((string)($settings['style'] ?? 'solid')) . '">',
            'spacer' => '<div class="builder-spacer" style="height:' . (int)($settings['height'] ?? 40) . 'px"></div>',
            'html' => '<div class="builder-html">' . (string)($settings['html'] ?? '') . '</div>',
            'posts' => self::posts($settings),
            'faq' => self::faq($settings),
            'testimonials' => self::testimonials($settings),
            'newsletter' => self::newsletter($settings),
            default => '',
        };
    }

    private static function hero(array $s): string
    {
        $image = (string)($s['image'] ?? '');
        $classes = 'builder-hero align-' . preg_replace('/[^a-z0-9-]/i', '', (string)($s['align'] ?? 'center'));
        $style = $image !== '' && valid_asset_url($image)
            ? 'background-image:linear-gradient(rgba(10,15,30,' . (0.35 + (int)($s['overlay'] ?? 0) / 100) . '),rgba(10,15,30,' . (0.35 + (int)($s['overlay'] ?? 0) / 100) . ')),url(' . e($image) . ')'
            : '';
        $out = '<header class="' . $classes . '"' . ($style !== '' ? ' style="' . $style . '"' : '') . '><div class="hero-inner">';
        if (!empty($s['subtitle'])) {
            $out .= '<p class="hero-eyebrow">' . Html::sanitize((string)$s['subtitle']) . '</p>';
        }
        $out .= '<h1>' . Html::sanitize((string)($s['title'] ?? '')) . '</h1>';
        if (!empty($s['button_text']) && valid_asset_url((string)($s['button_url'] ?? ''))) {
            $out .= '<a class="btn btn-primary" href="' . e((string)$s['button_url']) . '">' . Html::sanitize((string)$s['button_text']) . '</a>';
        }
        return $out . '</div></header>';
    }

    private static function image(array $s): string
    {
        $src = (string)($s['src'] ?? '');
        if ($src === '' || !valid_asset_url($src)) {
            return '';
        }
        $width = (string)($s['width'] ?? 'full');
        return '<figure class="builder-image width-' . e($width) . '"><img src="' . e($src) . '" alt="' . e((string)($s['alt'] ?? '')) . '" loading="lazy">'
            . (!empty($s['caption']) ? '<figcaption>' . e((string)$s['caption']) . '</figcaption>' : '') . '</figure>';
    }

    private static function gallery(array $s): string
    {
        $images = (array)($s['images'] ?? []);
        if ($images === []) {
            return '';
        }
        $columns = max(1, min(6, (int)($s['columns'] ?? 3)));
        $out = '<div class="builder-gallery cols-' . $columns . '">';
        foreach ($images as $image) {
            $src = is_array($image) ? (string)($image['src'] ?? '') : (string)$image;
            if (!valid_asset_url($src)) {
                continue;
            }
            $alt = is_array($image) ? (string)($image['alt'] ?? '') : '';
            $out .= '<figure><img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy"></figure>';
        }
        return $out . '</div>';
    }

    private static function button(array $s): string
    {
        $url = (string)($s['url'] ?? '');
        if (!valid_asset_url($url)) {
            return '';
        }
        $style = preg_replace('/[^a-z0-9-]/i', '', (string)($s['style'] ?? 'primary'));
        $size = preg_replace('/[^a-z0-9-]/i', '', (string)($s['size'] ?? 'md'));
        return '<div class="builder-button align-' . e((string)($s['align'] ?? 'left')) . '"><a class="btn btn-' . $style . ' btn-' . $size . '" href="' . e($url) . '">'
            . Html::sanitize((string)($s['text'] ?? 'Buka')) . '</a></div>';
    }

    private static function cta(array $s): string
    {
        $bg = preg_match('/^#?[0-9a-f]{3,8}$/i', (string)($s['background'] ?? '')) ? (string)$s['background'] : '';
        $out = '<div class="builder-cta"' . ($bg !== '' ? ' style="background:' . e(str_starts_with($bg, '#') ? $bg : '#' . $bg) . '"' : '') . '>';
        $out .= '<h2>' . Html::sanitize((string)($s['title'] ?? '')) . '</h2>';
        $out .= '<p>' . Html::sanitize((string)($s['text'] ?? '')) . '</p>';
        if (!empty($s['button_text']) && valid_asset_url((string)($s['button_url'] ?? ''))) {
            $out .= '<a class="btn btn-light" href="' . e((string)$s['button_url']) . '">' . Html::sanitize((string)$s['button_text']) . '</a>';
        }
        return $out . '</div>';
    }

    private static function columns(array $s): string
    {
        $count = max(1, min(6, (int)($s['count'] ?? 2)));
        $content = $s['content'] ?? [];
        if (is_string($content)) {
            $content = [$content];
        }
        $out = '<div class="builder-columns cols-' . $count . '">';
        for ($i = 0; $i < $count; $i++) {
            $out .= '<div class="builder-column">' . Html::sanitize((string)($content[$i] ?? '')) . '</div>';
        }
        return $out . '</div>';
    }

    private static function features(array $s): string
    {
        $items = (array)($s['items'] ?? []);
        if ($items === []) {
            return '';
        }
        $columns = max(1, min(4, (int)($s['columns'] ?? 3)));
        $out = '<div class="builder-features cols-' . $columns . '">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out .= '<div class="feature-card"><span class="feature-icon">' . Html::sanitize((string)($item['icon'] ?? '★')) . '</span>'
                . '<h3>' . Html::sanitize((string)($item['title'] ?? '')) . '</h3>'
                . '<p>' . Html::sanitize((string)($item['text'] ?? '')) . '</p></div>';
        }
        return $out . '</div>';
    }

    private static function video(array $s): string
    {
        $url = (string)($s['url'] ?? '');
        if ($url === '' || !Html::isSafeEmbed($url)) {
            return '';
        }
        $ratio = preg_replace('/[^0-9x:]/i', '', (string)($s['ratio'] ?? '16x9'));
        return '<div class="builder-video ratio-' . e($ratio) . '"><iframe src="' . e($url) . '" loading="lazy" allowfullscreen '
            . 'sandbox="allow-scripts allow-same-origin allow-presentation" title="Video"></iframe></div>';
    }

    private static function posts(array $s): string
    {
        $limit = max(1, min(12, (int)($s['limit'] ?? 3)));
        $category = (string)($s['category'] ?? '');
        $posts = \App\Models\Post::query(['status' => 'published', 'search' => ''] + ($category !== '' ? ['category_id' => (int)$category] : []), $limit);
        if (!$posts) {
            return '';
        }
        $layout = preg_replace('/[^a-z0-9-]/i', '', (string)($s['layout'] ?? 'cards'));
        $out = '<div class="builder-posts layout-' . $layout . '">';
        foreach ($posts as $post) {
            $image = post_image_url($post);
            $out .= '<article class="post-card"><a class="card-thumb" href="' . e(post_url($post)) . '">'
                . ($image !== '' ? '<img src="' . e($image) . '" alt="' . e($post['title']) . '" loading="lazy">' : '<span class="thumb-fallback"></span>')
                . '</a><div class="card-body"><h3><a href="' . e(post_url($post)) . '">' . e($post['title']) . '</a></h3>'
                . '<p>' . e(excerpt($post, 120)) . '</p></div></article>';
        }
        return $out . '</div>';
    }

    private static function faq(array $s): string
    {
        $items = (array)($s['items'] ?? []);
        if ($items === []) {
            return '';
        }
        $out = '<div class="builder-faq">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out .= '<details><summary>' . Html::sanitize((string)($item['question'] ?? '')) . '</summary><p>'
                . Html::sanitize((string)($item['answer'] ?? '')) . '</p></details>';
        }
        return $out . '</div>';
    }

    private static function testimonials(array $s): string
    {
        $items = (array)($s['items'] ?? []);
        if ($items === []) {
            return '';
        }
        $out = '<div class="builder-testimonials">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out .= '<figure><blockquote>' . Html::sanitize((string)($item['text'] ?? '')) . '</blockquote>'
                . '<figcaption>' . Html::sanitize((string)($item['name'] ?? '')) . '</figcaption></figure>';
        }
        return $out . '</div>';
    }

    private static function newsletter(array $s): string
    {
        return '<div class="builder-newsletter"><h2>' . Html::sanitize((string)($s['title'] ?? 'Berlangganan')) . '</h2>'
            . '<p>' . Html::sanitize((string)($s['text'] ?? '')) . '</p>'
            . '<form method="post" action="' . e(url('newsletter')) . '">' . \App\Core\Csrf::field()
            . '<input type="email" name="email" placeholder="Email" required><button class="btn btn-primary" type="submit">'
            . Html::sanitize((string)($s['button'] ?? 'Kirim')) . '</button></form></div>';
    }

    /**
     * Build a standalone HTML document for the builder preview iframe.
     *
     * It mirrors the front-end stylesheet order (base → system → theme →
     * webfont → customizer) and the page wrapper used by FrontController so
     * the canvas looks exactly like the published page, including dark themes.
     * Interactive elements are neutralised so the pane behaves as a canvas.
     */
    public static function previewDocument(?string $json, string $title = ''): string
    {
        $theme = Theme::instance();
        $normalized = self::normalize((string)$json);
        $body = self::render($normalized);
        $lang = Settings::get('language', 'id');

        $head = '<!doctype html><html lang="' . e($lang) . '" data-theme="' . e($theme->slug()) . '">';
        $head .= '<head><meta charset="utf-8">';
        $head .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $head .= '<link rel="stylesheet" href="' . e(url('assets/front/base.css')) . '">';
        $system = (defined('CMS_ROOT') ? CMS_ROOT : dirname(__DIR__, 2)) . '/assets/front/system.css';
        $head .= '<link rel="stylesheet" href="' . e(url('assets/front/system.css')) . '?v=' . (is_file($system) ? (int)filemtime($system) : 1) . '">';
        $css = $theme->asset('style.css');
        if ($css !== '') {
            $head .= '<link rel="stylesheet" href="' . e($css) . '">';
        }
        $font = $theme->setting('font_pair');
        if ($font !== '' && str_starts_with($font, 'https://')) {
            $head .= '<link rel="stylesheet" href="' . e($font) . '">';
        }
        $custom = $theme->customizerCss();
        if (trim($custom) !== '') {
            $head .= '<style id="theme-customizer">' . $custom . '</style>';
        }
        // Canvas tweaks: the pane is a preview, not a navigable page.
        $head .= '<style id="builder-preview-base">body{margin:0}.builder-preview-empty{margin:0;padding:28px;color:#64748b;text-align:center;font:14px/1.6 system-ui,sans-serif}</style>';
        $head .= '</head><body class="' . e($theme->bodyClasses()) . '">';

        $inner = '<article class="single-page page-builder">';
        $heading = trim($title);
        if ($heading !== '') {
            $inner .= '<header class="page-header"><h1>' . e($heading) . '</h1></header>';
        }
        $inner .= '<div class="article-body prose">';
        $inner .= $body !== ''
            ? $body
            : '<p class="builder-preview-empty">Tambahkan blok dari panel kiri untuk melihat pratinjau halaman di sini.</p>';
        $inner .= '</div></article>';

        $head .= '<main id="main" class="site-main shell">' . $inner . '</main>';
        $head .= '<script id="builder-preview-guard">document.addEventListener("click",function(e){var a=e.target.closest("a");if(a){e.preventDefault();}},true);document.addEventListener("submit",function(e){e.preventDefault();},true);</script>';
        $head .= '</body></html>';
        return $head;
    }

    /**
     * Starter document used by "create page with builder".
     */
    public static function starter(string $title): string
    {
        return (string)json_encode([
            'version' => 1,
            'sections' => [
                [
                    'id' => 'sec-hero',
                    'settings' => ['background' => '', 'padding' => 'lg', 'width' => 'normal', 'class' => ''],
                    'blocks' => [
                        ['id' => 'hero-1', 'type' => 'hero', 'settings' => [
                            'title' => $title,
                            'subtitle' => Settings::get('site_description', 'Selamat datang'),
                            'image' => '',
                            'button_text' => '',
                            'button_url' => '',
                            'align' => 'center',
                            'overlay' => 40,
                        ]],
                        ['id' => 'text-1', 'type' => 'text', 'settings' => ['text' => '<p>Mulai bangun halaman ini dengan menambahkan blok.</p>', 'align' => 'center']],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
