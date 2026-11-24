<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Slug;
use App\Core\Str;

/**
 * Turns AVDB metadata into a ready-to-save blog post: SEO title, slug, excerpt,
 * meta description and an HTML body assembled from the sections the editor
 * chose.
 *
 * The output is plain semantic HTML (headings, lists, tables, figure) so it
 * inherits the active theme's typography. Only <img> tags point at the
 * provider's CDN; the stream link is rendered as a normal anchor (never an
 * iframe), so a third-party player cannot script the page.
 */
final class AvdbArticle
{
    /** Default category every generated article is filed under. */
    public const DEFAULT_CATEGORY = 'av';

    /** Sections the editor can toggle, in the order they appear in the body. */
    public const SECTIONS = [
        'poster' => 'Poster & kode',
        'details' => 'Tabel informasi',
        'description' => 'Sinopsis',
        'categories' => 'Kategori',
        'cast' => 'Pemain',
        'crew' => 'Sutradara',
        'watch' => 'Tautan tonton',
        'attribution' => 'Atribusi AVDB (disarankan)',
    ];

    /**
     * Build the post payload from an AVDB list/detail record.
     *
     * @param array<string, mixed> $item     One record from Avdb::details()/search()
     * @param array<int, string>   $sections Enabled section keys
     * @return array{title:string, seo_title:string, slug:string, excerpt:string, meta_description:string, body:string, thumbnail:string, og_image:string, categories:array<int,string>, tags:array<int,string>, keywords:array<int,string>}
     */
    public static function build(array $item, array $sections = [], string $category = self::DEFAULT_CATEGORY): array
    {
        $sections = $sections ?: array_keys(self::SECTIONS);
        $code = self::code($item);
        $title = self::title($item);
        $description = self::description($item);
        $categories = self::categories($item);
        $poster = Avdb::image((string)($item['poster_url'] ?? ''));
        $thumb = Avdb::image((string)($item['thumb_url'] ?? ''));

        $pieces = [];
        foreach ($sections as $section) {
            $html = match ($section) {
                'poster' => self::posterBlock($item, $title, $code, $poster),
                'details' => self::detailsBlock($item, $code),
                'description' => self::descriptionBlock($description),
                'categories' => self::categoriesBlock($categories),
                'cast' => self::listBlock('Pemain', (array)($item['actor'] ?? [])),
                'crew' => self::listBlock('Sutradara', (array)($item['director'] ?? [])),
                'watch' => self::watchBlock($item, $title),
                'attribution' => self::attributionBlock(),
                default => '',
            };
            if (trim($html) !== '') {
                $pieces[] = $html;
            }
        }

        $body = implode("\n", $pieces);
        $seoTitle = $code !== '' ? $code . ' — ' . Str::limit($title, 48) : Str::limit($title, 60);
        $seoTitle = self::clean($seoTitle, 60);
        $excerpt = $description !== ''
            ? Str::limit($description, 180)
            : self::fallback($title, $code, $categories);

        return [
            'title' => self::clean($title, 190),
            'seo_title' => self::clean($seoTitle, 255),
            // Slug::make() runs again inside Post::save(); slugifying here keeps
            // the preview identical to what will be stored.
            'slug' => Slug::make((string)($item['slug'] ?? '') ?: ($code !== '' ? $code : $title)),
            'excerpt' => self::clean($excerpt, 500),
            'meta_description' => self::clean(Str::limit($excerpt, 158), 320),
            'body' => $body,
            'thumbnail' => $poster !== '' ? $poster : $thumb,
            'og_image' => $thumb !== '' ? $thumb : $poster,
            'categories' => [self::clean($category, 190)],
            'tags' => self::tags($item, $categories, $code),
            'keywords' => $categories,
        ];
    }

    /**
     * Plain-text preview used by the admin screen before saving.
     *
     * @param array<string, mixed> $item
     */
    public static function preview(array $item): array
    {
        return [
            'id' => (int)($item['id'] ?? 0),
            'code' => self::code($item),
            'title' => self::title($item),
            'type_name' => trim((string)($item['type_name'] ?? '')),
            'year' => self::year($item),
            'poster' => Avdb::image((string)($item['poster_url'] ?? '')),
            'thumb' => Avdb::image((string)($item['thumb_url'] ?? '')),
            'categories' => self::categories($item),
            'actors' => self::names((array)($item['actor'] ?? [])),
            'directors' => self::names((array)($item['director'] ?? [])),
            'duration' => trim((string)($item['time'] ?? '')),
            'quality' => trim((string)($item['quality'] ?? '')),
            'status' => trim((string)($item['status'] ?? '')),
            'description' => self::description($item),
        ];
    }

    // ------------------------------------------------------------------
    // Field helpers
    // ------------------------------------------------------------------

    public static function code(array $item): string
    {
        $code = trim((string)($item['movie_code'] ?? ''));
        if ($code === '') {
            $code = strtoupper(trim((string)($item['slug'] ?? '')));
        }
        return $code;
    }

    public static function title(array $item): string
    {
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($item['origin_name'] ?? ''));
        }
        if ($name === '') {
            $name = self::code($item);
        }
        return $name !== '' ? $name : 'Tanpa judul';
    }

    public static function year(array $item): string
    {
        $year = trim((string)($item['year'] ?? ''));
        if ($year !== '') {
            return $year;
        }
        return substr(trim((string)($item['created_at'] ?? '')), 0, 4);
    }

    public static function description(array $item): string
    {
        return trim((string)($item['description'] ?? ''));
    }

    /**
     * Category/tag list. The API also repeats the type (e.g. "Uncensored
     * Leaked") inside it, so it is deduplicated against type_name.
     *
     * @return array<int, string>
     */
    public static function categories(array $item): array
    {
        $type = trim((string)($item['type_name'] ?? ''));
        $names = [];
        foreach ((array)($item['category'] ?? []) as $entry) {
            $entry = trim((string)$entry);
            if ($entry === '' || ($type !== '' && strcasecmp($entry, $type) === 0)) {
                continue;
            }
            $names[] = $entry;
        }
        return array_values(array_unique($names));
    }

    /** @return array<int, string> */
    private static function names(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '' && strcasecmp($value, 'updating') !== 0) {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<int, string> $categories
     * @return array<int, string>
     */
    private static function tags(array $item, array $categories, string $code): array
    {
        $tags = array_merge(
            array_slice($categories, 0, 8),
            array_slice(self::names((array)($item['actor'] ?? [])), 0, 3)
        );
        $type = trim((string)($item['type_name'] ?? ''));
        if ($type !== '') {
            $tags[] = $type;
        }
        $year = self::year($item);
        if ($year !== '') {
            $tags[] = $year;
        }
        $quality = trim((string)($item['quality'] ?? ''));
        if ($quality !== '') {
            $tags[] = $quality;
        }
        if ($code !== '') {
            $tags[] = $code;
        }
        $tags[] = 'AVDB';

        $out = [];
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = Str::limit($tag, 60);
            }
        }
        return array_slice($out, 0, 15);
    }

    private static function fallback(string $title, string $code, array $categories): string
    {
        $meta = $code !== '' ? ' (' . $code . ')' : '';
        $genre = $categories ? ' Kategori: ' . implode(', ', array_slice($categories, 0, 3)) . '.' : '';
        return $title . $meta . '.' . $genre . ' Ringkasan dari AVDB belum tersedia.';
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    private static function posterBlock(array $item, string $title, string $code, string $poster): string
    {
        if ($poster === '') {
            return '';
        }
        $caption = $code !== '' ? $code : $title;
        $year = self::year($item);
        $caption .= $year !== '' ? ' (' . $year . ')' : '';
        return '<figure class="avdb-poster"><img src="' . self::esc($poster) . '" alt="Poster ' . self::esc($caption) . '" loading="lazy" decoding="async">'
            . '<figcaption>' . self::esc($caption) . '</figcaption></figure>';
    }

    private static function detailsBlock(array $item, string $code): string
    {
        $rows = [
            ['Kode', $code],
            ['Jenis', trim((string)($item['type_name'] ?? ''))],
            ['Tahun', self::year($item)],
            ['Tanggal rilis', trim((string)($item['vod_pubdate'] ?? $item['created_at'] ?? ''))],
            ['Durasi', trim((string)($item['time'] ?? ''))],
            ['Kualitas', trim((string)($item['quality'] ?? ''))],
            ['Status', trim((string)($item['status'] ?? ''))],
            ['Negara', implode(', ', self::names((array)($item['country'] ?? [])))],
            ['Pemain', implode(', ', self::names((array)($item['actor'] ?? [])))],
            ['Sutradara', implode(', ', self::names((array)($item['director'] ?? [])))],
            ['ID AVDB', (string)($item['id'] ?? '')],
        ];
        $html = '';
        foreach ($rows as [$label, $value]) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $html .= '<tr><th scope="row">' . self::esc($label) . '</th><td>' . self::esc($value) . '</td></tr>';
        }
        return $html !== '' ? '<h2>Informasi</h2><table><tbody>' . $html . '</tbody></table>' : '';
    }

    private static function descriptionBlock(string $description): string
    {
        return $description === '' ? '' : '<h2>Sinopsis</h2><p>' . self::esc($description) . '</p>';
    }

    /** @param array<int, string> $categories */
    private static function categoriesBlock(array $categories): string
    {
        return $categories === [] ? '' : '<h2>Kategori</h2><p>' . self::esc(implode(', ', $categories)) . '</p>';
    }

    /** @param array<int, mixed> $values */
    private static function listBlock(string $heading, array $values): string
    {
        $items = '';
        foreach (self::names($values) as $name) {
            $items .= '<li>' . self::esc($name) . '</li>';
        }
        return $items !== '' ? '<h2>' . self::esc($heading) . '</h2><ul>' . $items . '</ul>' : '';
    }

    /**
     * The provider's player endpoint. Rendered as a link with nofollow so a
     * third-party page is never embedded in the article itself.
     */
    private static function watchBlock(array $item, string $title): string
    {
        $url = Avdb::streamUrl($item);
        if ($url === '' || !valid_asset_url($url)) {
            return '';
        }
        return '<h2 class="avdb-watch-heading">Tonton</h2>'
            . '<p class="avdb-watch"><a href="' . self::esc($url) . '" rel="nofollow noopener noreferrer" target="_blank">'
            . self::esc('Buka pemutar — ' . Str::limit($title, 70)) . '</a></p>'
            . '<p><small>Tautan pemutar disediakan oleh penyedia data AVDB. Konten berada di luar situs ini.</small></p>';
    }

    private static function attributionBlock(): string
    {
        return '<p class="avdb-attribution"><small>Data dan gambar dari <a href="https://avdbapi.com/" rel="noopener noreferrer nofollow">AVDBAPI</a>.</small></p>';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * AVDB fields are plain text. Strip any markup a rogue value could carry
     * and collapse whitespace so stored title/excerpt/meta stay clean.
     */
    private static function clean(string $value, int $max): string
    {
        $value = strip_tags($value);
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        return Str::limit($value, $max);
    }
}
