<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Config;
use App\Core\Settings;
use App\Core\ValidationException;

/**
 * The Movie Database (TMDB) API v3 client.
 *
 * Metadata and images only — TMDB never serves the films themselves. This
 * client is intentionally dependency free: it uses cURL when available and
 * falls back to the streams wrapper, mirroring the rest of the CMS which must
 * run on plain shared hosting.
 *
 * Every response is cached (TMDB asks integrators to cache and avoid repeat
 * calls) and each request carries the required attribution notice.
 *
 * Docs: https://developer.themoviedb.org/docs/getting-started
 *       https://developer.themoviedb.org/docs/append-to-response
 */
final class Tmdb
{
    public const IMAGE_BASE = 'https://image.tmdb.org/t/p/';

    /** Poster sizes accepted by the image CDN; used by the size picker. */
    public const POSTER_SIZES = ['w92', 'w154', 'w185', 'w342', 'w500', 'w780', 'original'];

    private const SETTING_KEY = 'tmdb_api_token';
    private const LANGUAGE_KEY = 'tmdb_language';
    private const REGION_KEY = 'tmdb_region';

    private string $token;
    private string $language;
    private string $region;

    public function __construct(?string $token = null, ?string $language = null, ?string $region = null)
    {
        $this->token = trim($token ?? self::storedToken());
        $this->language = trim($language ?? Settings::get(self::LANGUAGE_KEY, 'id-ID')) ?: 'id-ID';
        $this->region = strtoupper(trim($region ?? Settings::get(self::REGION_KEY, 'ID')) ?: 'ID');
    }

    // ------------------------------------------------------------------
    // Credentials
    // ------------------------------------------------------------------

    /**
     * Read the token from the per-blog settings table, falling back to an
     * environment variable so hosting operators can keep it out of the DB.
     */
    public static function storedToken(): string
    {
        $stored = Settings::get(self::SETTING_KEY, '');
        if ($stored !== '') {
            return $stored;
        }
        return (string)(getenv('TMDB_API_TOKEN') ?: '');
    }

    public static function saveToken(string $token, string $language = '', string $region = ''): void
    {
        Settings::set(self::SETTING_KEY, trim($token));
        if ($language !== '') {
            Settings::set(self::LANGUAGE_KEY, trim($language));
        }
        if ($region !== '') {
            Settings::set(self::REGION_KEY, strtoupper(trim($region)));
        }
        // A new token invalidates every cached response and failure marker,
        // so a corrected credential works on the very next request.
        Cache::flushGroup('tmdb');
    }

    public static function language(): string
    {
        return Settings::get(self::LANGUAGE_KEY, 'id-ID') ?: 'id-ID';
    }

    public static function region(): string
    {
        return Settings::get(self::REGION_KEY, 'ID') ?: 'ID';
    }

    public function hasCredentials(): bool
    {
        return $this->token !== '';
    }

    // ------------------------------------------------------------------
    // Catalog: paginated lists for movies and TV
    // ------------------------------------------------------------------

    /** Hard limit imposed by TMDB: no list endpoint serves past page 500. */
    public const MAX_PAGE = 500;

    /** Ordering options accepted by /discover/{movie,tv}. */
    public const SORTS = [
        'popularity.desc' => 'Paling populer',
        'vote_average.desc' => 'Rating tertinggi',
        'vote_count.desc' => 'Paling banyak diulas',
        'revenue.desc' => 'Pendapatan tertinggi',
        'primary_release_date.desc' => 'Terbaru (film)',
        'first_air_date.desc' => 'Terbaru (serial)',
    ];

    /**
     * One page of a curated list. `$kind` is a movie list (popular,
     * now_playing, upcoming, top_rated) or a TV list (tv_popular,
     * tv_airing_today, tv_on_the_air, tv_top_rated).
     *
     * @return array{page:int,total_pages:int,total_results:int,results:array<int,array<string,mixed>>}
     */
    public function browse(string $kind = 'popular', int $page = 1): array
    {
        $page = $this->clampPage($page);
        $map = [
            'popular' => ['/movie/popular', []],
            'now_playing' => ['/movie/now_playing', ['region' => $this->region]],
            'upcoming' => ['/movie/upcoming', ['region' => $this->region]],
            'top_rated' => ['/movie/top_rated', []],
            'tv_popular' => ['/tv/popular', []],
            'tv_airing_today' => ['/tv/airing_today', []],
            'tv_on_the_air' => ['/tv/on_the_air', []],
            'tv_top_rated' => ['/tv/top_rated', []],
        ];
        [$path, $extra] = $map[$kind] ?? $map['popular'];
        // TV list endpoints do not accept `region`; strip it defensively.
        $query = ['page' => $page] + $extra;
        return $this->paginated($this->get($path, $query), str_starts_with($kind, 'tv_') ? 'tv' : 'movie');
    }

    /**
     * Trending titles for a day/week window. When $mediaType is "movie" or
     * "tv" only that kind is returned; otherwise movies and TV are mixed.
     *
     * @return array{page:int,total_pages:int,total_results:int,results:array<int,array<string,mixed>>}
     */
    public function trending(string $window = 'week', int $page = 1, string $mediaType = 'all'): array
    {
        $window = $window === 'day' ? 'day' : 'week';
        $kind = in_array($mediaType, ['movie', 'tv'], true) ? $mediaType : 'all';
        $data = $this->get('/trending/' . $kind . '/' . $window, ['page' => $this->clampPage($page)]);
        return $this->paginated($data, $kind);
    }

    /**
     * /discover with an optional genre filter — the way to walk the whole
     * catalogue (a genre can be browsed far past page 500 of "popular").
     *
     * @return array{page:int,total_pages:int,total_results:int,results:array<int,array<string,mixed>>}
     */
    public function discover(string $type = 'movie', int $page = 1, int $genreId = 0, string $sort = 'popularity.desc'): array
    {
        $kind = $type === 'tv' ? 'tv' : 'movie';
        $sort = self::sortFor($sort, $kind);
        $query = [
            'page' => $this->clampPage($page),
            'sort_by' => $sort,
            'include_adult' => 'false',
        ];
        if ($genreId > 0) {
            $query['with_genres'] = $genreId;
        }
        // Discover honours a vote floor; without one the tail is full of
        // titles with a single vote that make poor articles.
        if ($sort === 'vote_average.desc') {
            $query['vote_count.gte'] = 200;
        }
        return $this->paginated($this->get('/discover/' . $kind, $query), $kind);
    }

    /**
     * Resolve a sort key for a media type. The date sorts only exist on one
     * side of the catalogue, so a mismatched key is swapped for the equivalent
     * field rather than sent as-is (TMDB rejects unknown sort_by values).
     */
    public static function sortFor(string $sort, string $type): string
    {
        $kind = $type === 'tv' ? 'tv' : 'movie';
        $aliases = ['primary_release_date.desc', 'first_air_date.desc'];
        if (in_array($sort, $aliases, true)) {
            return $kind === 'tv' ? 'first_air_date.desc' : 'primary_release_date.desc';
        }
        return isset(self::SORTS[$sort]) ? $sort : 'popularity.desc';
    }

    /**
     * Genre list for movies or TV, cached by TMDB's normal response cache.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function genres(string $type = 'movie'): array
    {
        $kind = $type === 'tv' ? 'tv' : 'movie';
        $data = $this->get('/genre/' . $kind . '/list', []);
        $out = [];
        foreach ((array)($data['genres'] ?? []) as $genre) {
            if (!is_array($genre)) {
                continue;
            }
            $id = (int)($genre['id'] ?? 0);
            $name = trim((string)($genre['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $out[] = ['id' => $id, 'name' => $name];
            }
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Text search across movies or TV shows.
     *
     * @return array{page:int,total_pages:int,total_results:int,results:array<int,array<string,mixed>>}
     */
    public function search(string $query, string $type = 'movie', int $page = 1): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new ValidationException('Kata kunci pencarian wajib diisi.');
        }
        $kind = $type === 'tv' ? 'tv' : 'movie';
        $data = $this->get('/search/' . $kind, [
            'query' => $query,
            'page' => $this->clampPage($page),
            'include_adult' => 'false',
        ]);
        return $this->paginated($data, $kind);
    }

    /**
     * Normalise a TMDB list response into a stable envelope and tag each item
     * with its media type so the UI never has to guess.
     *
     * @param array<string, mixed> $data
     * @return array{page:int,total_pages:int,total_results:int,results:array<int,array<string,mixed>>}
     */
    private function paginated(array $data, string $fallbackType): array
    {
        $results = [];
        foreach ((array)($data['results'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Trending/all already carries media_type; lists do not.
            $media = (string)($item['media_type'] ?? '');
            if (!in_array($media, ['movie', 'tv', 'person'], true)) {
                $media = $fallbackType === 'tv' ? 'tv' : 'movie';
            }
            $item['media_type'] = $media;
            $results[] = $item;
        }
        $totalPages = min(self::MAX_PAGE, max(1, (int)($data['total_pages'] ?? 1)));
        return [
            // Keep the reported page inside the window so a request past the
            // end (or past TMDB's 500 cap) still yields a usable pager.
            'page' => min($totalPages, max(1, (int)($data['page'] ?? 1))),
            'total_pages' => $totalPages,
            'total_results' => max(0, (int)($data['total_results'] ?? count($results))),
            'results' => $results,
        ];
    }

    private function clampPage(int $page): int
    {
        return max(1, min(self::MAX_PAGE, $page));
    }

    /**
     * Curated list kept for backwards compatibility with earlier callers;
     * delegates to browse() and returns just the items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(string $kind = 'popular', int $page = 1): array
    {
        return $this->browse($kind, $page)['results'];
    }

    /**
     * Full detail for one title, joining related data in a single request via
     * append_to_response (credits, videos, images, recommendations, keywords).
     *
     * @return array<string, mixed>
     */
    public function details(int $id, string $type = 'movie'): array
    {
        $kind = $type === 'tv' ? 'tv' : 'movie';
        $append = $kind === 'tv'
            ? 'credits,videos,images,recommendations,keywords,watch/providers,external_ids'
            : 'credits,videos,images,recommendations,keywords,watch/providers,release_dates,external_ids';
        $data = $this->get('/' . $kind . '/' . max(1, $id), [
            'append_to_response' => $append,
            'include_image_language' => 'id,en,null',
            // Without this, appended videos are filtered to the blog language,
            // so an Indonesian blog gets an empty list (and no trailer).
            'include_video_language' => $this->videoLanguages(),
        ]);
        $data['_media_type'] = $kind;
        return $data;
    }

    /**
     * Video languages to request alongside the details: the blog language,
     * English (TMDB hosts almost every official trailer in en) and the
     * language-neutral entries (`null`).
     */
    private function videoLanguages(): string
    {
        $codes = [
            strtolower(substr($this->language, 0, 2)),
            'en',
            'null',
        ];
        return implode(',', array_values(array_unique(array_filter($codes, static fn(string $c): bool => $c !== ''))));
    }

    /**
     * Configuration: image bases and size lists as published by TMDB.
     *
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->get('/configuration', []);
    }

    /**
     * Validate the current token with a cheap call. Returns the resolved
     * configuration on success so the caller can display a friendly status.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->configuration();
    }

    // ------------------------------------------------------------------
    // Presentation helpers
    // ------------------------------------------------------------------

    /** Build a full image URL from a TMDB path, or '' when no path is set. */
    public static function image(?string $path, string $size = 'w500'): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        $size = in_array($size, self::POSTER_SIZES, true) ? $size : 'w500';
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return self::IMAGE_BASE . $size . '/' . ltrim($path, '/');
    }

    /**
     * PNG attribution mark required by TMDB's terms, rendered inline so we do
     * not depend on an external request just to satisfy attribution.
     */
    public static function attribution(): string
    {
        return self::IMAGE_BASE . 'w45_and_h67_bestv2/3DDB1fFHUgPovzyE1Sw2m9KQjHU.png';
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * Perform an authenticated GET and decode JSON.
     *
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        if (!$this->hasCredentials()) {
            throw new ValidationException('Token TMDB belum diatur. Isi di panel kredensial generator.');
        }
        $query = array_filter($query + ['language' => $this->language], static fn($value): bool => $value !== '' && $value !== null);
        $base = rtrim((string)Config::get('tmdb_api_base', 'https://api.themoviedb.org/3'), '/');
        $url = $base . $path . '?' . http_build_query($query);
        $cacheKey = 'tmdb.' . hash('sha256', $this->token . '|' . $url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        if (Cache::enabled()) {
            $failedKey = $cacheKey . '.failed';
            if (Cache::get($failedKey) !== null) {
                throw new ValidationException('TMDB tidak dapat dihubungi baru-baru ini. Coba lagi beberapa saat lagi.');
            }
        }
        $response = $this->request($url);
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new ValidationException('Respons TMDB tidak valid.');
        }
        if ($response['status'] !== 200) {
            $message = (string)($data['status_message'] ?? 'Permintaan TMDB gagal.');
            // Only back off on transient failures (rate limit / server / network).
            // Auth problems must always surface so the token can be corrected.
            if (in_array($response['status'], [429, 500, 502, 503, 504], true)) {
                Cache::put($cacheKey . '.failed', 1, 45, 'tmdb');
            }
            throw new ValidationException('TMDB: ' . $message . ' (HTTP ' . $response['status'] . ').');
        }
        Cache::put($cacheKey, $data, 900, 'tmdb');
        return $data;
    }

    /**
     * TMDB accepts two credential shapes: a v4 read access token (a long JWT,
     * sent as a Bearer header) and the older v3 API key (32 hex characters,
     * sent as an `api_key` query parameter). The key itself is valid for both
     * accounts, so the shape decides how it must be presented.
     */
    private function isV3Key(): bool
    {
        return preg_match('/^[0-9a-f]{32}$/i', $this->token) === 1;
    }

    /**
     * @return array{status:int, body:string}
     */
    private function request(string $url): array
    {
        $headers = ['Accept: application/json'];
        if ($this->isV3Key()) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'api_key=' . rawurlencode($this->token);
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'CMS-TMDB-Generator/1.0',
            ]);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($body === false) {
                throw new ValidationException('Tidak dapat menghubungi TMDB: ' . ($error ?: 'koneksi gagal') . '.');
            }
            return ['status' => $status, 'body' => (string)$body];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ValidationException('Tidak dapat menghubungi TMDB (ekstensi HTTP tidak tersedia).');
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
