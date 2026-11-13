<?php
declare(strict_types=1);

namespace App\Core;

/**
 * URL/date formatting helpers shared by the front controller, theme UI kit
 * and legacy-compatible global functions.
 *
 * The canonical URL shapes intentionally mirror Blogger so existing links,
 * search engine indexes and feeds keep working:
 *   post  → /YYYY/MM/slug.html
 *   page  → /p/slug.html
 *   label → /search/label/slug
 */
final class Helpers
{
    public static function e(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = '';
        }
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function postUrl(array $post): string
    {
        $timestamp = self::timestamp((string)($post['published_at'] ?? ''));
        if ($timestamp === 0) {
            $timestamp = self::timestamp((string)($post['created_at'] ?? '')) ?: time();
        }
        $slug = rawurlencode((string)($post['slug'] ?? 'post'));
        return url(gmdate('Y/m/', $timestamp) . $slug . '.html');
    }

    public static function pageUrl(array $page): string
    {
        $slug = (string)($page['slug'] ?? '');
        if ($slug === '') {
            return url();
        }
        return url('p/' . rawurlencode($slug) . '.html');
    }

    public static function categoryUrl(array $category): string
    {
        return url('search/label/' . rawurlencode((string)($category['slug'] ?? '')));
    }

    public static function authorUrl(string $name): string
    {
        return url('author/' . rawurlencode(Slug::make($name, 'author')));
    }

    public static function date(string $datetime, string $format = 'd M Y'): string
    {
        $timestamp = self::timestamp($datetime);
        if ($timestamp <= 0) {
            return '';
        }
        $formatted = date($format, $timestamp);
        // Localise month names for the two supported front-end languages.
        $locale = \App\Core\Lang::locale();
        if ($locale !== 'en') {
            $months = \App\Core\Lang::catalog($locale)['months'] ?? [];
            $short = \App\Core\Lang::catalog($locale)['months_short'] ?? [];
            if ($months !== []) {
                $long = [];
                $shortMap = [];
                for ($i = 1; $i <= 12; $i++) {
                    $long[date('F', mktime(0, 0, 0, $i, 1))] = (string)($months[$i - 1] ?? '');
                    $shortMap[date('M', mktime(0, 0, 0, $i, 1))] = (string)($short[$i - 1] ?? '');
                }
                $formatted = strtr($formatted, $long);
                // Only swap abbreviations as whole words (d M Y, etc.).
                $formatted = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', array_keys($shortMap))) . ')\b/', static fn(array $m): string => $shortMap[$m[1]], $formatted) ?? $formatted;
            }
        }
        return $formatted;
    }

    public static function isoDate(string $datetime): string
    {
        $timestamp = self::timestamp($datetime);
        return $timestamp > 0 ? gmdate('c', $timestamp) : '';
    }

    public static function timeAgo(string $datetime): string
    {
        $timestamp = self::timestamp($datetime);
        if ($timestamp <= 0) {
            return '';
        }
        $diff = time() - $timestamp;
        if ($diff < 60) {
            return __('just_now');
        }
        $units = [
            31536000 => 'year_short',
            2592000 => 'month_short',
            604800 => 'week_short',
            86400 => 'day_short',
            3600 => 'hour_short',
            60 => 'minute_short',
        ];
        foreach ($units as $seconds => $key) {
            $value = (int)floor($diff / $seconds);
            if ($value > 0) {
                return __('ago', ['value' => $value, 'unit' => __($key)]);
            }
        }
        return __('just_now');
    }

    public static function safeHref(string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '//') || str_starts_with($href, '#')) {
            return $href === '' ? '#' : ($href[0] === '#' ? $href : '#');
        }
        $scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
        return $scheme === '' || in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $href : '#';
    }

    public static function validAssetUrl(string $value): bool
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

    public static function validSiteUrl(string $value): bool
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

    /**
     * Normalise a datetime to a UNIX timestamp (UTC storage, server display).
     */
    public static function timestamp(string $datetime): int
    {
        $datetime = trim($datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return 0;
        }
        $timestamp = strtotime($datetime . ' UTC');
        return $timestamp === false ? 0 : $timestamp;
    }

    /**
     * Resolve a stored/relative asset path to a public URL.
     */
    public static function mediaUrl(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }
        return url($path);
    }

    /**
     * Canonical share/feed text for a post.
     */
    public static function excerpt(array $post, int $length = 180): string
    {
        $source = (string)($post['excerpt'] ?? '');
        return Str::excerpt($source !== '' ? $source : (string)($post['body'] ?? ''), $length);
    }
}
