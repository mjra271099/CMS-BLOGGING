<?php
declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * HTML sanitizer built on DOMDocument with a strict tag/attribute allowlist.
 * Author level content is sanitized, admin level content is trusted by design.
 */
final class Html
{
    private const ALLOWED = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's',
        'blockquote', 'cite', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'caption', 'col', 'colgroup', 'code', 'pre',
        'span', 'div', 'section', 'article', 'aside', 'video', 'audio', 'source', 'iframe',
        'embed', 'object', 'svg', 'path', 'circle', 'rect', 'line', 'polygon', 'polyline',
        'g', 'defs', 'title', 'mark', 'small', 'sup', 'sub', 'abbr', 'details', 'summary', 'del', 'ins',
    ];

    private const GLOBAL_ATTRS = [
        'class', 'id', 'title', 'lang', 'dir', 'role', 'aria-label', 'aria-hidden', 'aria-describedby',
        'aria-labelledby', 'data-*', 'style',
    ];

    private const TAG_ATTRS = [
        'a' => ['href', 'target', 'rel', 'name', 'download'],
        'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding'],
        'iframe' => ['src', 'width', 'height', 'frameborder', 'allowfullscreen', 'allow', 'loading', 'sandbox'],
        'embed' => ['src', 'type', 'width', 'height'],
        'object' => ['data', 'type', 'width', 'height'],
        'video' => ['src', 'poster', 'controls', 'width', 'height', 'preload', 'playsinline', 'muted'],
        'audio' => ['src', 'controls', 'preload'],
        'source' => ['src', 'type', 'srcset', 'media'],
        'svg' => ['viewbox', 'xmlns', 'fill', 'width', 'height', 'stroke'],
        'path' => ['d', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'],
        'table' => ['border', 'cellpadding', 'cellspacing'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'blockquote' => ['cite'],
    ];

    private const URL_ATTRS = ['href', 'src', 'data', 'cite', 'poster', 'srcset'];
    private const EMBED_HOSTS = [
        'www.youtube.com', 'youtube.com', 'youtu.be', 'player.vimeo.com', 'vimeo.com',
        'www.dailymotion.com', 'dailymotion.com', 'open.spotify.com', 'w.soundcloud.com',
        'soundcloud.com', 'www.google.com', 'maps.google.com', 'www.google.com/maps',
        'player.twitch.tv', 'www.tiktok.com', 'instagram.com', 'www.instagram.com',
        'twitter.com', 'platform.twitter.com', 'x.com', 'facebook.com', 'www.facebook.com',
    ];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><html><body><div id="cms-root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('cms-root');
        if (!$root instanceof DOMElement) {
            return '';
        }
        self::walk($root);
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }
        return trim($output);
    }

    private static function walk(DOMNode $node): void
    {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            /** @var DOMElement $element */
            $element = $node;
            $tag = strtolower($element->nodeName);
            if ($tag === 'script' || $tag === 'style' || $tag === 'form' || $tag === 'input'
                || $tag === 'button' || $tag === 'textarea' || $tag === 'select' || $tag === 'option'
                || $tag === 'link' || $tag === 'meta' || $tag === 'base' || $tag === 'html'
                || $tag === 'head' || $tag === 'body') {
                $element->parentNode?->removeChild($element);
                return;
            }
            if (!in_array($tag, self::ALLOWED, true)) {
                // Unwrap unknown elements but keep their text content.
                $element->parentNode?->removeChild($element);
                return;
            }
            self::cleanAttributes($element, $tag);
            if ($tag === 'iframe' || $tag === 'embed' || $tag === 'object') {
                if (!self::isSafeEmbed((string)$element->getAttribute('src') ?: (string)$element->getAttribute('data'))) {
                    $element->parentNode?->removeChild($element);
                    return;
                }
            }
        }
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            self::walk($child);
        }
    }

    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::TAG_ATTRS[$tag] ?? []);
        $remove = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (str_starts_with($name, 'on')) {
                $remove[] = $name;
                continue;
            }
            $wildcard = $name === 'data-' || str_starts_with($name, 'data-');
            $baseAllowed = array_filter($allowed, static fn(string $item): bool => $item !== 'data-*');
            if (!$wildcard && !in_array($name, $baseAllowed, true)) {
                $remove[] = $name;
                continue;
            }
            if (in_array($name, self::URL_ATTRS, true) && !self::isSafeUrl($attribute->nodeValue ?? '')) {
                $remove[] = $name;
            }
            if ($name === 'style' && !self::isSafeStyle((string)$attribute->nodeValue)) {
                $remove[] = $name;
            }
        }
        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }
        if ($tag === 'a') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * URL aman untuk atribut seperti href/src. Skema `data:` hanya diizinkan
     * untuk gambar raster (png/jpeg/gif/webp) dalam bentuk base64 — bukan
     * `data:text/html` atau `data:image/svg+xml` yang dapat mengeksekusi
     * script saat dibuka langsung.
     */
    public static function isSafeUrl(string $url, array $schemes = ['http', 'https', 'mailto', 'tel']): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '#')) {
            return true;
        }
        // Hanya data-URI gambar raster base64 yang diizinkan.
        if (stripos($url, 'data:') === 0) {
            return (bool)preg_match(
                '/^data:image\/(png|jpe?g|gif|webp);base64,[a-z0-9+\/=\s]+$/i',
                $url
            );
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '' && !str_starts_with($url, '//')) {
            return true;
        }
        return in_array($scheme, $schemes, true);
    }

    private static function isSafeStyle(string $style): bool
    {
        return !preg_match('/expression|javascript:|url\s*\(|behavior|binding/i', $style);
    }

    public static function isSafeEmbed(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return $host !== '' && in_array($host, self::EMBED_HOSTS, true);
    }

    public static function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    }
}
