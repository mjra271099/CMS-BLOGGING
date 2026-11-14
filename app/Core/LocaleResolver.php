<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Picks the front-end language for a visitor automatically.
 *
 * Order of evidence, strongest first:
 *   1. A country header set by the CDN/reverse proxy in front of the site
 *      (Cloudflare's CF-IPCountry, CloudFront's CloudFront-Viewer-Country, …).
 *   2. The visitor's primary Accept-Language range.
 *   3. The language configured for the blog.
 *
 * Only visibility into the visitor's own request is used: nothing is stored
 * and no third-party geolocation service is called, so the result is the same
 * for every page view and works on plain PHP hosting.
 */
final class LocaleResolver
{
    /** Header names carrying an ISO-3166 country code, in preference order. */
    private const COUNTRY_HEADERS = [
        'HTTP_CF_IPCOUNTRY',
        'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
        'HTTP_X_VERCEL_IP_COUNTRY',
        'HTTP_X_COUNTRY_CODE',
        'HTTP_X_GEOIP_COUNTRY',
    ];

    /** Countries where English is the primary language of the audience. */
    private const ENGLISH_COUNTRIES = [
        'US', 'GB', 'UK', 'AU', 'CA', 'NZ', 'IE', 'ZA', 'SG', 'PH', 'MY',
        'IN', 'NG', 'KE', 'GH', 'JM', 'TT', 'BZ', 'GY', 'FJ', 'PG', 'MT',
    ];

    /** Countries where Indonesian is the primary language of the audience. */
    private const INDONESIAN_COUNTRIES = ['ID', 'TL'];

    /** Accept-Language primary subtags that map straight onto a locale. */
    private const SUBTAG_MAP = ['id' => 'id', 'in' => 'id', 'en' => 'en'];

    public static function resolve(string $fallback): string
    {
        $fallback = self::normalise($fallback);

        $country = self::country();
        if ($country !== null) {
            if (in_array($country, self::INDONESIAN_COUNTRIES, true)) {
                return 'id';
            }
            if (in_array($country, self::ENGLISH_COUNTRIES, true)) {
                return 'en';
            }
            // A known country outside both lists: its Accept-Language header is
            // a better signal than guessing, so fall through to it.
        }

        $preferred = self::preferredFromAcceptLanguage();
        if ($preferred !== null) {
            return $preferred;
        }

        return $fallback;
    }

    /**
     * Country code from a proxy header, or null when none is present.
     * Cloudflare uses "XX" (unknown) and "T1" (Tor), both of which are ignored.
     */
    public static function country(): ?string
    {
        foreach (self::COUNTRY_HEADERS as $header) {
            $value = strtoupper(trim((string)($_SERVER[$header] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $value) === 1 && $value !== 'XX') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Best supported locale from the Accept-Language header, honouring the
     * q-values browsers send. Returns null when nothing supported is listed.
     */
    public static function preferredFromAcceptLanguage(?string $header = null): ?string
    {
        $header ??= (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if (trim($header) === '') {
            return null;
        }

        $ranges = [];
        foreach (explode(',', $header) as $position => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = explode(';', $part);
            $tag = strtolower(trim((string)array_shift($bits)));
            $quality = 1.0;
            foreach ($bits as $bit) {
                $bit = strtolower(trim($bit));
                if (str_starts_with($bit, 'q=')) {
                    $quality = (float)substr($bit, 2);
                }
            }
            if ($quality <= 0.0) {
                continue;
            }
            $primary = substr(trim(explode('-', $tag)[0]), 0, 2);
            if (isset(self::SUBTAG_MAP[$primary])) {
                // Higher q wins; ties keep the browser's left-to-right order.
                $ranges[] = ['q' => $quality, 'order' => $position, 'locale' => self::SUBTAG_MAP[$primary]];
            }
        }

        if ($ranges === []) {
            return null;
        }
        usort($ranges, static fn(array $a, array $b): int => [$b['q'], $a['order']] <=> [$a['q'], $b['order']]);
        return (string)$ranges[0]['locale'];
    }

    private static function normalise(string $locale): string
    {
        $locale = strtolower(substr(trim($locale), 0, 2));
        return isset(Lang::SUPPORTED[$locale]) ? $locale : 'id';
    }
}
