<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Str;

/**
 * Turns TMDB metadata into a ready-to-save blog post: SEO title, slug,
 * excerpt, meta description and an HTML body assembled from the sections the
 * editor chose to include.
 *
 * The output is deliberate semantic HTML (headings, lists, tables, figures) so
 * it inherits the active theme's typography instead of fighting it. No scripts
 * are embedded — only <img> markup pointing at the TMDB image CDN.
 */
final class TmdbArticle
{
    /** Default category every generated article is filed under. */
    public const DEFAULT_CATEGORY = 'movies';

    /** Sections the editor can toggle, in the order they appear in the body. */
    public const SECTIONS = [
        'poster' => 'Poster & informasi utama',
        'overview' => 'Sinopsis',
        'genres' => 'Genre',
        'cast' => 'Pemain',
        'crew' => 'Sutradara & kru',
        'facts' => 'Tabel fakta',
        'rating' => 'Rating & popularitas',
        'trailer' => 'Trailer (YouTube)',
        'providers' => 'Platform streaming',
        'recommendations' => 'Rekomendasi serupa',
        'attribution' => 'Atribusi TMDB (disarankan)',
    ];

    /**
     * Build the post payload from TMDB detail data.
     *
     * @param array<string, mixed> $details Response from Tmdb::details()
     * @param array<int, string>   $sections Enabled section keys
     * @return array{title:string, seo_title:string, slug:string, excerpt:string, meta_description:string, body:string, thumbnail:string, og_image:string, categories:array<int,string>, tags:array<int,string>, keywords:array<int,string>}
     */
    public static function build(array $details, array $sections = [], string $category = self::DEFAULT_CATEGORY, string $posterSize = 'w500'): array
    {
        $isTv = ($details['_media_type'] ?? 'movie') === 'tv';
        $title = self::title($details, $isTv);
        $year = self::year($details, $isTv);
        $sections = $sections ?: array_keys(self::SECTIONS);

        $overview = trim((string)($details['overview'] ?? ''));
        $genres = self::genres($details);
        $poster = Tmdb::image((string)($details['poster_path'] ?? ''), $posterSize);
        $backdrop = Tmdb::image((string)($details['backdrop_path'] ?? ''), 'w780');

        $pieces = [];
        foreach ($sections as $section) {
            $html = match ($section) {
                'poster' => self::posterBlock($details, $title, $isTv),
                'overview' => self::overviewBlock($overview),
                'genres' => self::genresBlock($genres),
                'cast' => self::castBlock($details),
                'crew' => self::crewBlock($details),
                'facts' => self::factsBlock($details, $isTv),
                'rating' => self::ratingBlock($details),
                'trailer' => self::trailerBlock($details),
                'providers' => self::providersBlock($details),
                'recommendations' => self::recommendationsBlock($details, $isTv),
                'attribution' => self::attributionBlock(),
                default => '',
            };
            if (trim($html) !== '') {
                $pieces[] = $html;
            }
        }

        $body = implode("\n", $pieces);
        $seoTitle = ($isTv ? 'Serial ' : 'Film ') . $title . ($year !== '' ? ' (' . $year . ')' : '');
        $seoTitle = Str::limit($seoTitle, 60);
        $excerpt = $overview !== '' ? Str::limit($overview, 180) : self::synopsisFallback($title, $year, $genres, $isTv);
        $metaDescription = $overview !== '' ? Str::limit($overview, 158) : $excerpt;

        return [
            'title' => self::clean($title . ($year !== '' ? ' (' . $year . ')' : '') . ' — ' . ($isTv ? 'Serial' : 'Film'), 190),
            'seo_title' => self::clean($seoTitle, 255),
            // Slug::make() runs again inside Post::save(); slugifying here keeps
            // the admin preview identical to what will actually be stored.
            'slug' => \App\Core\Slug::make($title . ($year !== '' ? '-' . $year : '')),
            'excerpt' => self::clean($excerpt, 500),
            'meta_description' => self::clean($metaDescription, 320),
            'body' => $body,
            'thumbnail' => $poster !== '' ? $poster : $backdrop,
            'og_image' => $backdrop !== '' ? $backdrop : $poster,
            'categories' => [self::clean($category, 190)],
            'tags' => self::tags($details, $isTv, $genres),
            'keywords' => $genres,
        ];
    }

    /**
     * Plain-text preview used by the admin screen before saving.
     *
     * @param array<string, mixed> $details
     */
    public static function preview(array $details): array
    {
        $isTv = ($details['_media_type'] ?? 'movie') === 'tv';
        return [
            'title' => self::title($details, $isTv),
            'year' => self::year($details, $isTv),
            'overview' => trim((string)($details['overview'] ?? '')),
            'poster' => Tmdb::image((string)($details['poster_path'] ?? ''), 'w342'),
            'backdrop' => Tmdb::image((string)($details['backdrop_path'] ?? ''), 'w780'),
            'genres' => self::genres($details),
            'runtime' => (int)($isTv ? ($details['number_of_seasons'] ?? 0) : ($details['runtime'] ?? 0)),
            'vote' => (float)($details['vote_average'] ?? 0),
            'votes' => (int)($details['vote_count'] ?? 0),
            'type' => $isTv ? 'tv' : 'movie',
            'tmdb_id' => (int)($details['id'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------
    // Field helpers
    // ------------------------------------------------------------------

    public static function title(array $details, bool $isTv): string
    {
        $title = (string)($details['title'] ?? $details['name'] ?? '');
        if ($title === '') {
            $title = (string)($details['original_title'] ?? $details['original_name'] ?? 'Tanpa judul');
        }
        return trim($title);
    }

    public static function year(array $details, bool $isTv): string
    {
        $date = (string)($isTv ? ($details['first_air_date'] ?? '') : ($details['release_date'] ?? ''));
        return substr($date, 0, 4);
    }

    /** @return array<int, string> */
    private static function genres(array $details): array
    {
        $names = [];
        foreach ((array)($details['genres'] ?? []) as $genre) {
            if (is_array($genre) && !empty($genre['name'])) {
                $names[] = (string)$genre['name'];
            }
        }
        return array_values(array_unique($names));
    }

    /** @return array<int, string> */
    private static function tags(array $details, bool $isTv, array $genres): array
    {
        $tags = $genres;
        $tags[] = $isTv ? 'Serial TV' : 'Film';
        $year = self::year($details, $isTv);
        if ($year !== '') {
            $tags[] = $year;
        }
        $tags[] = 'TMDB';
        return array_values(array_unique(array_filter($tags)));
    }

    private static function synopsisFallback(string $title, string $year, array $genres, bool $isTv): string
    {
        $kind = $isTv ? 'Serial TV' : 'Film';
        $meta = $year !== '' ? ' rilis ' . $year : '';
        $genreText = $genres ? ' bergenre ' . implode(', ', array_slice($genres, 0, 3)) : '';
        return $kind . ' ' . $title . $meta . $genreText . '. Ringkasan resmi belum tersedia di TMDB.';
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    private static function posterBlock(array $details, string $title, bool $isTv): string
    {
        $poster = Tmdb::image((string)($details['poster_path'] ?? ''), 'w500');
        if ($poster === '') {
            return '';
        }
        $year = self::year($details, $isTv);
        $caption = $title . ($year !== '' ? ' (' . $year . ')' : '');
        return '<figure class="tmd-poster"><img src="' . self::esc($poster) . '" alt="Poster ' . self::esc($caption) . '" width="500" loading="lazy" decoding="async">'
            . '<figcaption>' . self::esc($caption) . '</figcaption></figure>';
    }

    private static function overviewBlock(string $overview): string
    {
        if ($overview === '') {
            return '';
        }
        return '<h2>Sinopsis</h2><p>' . self::esc($overview) . '</p>';
    }

    private static function genresBlock(array $genres): string
    {
        if (!$genres) {
            return '';
        }
        return '<h2>Genre</h2><p>' . self::esc(implode(', ', $genres)) . '</p>';
    }

    private static function castBlock(array $details): string
    {
        $cast = array_slice((array)($details['credits']['cast'] ?? []), 0, 8);
        if (!$cast) {
            return '';
        }
        $items = '';
        foreach ($cast as $person) {
            $name = (string)($person['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $role = (string)($person['character'] ?? '');
            $items .= '<li><strong>' . self::esc($name) . '</strong>' . ($role !== '' ? ' — ' . self::esc($role) : '') . '</li>';
        }
        return $items !== '' ? '<h2>Pemain</h2><ul>' . $items . '</ul>' : '';
    }

    private static function crewBlock(array $details): string
    {
        $crew = (array)($details['credits']['crew'] ?? []);
        $wanted = [
            'Director' => 'Sutradara',
            'Screenplay' => 'Penulis naskah',
            'Writer' => 'Penulis',
            'Story' => 'Cerita',
            'Producer' => 'Produser',
            'Creator' => 'Pembuat',
            'Original Music Composer' => 'Musik',
            'Director of Photography' => 'Sinematografi',
        ];
        $rows = '';
        $used = [];
        foreach ($crew as $member) {
            $job = (string)($member['job'] ?? '');
            if (!isset($wanted[$job]) || isset($used[$job])) {
                continue;
            }
            $name = (string)($member['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rows .= '<tr><th scope="row">' . self::esc($wanted[$job]) . '</th><td>' . self::esc($name) . '</td></tr>';
            $used[$job] = true;
        }
        if ($rows === '') {
            return '';
        }
        return '<h2>Kru Produksi</h2><table><tbody>' . $rows . '</tbody></table>';
    }

    private static function factsBlock(array $details, bool $isTv): string
    {
        $rows = [];
        $title = self::title($details, $isTv);
        $rows[] = ['Judul asli', (string)($details['original_title'] ?? $details['original_name'] ?? '')];
        $rows[] = ['Tipe', $isTv ? 'Serial TV' : 'Film'];
        if ($isTv) {
            $rows[] = ['Tanggal tayang', (string)($details['first_air_date'] ?? '')];
            $rows[] = ['Jumlah musim', (string)($details['number_of_seasons'] ?? '')];
            $rows[] = ['Jumlah episode', (string)($details['number_of_episodes'] ?? '')];
            $rows[] = ['Status', (string)($details['status'] ?? '')];
            $networks = array_map(static fn($n): string => (string)($n['name'] ?? ''), (array)($details['networks'] ?? []));
            $networks = array_values(array_filter($networks));
            if ($networks) {
                $rows[] = ['Jaringan', implode(', ', $networks)];
            }
        } else {
            $rows[] = ['Tanggal rilis', (string)($details['release_date'] ?? '')];
            $runtime = (int)($details['runtime'] ?? 0);
            if ($runtime > 0) {
                $rows[] = ['Durasi', $runtime . ' menit'];
            }
            $rows[] = ['Status', (string)($details['status'] ?? '')];
        }
        $rows[] = ['Bahasa asli', (string)($details['original_language'] ?? '')];
        $rows[] = ['Negara asal', implode(', ', array_map(
            static fn($c): string => (string)($c['name'] ?? ''),
            (array)($details['production_countries'] ?? [])
        ))];
        $companies = array_map(static fn($c): string => (string)($c['name'] ?? ''), (array)($details['production_companies'] ?? []));
        $companies = array_values(array_filter($companies));
        if ($companies) {
            $rows[] = ['Produksi', implode(', ', array_slice($companies, 0, 5))];
        }
        $rows[] = ['ID TMDB', (string)($details['id'] ?? '')];

        $html = '';
        foreach ($rows as [$label, $value]) {
            $value = trim($value);
            if ($value === '' || $value === $title) {
                continue;
            }
            $html .= '<tr><th scope="row">' . self::esc($label) . '</th><td>' . self::esc($value) . '</td></tr>';
        }
        return $html !== '' ? '<h2>Informasi ' . ($isTv ? 'Serial' : 'Film') . '</h2><table><tbody>' . $html . '</tbody></table>' : '';
    }

    private static function ratingBlock(array $details): string
    {
        $vote = (float)($details['vote_average'] ?? 0);
        $count = (int)($details['vote_count'] ?? 0);
        $popularity = (float)($details['popularity'] ?? 0);
        if ($vote <= 0 && $count <= 0) {
            return '';
        }
        $parts = [];
        if ($vote > 0) {
            $parts[] = 'Rating <strong>' . number_format($vote, 1) . '/10</strong>';
        }
        if ($count > 0) {
            $parts[] = 'dari <strong>' . number_format($count) . '</strong> suara pengguna TMDB';
        }
        if ($popularity > 0) {
            $parts[] = 'popularitas <strong>' . number_format($popularity, 1) . '</strong>';
        }
        return '<h2>Rating &amp; Popularitas</h2><p>' . implode(', ', $parts) . '.</p>';
    }

    private static function trailerBlock(array $details): string
    {
        $videos = (array)($details['videos']['results'] ?? []);
        $pick = null;
        foreach (['Trailer', 'Teaser', 'Clip'] as $want) {
            foreach ($videos as $video) {
                if (!is_array($video)) {
                    continue;
                }
                if (strcasecmp((string)($video['site'] ?? ''), 'YouTube') !== 0) {
                    continue;
                }
                if (strcasecmp((string)($video['type'] ?? ''), $want) === 0) {
                    $pick = $video;
                    break 2;
                }
            }
        }
        if ($pick === null) {
            return '';
        }
        $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($pick['key'] ?? ''));
        if ($key === '') {
            return '';
        }
        $label = strcasecmp((string)($pick['type'] ?? ''), 'Trailer') === 0 ? 'Trailer' : 'Cuplikan';
        return '<h2>' . $label . '</h2><div class="tmd-video"><iframe src="https://www.youtube.com/embed/' . $key
            . '" width="560" height="315" loading="lazy" frameborder="0" allowfullscreen></iframe></div>';
    }

    private static function providersBlock(array $details): string
    {
        $providers = (array)($details['watch/providers']['results'] ?? []);
        $region = Tmdb::region();
        $entry = $providers[$region] ?? null;
        if (!is_array($entry)) {
            return '';
        }
        $groups = [
            'flatrate' => 'Streaming',
            'rent' => 'Sewa',
            'buy' => 'Beli',
            'free' => 'Gratis',
            'ads' => 'Gratis dengan iklan',
        ];
        $items = '';
        foreach ($groups as $key => $label) {
            $list = (array)($entry[$key] ?? []);
            if (!$list) {
                continue;
            }
            $names = [];
            foreach ($list as $provider) {
                if (is_array($provider) && !empty($provider['provider_name'])) {
                    $names[] = (string)$provider['provider_name'];
                }
            }
            if ($names) {
                $items .= '<li><strong>' . self::esc($label) . ':</strong> ' . self::esc(implode(', ', $names)) . '</li>';
            }
        }
        if ($items === '') {
            return '';
        }
        $link = (string)($entry['link'] ?? '');
        return '<h2>Platform Streaming (' . self::esc($region) . ')</h2><ul>' . $items . '</ul>'
            . '<p><small>Data penyedia streaming disediakan oleh JustWatch melalui TMDB.</small></p>'
            . ($link !== '' ? '<p><a href="' . self::esc($link) . '" rel="noopener noreferrer">Lihat ketersediaan di TMDB</a></p>' : '');
    }

    private static function recommendationsBlock(array $details, bool $isTv): string
    {
        $items = array_slice((array)($details['recommendations']['results'] ?? []), 0, 6);
        if (!$items) {
            return '';
        }
        $html = '<h2>Rekomendasi Serupa</h2><ul class="tmd-recs">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = (string)($item['title'] ?? $item['name'] ?? '');
            if ($title === '') {
                continue;
            }
            $year = substr((string)($item['release_date'] ?? $item['first_air_date'] ?? ''), 0, 4);
            $html .= '<li><strong>' . self::esc($title) . '</strong>' . ($year !== '' ? ' (' . self::esc($year) . ')' : '') . '</li>';
        }
        return $html . '</ul>';
    }

    private static function attributionBlock(): string
    {
        return '<p class="tmd-attribution"><small>Data dan gambar dari <a href="https://www.themoviedb.org/" rel="noopener noreferrer">TMDB</a>. '
            . 'Produk ini menggunakan API TMDB namun tidak didukung atau disertifikasi oleh TMDB.</small></p>';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * TMDB fields are plain text. Removed any markup a rogue value could carry
     * and collapse whitespace so the stored title/excerpt/meta stay clean.
     */
    private static function clean(string $value, int $max): string
    {
        $value = strip_tags($value);
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        return Str::limit($value, $max);
    }
}
