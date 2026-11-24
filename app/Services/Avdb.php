<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Config;
use App\Core\Settings;
use App\Core\ValidationException;

/**
 * AVDB API client (avdbapi.com).
 *
 * The API is free and needs no key, so unlike {@see Tmdb} there are no
 * credentials to store — only a preferred output language. Metadata and cover
 * images only; the client never fetches media files.
 *
 * Responses are cached because the catalogue is huge and the UI pages through
 * it quickly. Text searches are rate limited by the provider (30-45 requests
 * per minute depending on the field), so the cache also keeps the generator
 * well inside that budget.
 *
 * Docs: https://avdbapi.com/index.php/label/help.html
 */
final class Avdb
{
    public const BASE = 'https://avdbapi.com';

    /** Grid size. Kept small: a detail page carries long descriptions. */
    public const PAGE_SIZE = 24;

    /**
     * The provider does not cap pagination, but walking tens of thousands of
     * pages is never useful for a blog. This keeps the pager finite and honest.
     */
    public const MAX_PAGE = 1000;

    /**
     * Language prefixes documented by the API. English is the default and is
     * served without a prefix; it also carries the most complete metadata,
     * because some localised records arrive without a description.
     */
    public const LANGUAGES = [
        'en' => 'English',
        'id' => 'Bahasa Indonesia',
        'ms' => 'Bahasa Melayu',
        'th' => 'ไทย',
        'vi' => 'Tiếng Việt',
        'ko' => '한국어',
        'ja' => '日本語',
        'zh' => '中文',
        'hi' => 'हिन्दी',
        'ar' => 'العربية',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'es' => 'Español',
        'pt' => 'Português',
        'ru' => 'Русский',
        'tr' => 'Türkçe',
        'tl' => 'Filipino',
    ];

    /** Category type IDs accepted by the `t` parameter. */
    public const TYPES = [
        0 => 'Semua kategori',
        1 => 'Censored',
        2 => 'Uncensored',
        3 => 'Uncensored Leaked',
        4 => 'Amateur',
        5 => 'Chinese AV',
        6 => 'Hentai',
        7 => 'English Subtitle',
    ];

    /**
     * Search parameters and the label shown in the picker. Each maps to a
     * documented query key; `wd` also matches JAV codes.
     */
    public const SEARCH_FIELDS = [
        'wd' => 'Judul / kode',
        'code' => 'Kode (persis)',
        'actor' => 'Aktor',
        'director' => 'Sutradara',
        'category' => 'Kategori',
    ];

    public const SORTS = [
        'desc' => 'Terbaru dulu',
        'asc' => 'Terlama dulu',
    ];

    private const LANGUAGE_KEY = 'avdb_language';

    private string $language;

    public function __construct(?string $language = null)
    {
        $language = strtolower(trim($language ?? self::storedLanguage()));
        $this->language = isset(self::LANGUAGES[$language]) ? $language : 'en';
    }

    // ------------------------------------------------------------------
    // Preferences
    // ------------------------------------------------------------------

    public static function storedLanguage(): string
    {
        $stored = strtolower(trim((string)Settings::get(self::LANGUAGE_KEY, 'en')));
        if (isset(self::LANGUAGES[$stored])) {
            return $stored;
        }
        $env = strtolower(trim((string)(getenv('AVDB_LANGUAGE') ?: '')));
        return isset(self::LANGUAGES[$env]) ? $env : 'en';
    }

    public static function saveLanguage(string $language): void
    {
        $language = strtolower(trim($language));
        if (!isset(self::LANGUAGES[$language])) {
            throw new ValidationException('Bahasa AVDB tidak dikenal.');
        }
        Settings::set(self::LANGUAGE_KEY, $language);
        // Translations are baked into the cached responses, so a language
        // change must not keep serving the previous language.
        Cache::flushGroup('avdb');
    }

    public function language(): string
    {
        return $this->language;
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    /**
     * Latest (or oldest) videos, optionally restricted to one category type.
     *
     * @return array{page:int,total_pages:int,total_results:int,results:array<int,array<string,mixed>>}
     */
    public function browse(int $type = 0, int $page = 1, string $sort = 'desc', string $year = ''): array
    {
        $query = [
            'ac' => 'detail',
            'pg' => $this->clampPage($page),
            'pagesize' => self::PAGE_SIZE,
            'sort_direction' => $this->sortFor($sort),
        ];
        if (isset(self::TYPES[$type]) && $type > 0) {
            $query['t'] = $type;
        }
        $year = $this->yearFilter($year);
        if ($year !== '') {
            $query['year'] = $year;
        }
        return $this->paginated($this->get($query));
    }

    /**
     * Text search. `$field` must be one of SEARCH_FIELDS; anything else is
     * treated as the general keyword search so a stale URL cannot 400.
     *
     * @return array{page:int,total_pages:int,total_results:int,results:array<int,array<string,mixed>>}
     */
    public function search(string $field, string $query, int $page = 1, int $type = 0, string $sort = 'desc'): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new ValidationException('Kata kunci pencarian wajib diisi.');
        }
        if (!isset(self::SEARCH_FIELDS[$field])) {
            $field = 'wd';
        }
        $params = [
            'ac' => 'detail',
            $field => $query,
            'pg' => $this->clampPage($page),
            'pagesize' => self::PAGE_SIZE,
            'sort_direction' => $this->sortFor($sort),
        ];
        if (isset(self::TYPES[$type]) && $type > 0) {
            $params['t'] = $type;
        }
        return $this->paginated($this->get($params));
    }

    /**
     * Full detail for one record.
     *
     * @return array<string, mixed>
     */
    public function details(int $id): array
    {
        if ($id <= 0) {
            throw new ValidationException('ID AVDB tidak valid.');
        }
        $data = $this->get(['ac' => 'detail', 'ids' => $id]);
        foreach ((array)($data['list'] ?? []) as $item) {
            if (is_array($item) && (int)($item['id'] ?? 0) === $id) {
                return $item;
            }
        }
        throw new ValidationException('Judul AVDB #' . $id . ' tidak ditemukan.');
    }

    /**
     * Cheap health check. Returns the number of records reported so the admin
     * can see the connection really works, not just that an HTTP 200 arrived.
     *
     * @return array{total:int,page:int,language:string}
     */
    public function ping(): array
    {
        $data = $this->get(['ac' => 'detail', 'pagesize' => 1]);
        return [
            'total' => max(0, (int)($data['total'] ?? 0)),
            'page' => max(1, (int)($data['page'] ?? 1)),
            'language' => $this->language,
        ];
    }

    /**
     * Normalise the list envelope. The API reports `pagecount`/`total`; a
     * missing or absurd value must still yield a usable pager.
     *
     * @param array<string, mixed> $data
     * @return array{page:int,total_pages:int,total_results:int,results:array<int,array<string,mixed>>}
     */
    private function paginated(array $data): array
    {
        $results = [];
        foreach ((array)($data['list'] ?? []) as $item) {
            if (is_array($item)) {
                $results[] = $item;
            }
        }
        $totalPages = min(self::MAX_PAGE, max(1, (int)($data['pagecount'] ?? 1)));
        return [
            'page' => min($totalPages, max(1, (int)($data['page'] ?? 1))),
            'total_pages' => $totalPages,
            'total_results' => max(0, (int)($data['total'] ?? count($results))),
            'results' => $results,
        ];
    }

    private function clampPage(int $page): int
    {
        return max(1, min(self::MAX_PAGE, $page));
    }

    private function sortFor(string $sort): string
    {
        return $sort === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Accept "2024" or "2020-2024" only; anything else is dropped rather than
     * forwarded, so a hand-edited URL cannot inject query junk.
     */
    private function yearFilter(string $year): string
    {
        $year = trim($year);
        return preg_match('/^\d{4}(?:-\d{4})?$/', $year) === 1 ? $year : '';
    }

    // ------------------------------------------------------------------
    // Presentation helpers
    // ------------------------------------------------------------------

    /** Return an https image URL, or '' when the value is unusable. */
    public static function image(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return '';
        }
        return $url;
    }

    /**
     * Read the embed/stream link out of the `episodes` object. Views can hold
     * several server entries; the first usable one wins. The result is embedded
     * in an iframe by {@see AvdbArticle}.
     */
    public static function streamUrl(array $item): string
    {
        $servers = (array)($item['episodes']['server_data'] ?? []);
        foreach ($servers as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            // A server entry may be a single episode or a list of them.
            $candidates = isset($entry['link_embed']) ? [$entry] : $entry;
            foreach ((array)$candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $url = trim((string)($candidate['link_embed'] ?? ''));
                if ($url !== '' && preg_match('~^https?://~i', $url)) {
                    return $url;
                }
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * Build the endpoint for the current language. English is the default and
     * therefore has no prefix.
     */
    private function endpoint(): string
    {
        $root = rtrim((string)Config::get('avdb_api_base', self::BASE), '/');
        $path = '/api.php/provide/vod';
        if ($this->language !== 'en') {
            $path = '/' . rawurlencode($this->language) . $path;
        }
        return $root . $path;
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function get(array $query): array
    {
        $url = $this->endpoint() . '?' . http_build_query($query);
        $cacheKey = 'avdb.' . hash('sha256', $url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if (Cache::enabled() && Cache::get($cacheKey . '.failed') !== null) {
            throw new ValidationException('AVDB tidak dapat dihubungi baru-baru ini. Coba lagi beberapa saat lagi.');
        }

        $response = $this->request($url);
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new ValidationException('Respons AVDB tidak valid.');
        }
        if ($response['status'] !== 200) {
            // Rate limits are transient; auth does not exist here, so every
            // non-200 is treated as a provider/network problem.
            Cache::put($cacheKey . '.failed', 1, 30, 'avdb');
            $message = (string)($data['msg'] ?? 'Permintaan AVDB gagal.');
            throw new ValidationException('AVDB: ' . $message . ' (HTTP ' . $response['status'] . ').');
        }
        if ((int)($data['code'] ?? 0) !== 1) {
            $message = (string)($data['msg'] ?? 'Respons AVDB tidak dikenali.');
            throw new ValidationException('AVDB: ' . $message . '.');
        }
        Cache::put($cacheKey, $data, 900, 'avdb');
        return $data;
    }

    /**
     * @return array{status:int, body:string}
     */
    private function request(string $url): array
    {
        $headers = ['Accept: application/json'];
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'CMS-AVDB-Generator/1.0',
            ]);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($body === false) {
                throw new ValidationException('Tidak dapat menghubungi AVDB: ' . ($error ?: 'koneksi gagal') . '.');
            }
            return ['status' => $status, 'body' => (string)$body];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 25,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ValidationException('Tidak dapat menghubungi AVDB (ekstensi HTTP tidak tersedia).');
        }
        $status = 200;
        foreach ((array)($http_response_header ?? []) as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$line, $match)) {
                $status = (int)$match[1];
            }
        }
        return ['status' => $status, 'body' => (string)$body];
    }
}
