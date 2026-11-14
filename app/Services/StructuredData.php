<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Settings;
use App\Core\Theme;

/**
 * Structured Data (JSON-LD) engine.
 *
 * Every page gets a single `@graph` document in the document head, built from
 * the data the CMS already has (page meta, breadcrumbs, article body, theme
 * settings). Nothing is invented: a node is only emitted when the fields
 * Google requires for it are actually available, so the markup always matches
 * the visible content.
 *
 * The gallery names used below follow Google's "Structured data markup that
 * Google Search supports" catalogue, so the same vocabulary can be used in the
 * theme's `schema_pages` / `schema_site` settings.
 *
 * Themes opt into the extended graph by declaring a `structured_data` block in
 * theme.json. Without it only the always-on nodes are emitted (Organization,
 * WebSite, BlogPosting, BreadcrumbList), which keeps third-party themes byte
 * compatible with their previous output.
 *
 * @see https://developers.google.com/search/docs/appearance/structured-data/search-gallery
 */
final class StructuredData
{
    /** Flags recommended by Google for JSON-LD embedded in HTML. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

    /**
     * Gallery entry => schema.org type, used when a node is declared by hand in
     * the theme settings ("gallery" field).
     */
    public const GALLERY = [
        'Article' => 'Article',
        'Breadcrumb' => 'BreadcrumbList',
        'BreadcrumbList' => 'BreadcrumbList',
        'Carousel' => 'ItemList',
        'Course' => 'Course',
        'Course list' => 'ItemList',
        'Dataset' => 'Dataset',
        'Discussion Forum' => 'DiscussionForumPosting',
        'DiscussionForumPosting' => 'DiscussionForumPosting',
        'Education Q&A' => 'Quiz',
        'Employer Aggregate Rating' => 'AggregateRating',
        'Event' => 'Event',
        'Image Metadata' => 'ImageObject',
        'Job Posting' => 'JobPosting',
        'Local Business' => 'LocalBusiness',
        'Math Solver' => 'MathSolver',
        'Movie' => 'Movie',
        'Organization' => 'Organization',
        'Product' => 'Product',
        'Profile Page' => 'ProfilePage',
        'Q&A Page' => 'QAPage',
        'Recipe' => 'Recipe',
        'Review Snippet' => 'Review',
        'Software App' => 'SoftwareApplication',
        'SoftwareApplication' => 'SoftwareApplication',
        'Speakable' => 'SpeakableSpecification',
        'Subscription' => 'WebPageElement',
        'Vacation Rental' => 'VacationRental',
        'Video' => 'VideoObject',
        'VideoObject' => 'VideoObject',
    ];

    /**
     * The whole gallery. Types without a direct schema.org counterpart are
     * emitted as properties of another node (see the doc in themes/schema-theme).
     */
    public const GALLERY_COVERAGE = [
        'Article', 'Breadcrumb', 'Carousel', 'Course list', 'Dataset',
        'Discussion Forum', 'Education Q&A', 'Employer Aggregate Rating',
        'Event', 'Image Metadata', 'Job Posting', 'Local Business',
        'Math Solver', 'Movie', 'Organization', 'Product', 'Profile Page',
        'Q&A Page', 'Recipe', 'Review Snippet', 'Software App', 'Speakable',
        'Subscription', 'Vacation Rental', 'Video',
    ];

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** The complete JSON-LD document for one page. */
    public static function graph(array $page): array
    {
        $meta = (array)($page['meta'] ?? []);
        $site = self::site();
        $canonical = (string)($meta['canonical'] ?? $page['canonical'] ?? '');
        if ($canonical === '') {
            $canonical = $site['url'];
        }
        $route = (string)($page['route'] ?? '');
        $declaration = self::declaration();
        $extended = $declaration !== [];
        $article = $route === 'post' && !empty($meta['article']);

        $graph = [
            self::organization($site, $declaration),
            self::website($site, $declaration),
        ];

        if ($article) {
            $graph[] = self::article($page, $site, $canonical, $declaration);
        }

        if ($extended) {
            if ($article) {
                $graph[] = self::webPage($page, $site, $canonical, $declaration);
                $image = self::imageObject($page, $site, $canonical, $declaration);
                if ($image !== []) {
                    $graph[] = $image;
                }
                foreach (self::videoObjects($page, $site, $canonical) as $video) {
                    $graph[] = $video;
                }
                if (self::isMoviePost($page, $declaration)) {
                    $graph[] = self::movie($page, $site, $canonical, $declaration);
                }
            } elseif ($route === 'author' && !empty(self::schemaData($page)['author'])) {
                $graph[] = self::profilePage($page, $site, $canonical);
            } else {
                $list = self::itemList($page, $site, $canonical, $declaration);
                if ($list !== []) {
                    $graph[] = $list;
                }
            }
            foreach (self::declaredNodes($page, $site, $canonical) as $node) {
                $graph[] = $node;
            }
        }

        $crumbs = self::breadcrumbList($page, $site);
        if ($crumbs !== []) {
            $graph[] = $crumbs;
        }

        return ['@context' => 'https://schema.org', '@graph' => array_values($graph)];
    }

    /** `<script type="application/ld+json">…</script>` for the document head. */
    public static function script(array $page): string
    {
        $json = self::json($page);
        return $json === '' ? '' : '<script type="application/ld+json">' . $json . '</script>';
    }

    public static function json(array $page): string
    {
        return (string)json_encode(self::graph($page), self::JSON_FLAGS);
    }

    /** schema.org type for a gallery name, or null when unknown. */
    public static function schemaType(string $gallery): ?string
    {
        $key = trim($gallery);
        if (isset(self::GALLERY[$key])) {
            return self::GALLERY[$key];
        }
        foreach (self::GALLERY as $name => $type) {
            if (strcasecmp($name, $key) === 0) {
                return $type;
            }
        }
        return null;
    }

    /** Gallery entries the extended engine covers. */
    public static function coverage(): array
    {
        return self::GALLERY_COVERAGE;
    }

    // ------------------------------------------------------------------
    // Site level nodes
    // ------------------------------------------------------------------

    private static function site(): array
    {
        $blog = Context::blog();
        $siteName = Settings::get('site_name', (string)($blog['name'] ?? 'Blog'));
        return [
            'url' => url(),
            'name' => $siteName,
            'description' => Settings::get('seo_description', Settings::get('site_description', '')),
            'logo' => Settings::get('organization_logo', ''),
            'twitter' => Settings::get('twitter_site', ''),
            'lang' => \App\Core\Lang::htmlLocale(),
        ];
    }

    private static function organization(array $site, array $declaration): array
    {
        $locale = Settings::get('language', 'id');
        $name = trim(Settings::get('organization_name', ''));
        if ($name === '') {
            $name = $site['name'];
        }
        $type = self::themeSetting($declaration, 'org_type', 'Organization');
        if (!in_array($type, ['Organization', 'LocalBusiness', 'OnlineBusiness'], true)) {
            $type = 'Organization';
        }
        $org = [
            '@type' => $type,
            '@id' => $site['url'] . '#organization',
            'name' => $name,
            'url' => $site['url'],
        ];
        $logo = $site['logo'];
        if ($logo !== '') {
            $org['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }
        if ($declaration === []) {
            return $org;
        }

        if ($site['description'] !== '') {
            $org['description'] = $site['description'];
        }
        $sameAs = self::linkList(self::themeSetting($declaration, 'social_links', ''));
        if ($sameAs !== []) {
            $org['sameAs'] = $sameAs;
        }
        $email = self::themeSetting($declaration, 'org_email', '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $org['email'] = $email;
        }
        $phone = self::themeSetting($declaration, 'org_phone', '');
        if ($phone !== '') {
            $org['telephone'] = $phone;
        }
        $address = self::postalAddress($declaration);
        if ($address !== []) {
            $org['address'] = $address;
        }
        $geo = self::geo($declaration);
        if ($geo !== []) {
            $org['geo'] = $geo;
        }
        $priceRange = self::themeSetting($declaration, 'org_price_range', '');
        if ($priceRange !== '') {
            $org['priceRange'] = $priceRange;
        }
        $hours = self::themeSetting($declaration, 'org_opening_hours', '');
        if ($hours !== '') {
            $org['openingHours'] = self::lines($hours);
        }
        // "Employer Aggregate Rating": ratings about the organization itself.
        $rating = self::aggregateRating(
            self::themeSetting($declaration, 'org_rating_value', ''),
            self::themeSetting($declaration, 'org_rating_count', '')
        );
        if ($rating !== []) {
            $org['aggregateRating'] = $rating;
        }
        if ($locale !== '') {
            $org['knowsLanguage'] = strtoupper($locale) === 'ID' ? 'id-ID' : $site['lang'];
        }
        return $org;
    }

    private static function website(array $site, array $declaration): array
    {
        $website = [
            '@type' => 'WebSite',
            '@id' => $site['url'] . '#website',
            'url' => $site['url'],
            'name' => $site['name'],
            'publisher' => ['@id' => $site['url'] . '#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => url('search?q={search_term_string}')],
                'query-input' => 'required name=search_term_string',
            ],
        ];
        if ($declaration !== []) {
            $website['inLanguage'] = $site['lang'];
            if ($site['description'] !== '') {
                $website['description'] = $site['description'];
            }
        }
        return $website;
    }

    // ------------------------------------------------------------------
    // Page level nodes
    // ------------------------------------------------------------------

    private static function article(array $page, array $site, string $canonical, array $declaration): array
    {
        $meta = (array)($page['meta'] ?? []);
        $article = (array)$meta['article'];
        $type = 'BlogPosting';
        if ($declaration !== []) {
            $configured = self::themeSetting($declaration, 'article_type', 'BlogPosting');
            if (in_array($configured, ['BlogPosting', 'NewsArticle', 'Article', 'TechArticle', 'Report'], true)) {
                $type = $configured;
            }
        }
        $author = self::authorName($meta);
        $node = [
            '@type' => $type,
            '@id' => $canonical . '#article',
            'headline' => self::text((string)$article['title']),
            'description' => (string)($meta['description'] ?? ''),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
            'image' => ($meta['image'] ?? '') !== '' ? [(string)$meta['image']] : [],
            'datePublished' => (string)($meta['published'] ?? ''),
            'dateModified' => (string)($meta['modified'] ?? '') !== '' ? (string)$meta['modified'] : (string)($meta['published'] ?? ''),
            'author' => ['@type' => 'Person', 'name' => $author],
            'publisher' => ['@id' => $site['url'] . '#organization'],
            'wordCount' => str_word_count(strip_tags((string)($article['body'] ?? ''))),
            'articleSection' => array_values((array)($article['categories'] ?? [])),
        ];
        if ($declaration !== [] && $author !== '') {
            $node['author']['url'] = \App\Core\Helpers::authorUrl($author);
            $node['inLanguage'] = $site['lang'];
        }
        $paywall = self::paywall($page, $declaration);
        if ($paywall !== []) {
            $node['isAccessibleForFree'] = false;
            $node['hasPart'] = $paywall;
        }
        return $node;
    }

    private static function webPage(array $page, array $site, string $canonical, array $declaration): array
    {
        $meta = (array)($page['meta'] ?? []);
        $node = [
            '@type' => 'WebPage',
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => self::text((string)($meta['title'] ?? '')),
            'isPartOf' => ['@id' => $site['url'] . '#website'],
            'inLanguage' => $site['lang'],
            'datePublished' => (string)($meta['published'] ?? ''),
            'dateModified' => (string)($meta['modified'] ?? '') !== '' ? (string)$meta['modified'] : (string)($meta['published'] ?? ''),
        ];
        if (($meta['image'] ?? '') !== '') {
            $node['primaryImageOfPage'] = ['@id' => $canonical . '#primaryimage'];
        }
        // "Speakable": the parts of the page a voice assistant should read.
        $selectors = self::selectorList(self::themeSetting($declaration, 'speakable_selectors', ''));
        if ($selectors === []) {
            $selectors = (array)($declaration['speakable_selectors'] ?? []);
        }
        if ($selectors !== [] && ($declaration['speakable'] ?? true) !== false) {
            $node['speakable'] = [
                '@type' => 'SpeakableSpecification',
                'cssSelector' => array_values($selectors),
            ];
        }
        $paywall = self::paywall($page, $declaration);
        if ($paywall !== []) {
            $node['isAccessibleForFree'] = false;
            $node['hasPart'] = $paywall;
        }
        return self::prune($node);
    }

    private static function imageObject(array $page, array $site, string $canonical, array $declaration): array
    {
        if (($declaration['image_metadata'] ?? true) === false) {
            return [];
        }
        $meta = (array)($page['meta'] ?? []);
        $image = (string)($meta['image'] ?? '');
        if ($image === '') {
            return [];
        }
        $author = self::authorName($meta);
        $node = [
            '@type' => 'ImageObject',
            '@id' => $canonical . '#primaryimage',
            'url' => $image,
            'contentUrl' => $image,
            'caption' => self::text((string)($meta['title'] ?? '')),
            'representativeOfPage' => true,
            'creator' => $author !== ''
                ? ['@type' => 'Person', 'name' => $author]
                : ['@id' => $site['url'] . '#organization'],
            'creditText' => $author !== '' ? $author : $site['name'],
            'copyrightNotice' => $site['name'],
        ];
        $license = self::themeSetting($declaration, 'image_license', '');
        if ($license === '' && isset($declaration['image_license'])) {
            $license = (string)$declaration['image_license'];
        }
        if ($license !== '' && filter_var($license, FILTER_VALIDATE_URL)) {
            $node['license'] = $license;
            $node['acquireLicensePage'] = $canonical;
        }
        return self::prune($node);
    }

    /** VideoObject for every YouTube/Vimeo embed found in the article body. */
    private static function videoObjects(array $page, array $site, string $canonical): array
    {
        $meta = (array)($page['meta'] ?? []);
        $body = (string)($page['context']['body'] ?? $meta['article']['body'] ?? '');
        if ($body === '' || stripos($body, '<iframe') === false) {
            return [];
        }
        if (!preg_match_all('#<iframe\b[^>]*>#i', $body, $frames)) {
            return [];
        }
        $videos = [];
        $seen = [];
        foreach ($frames[0] as $frame) {
            $src = '';
            if (preg_match('#src\s*=\s*"([^"]+)"#i', $frame, $match) || preg_match("#src\s*=\s*'([^']+)'#i", $frame, $match)) {
                $src = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
            }
            $embed = self::embedInfo($src);
            if ($embed === null || isset($seen[$embed['id']])) {
                continue;
            }
            $seen[$embed['id']] = true;
            $title = '';
            if (preg_match('#title\s*=\s*"([^"]*)"#i', $frame, $match)) {
                $title = self::text($match[1]);
            }
            $videos[] = self::prune([
                '@type' => 'VideoObject',
                '@id' => $canonical . '#video-' . $embed['id'],
                'name' => $title !== '' ? $title : self::text((string)($meta['title'] ?? '')),
                'description' => (string)($meta['description'] ?? ''),
                'thumbnailUrl' => [$embed['thumbnail']],
                'uploadDate' => (string)($meta['published'] ?? ''),
                'embedUrl' => $embed['embed'],
                'isPartOf' => ['@id' => $canonical . '#webpage'],
                'publisher' => ['@id' => $site['url'] . '#organization'],
            ]);
            if (count($videos) >= 3) {
                break;
            }
        }
        return $videos;
    }

    /** ItemList (carousel) built from the posts listed on an archive page. */
    private static function itemList(array $page, array $site, string $canonical, array $declaration): array
    {
        $items = (array)(self::schemaData($page)['items'] ?? []);
        if (count($items) < 2) {
            return [];
        }
        $movieMode = self::taxonomyMatches($page, self::movieCategories($declaration));
        $elements = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item) || (string)($item['url'] ?? '') === '') {
                continue;
            }
            $node = self::prune([
                '@type' => $movieMode ? 'Movie' : 'Article',
                'name' => self::text((string)($item['name'] ?? '')),
                'url' => (string)$item['url'],
                'image' => (string)($item['image'] ?? ''),
                'datePublished' => !$movieMode ? (string)($item['date'] ?? '') : '',
                'dateCreated' => $movieMode ? (string)($item['date'] ?? '') : '',
            ]);
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => $node,
            ];
        }
        if (count($elements) < 2) {
            return [];
        }
        return [
            '@type' => 'ItemList',
            '@id' => $canonical . '#itemlist',
            'name' => self::text((string)($page['meta']['title'] ?? '')),
            'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
    }

    private static function profilePage(array $page, array $site, string $canonical): array
    {
        $author = (array)(self::schemaData($page)['author'] ?? []);
        $name = self::text((string)($author['name'] ?? ''));
        if ($name === '') {
            return [];
        }
        $person = self::prune([
            '@type' => 'Person',
            '@id' => (string)($author['url'] ?? $canonical) . '#person',
            'name' => $name,
            'url' => (string)($author['url'] ?? $canonical),
            'description' => self::text((string)($author['bio'] ?? '')),
            'image' => (string)($author['image'] ?? ''),
            'worksFor' => ['@id' => $site['url'] . '#organization'],
            'mainEntityOfPage' => ['@id' => $canonical . '#profilepage'],
        ]);
        return [
            '@type' => 'ProfilePage',
            '@id' => $canonical . '#profilepage',
            'url' => $canonical,
            'name' => $name,
            'isPartOf' => ['@id' => $site['url'] . '#website'],
            'inLanguage' => $site['lang'],
            'mainEntity' => $person,
        ];
    }

    private static function movie(array $page, array $site, string $canonical, array $declaration): array
    {
        $meta = (array)($page['meta'] ?? []);
        $article = (array)($meta['article'] ?? []);
        $node = [
            '@type' => 'Movie',
            '@id' => $canonical . '#movie',
            'name' => self::text((string)($article['title'] ?? $meta['title'] ?? '')),
            'url' => $canonical,
            'description' => (string)($meta['description'] ?? ''),
            'dateCreated' => (string)($meta['published'] ?? ''),
        ];
        if (($meta['image'] ?? '') !== '') {
            $node['image'] = (string)$meta['image'];
        }
        $director = [];
        foreach (self::lines(self::themeSetting($declaration, 'movie_directors', '')) as $name) {
            $director[] = ['@type' => 'Person', 'name' => $name];
        }
        if ($director !== []) {
            $node['director'] = count($director) === 1 ? $director[0] : $director;
        }
        $rating = self::aggregateRating(
            self::themeSetting($declaration, 'movie_rating_value', ''),
            self::themeSetting($declaration, 'movie_rating_count', '')
        );
        if ($rating !== []) {
            $node['aggregateRating'] = $rating;
        }
        return self::prune($node);
    }

    private static function breadcrumbList(array $page, array $site): array
    {
        $crumbs = (array)($page['breadcrumbs'] ?? []);
        if ($crumbs === []) {
            return [];
        }
        $elements = [];
        foreach (array_values($crumbs) as $index => $crumb) {
            if (!is_array($crumb)) {
                continue;
            }
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => self::text((string)($crumb['name'] ?? '')),
                'item' => (string)($crumb['url'] ?? $site['url']),
            ];
        }
        return $elements === [] ? [] : [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    // ------------------------------------------------------------------
    // Hand-declared nodes (theme settings)
    // ------------------------------------------------------------------

    /**
     * Nodes typed by the editor in the theme settings:
     *  - `schema_site`  → JSON object/array merged into every page
     *  - `schema_pages` → JSON array of {"path": "/resep/x", "gallery": "Recipe", "data": {…}}
     */
    private static function declaredNodes(array $page, array $site, string $canonical): array
    {
        $theme = Theme::instance();
        $nodes = [];
        foreach (self::jsonNodes($theme->setting('schema_site')) as $node) {
            $clean = self::withDefaults($node, $page, $site, $canonical);
            if ($clean !== []) {
                $nodes[] = $clean;
            }
        }
        $path = (string)(parse_url($canonical, PHP_URL_PATH) ?: '/');
        foreach (self::jsonNodes($theme->setting('schema_pages')) as $entry) {
            $pattern = (string)($entry['path'] ?? '');
            if ($pattern === '' || !self::pathMatches($path, $pattern)) {
                continue;
            }
            $type = self::schemaType((string)($entry['gallery'] ?? $entry['type'] ?? ''));
            if ($type === null) {
                continue;
            }
            $data = (array)($entry['data'] ?? []);
            $clean = self::withDefaults(['@type' => $type] + $data, $page, $site, $canonical);
            if ($clean !== []) {
                $nodes[] = $clean;
            }
        }
        return $nodes;
    }

    /**
     * Types that inherit from CreativeWork, so the page defaults (author,
     * dates, publisher, …) are valid properties for them.
     */
    private const CREATIVE_TYPES = [
        'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report',
        'Movie', 'Recipe', 'Review', 'Course', 'Dataset', 'Quiz',
        'DiscussionForumPosting', 'ImageObject', 'VideoObject',
        'SoftwareApplication', 'MathSolver', 'WebPage', 'ProfilePage',
        'QAPage', 'WebPageElement', 'Blog', 'LiveBlogPosting',
    ];

    /**
     * Add just the page defaults the node type accepts. CreativeWork keeps its
     * author/dates/publisher; other types (Event, Product, Organization, …)
     * only inherit the shared name/url/description so the markup stays valid.
     */
    private static function withDefaults(array $node, array $page, array $site, string $canonical): array
    {
        if (!isset($node['@type']) || (string)$node['@type'] === '') {
            return [];
        }
        $meta = (array)($page['meta'] ?? []);
        $author = self::authorName($meta);
        $defaults = [
            'url' => $canonical,
            'name' => self::text((string)($meta['title'] ?? '')),
            'description' => (string)($meta['description'] ?? ''),
        ];
        if (in_array((string)$node['@type'], self::CREATIVE_TYPES, true)) {
            $defaults['datePublished'] = (string)($meta['published'] ?? '');
            $defaults['dateModified'] = (string)($meta['modified'] ?? '') !== '' ? (string)$meta['modified'] : (string)($meta['published'] ?? '');
            $defaults['inLanguage'] = $site['lang'];
            $defaults['publisher'] = ['@id' => $site['url'] . '#organization'];
            if ($author !== '') {
                $defaults['author'] = ['@type' => 'Person', 'name' => $author];
            }
            if (($meta['image'] ?? '') !== '') {
                $defaults['image'] = [(string)$meta['image']];
            }
        }
        $node += $defaults;
        return self::prune($node);
    }

    private static function pathMatches(string $path, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern === '*') {
            return $pattern === '*';
        }
        $needle = '/' . ltrim($pattern, '/');
        if (!str_contains($needle, '*')) {
            return rtrim($path, '/') === rtrim($needle, '/') || $path === $needle;
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($needle, '#')) . '$#i';
        return preg_match($regex, $path) === 1;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Extra per-request hints provided by the controller. They may arrive at
     * the top level (`schema`) or inside the resolved meta payload.
     */
    private static function schemaData(array $page): array
    {
        $schema = $page['schema'] ?? null;
        if (!is_array($schema) || $schema === []) {
            $schema = $page['meta']['schema'] ?? [];
        }
        return is_array($schema) ? $schema : [];
    }

    /** Theme declaration (`structured_data` in theme.json), or [] when opted out. */
    private static function declaration(): array    {
        try {
            $meta = Theme::instance()->metadata();
        } catch (\Throwable) {
            return [];
        }
        $declaration = $meta['structured_data'] ?? [];
        if (!is_array($declaration) || $declaration === []) {
            return [];
        }
        return ($declaration['enabled'] ?? true) === false ? [] : $declaration;
    }

    /** Theme setting with the declaration used as the default. */
    private static function themeSetting(array $declaration, string $key, string $default = ''): string
    {
        if ($declaration === []) {
            return '';
        }
        $fallback = isset($declaration[$key]) && is_scalar($declaration[$key]) ? (string)$declaration[$key] : $default;
        return Theme::instance()->setting($key, $fallback);
    }

    /**
     * Categories that mark a post as a movie. The editor sets them as a comma
     * separated list; the theme.json declaration is the fallback.
     */
    private static function movieCategories(array $declaration): array
    {
        $configured = self::selectorList(self::themeSetting($declaration, 'movie_categories', ''));
        if ($configured !== []) {
            return $configured;
        }
        return array_map('strval', (array)($declaration['movie_categories'] ?? []));
    }

    private static function paywall(array $page, array $declaration): array
    {
        if ($declaration === [] || ($declaration['paywall'] ?? false) !== true) {
            return [];
        }
        $visibility = (string)(self::schemaData($page)['post']['visibility'] ?? '');
        $selector = self::themeSetting($declaration, 'paywall_selector', '');
        if ($visibility !== 'password' && $selector === '') {
            return [];
        }
        if ($selector === '') {
            $selector = (string)($declaration['paywall_selector'] ?? '.article-body');
        }
        return [[
            '@type' => 'WebPageElement',
            'isAccessibleForFree' => false,
            'cssSelector' => $selector,
        ]];
    }

    private static function isMoviePost(array $page, array $declaration): bool
    {
        $meta = (array)($page['meta'] ?? []);
        $categories = array_map('strval', (array)($meta['article']['categories'] ?? []));
        $slugs = array_map('strval', (array)(self::schemaData($page)['post']['category_slugs'] ?? []));
        return self::matchesAny(array_merge($categories, $slugs), self::movieCategories($declaration));
    }

    private static function taxonomyMatches(array $page, array $values): bool
    {
        if ($values === []) {
            return false;
        }
        $label = (string)($page['breadcrumbs'][1]['name'] ?? '');
        return self::matchesAny([(string)($page['slug'] ?? ''), $label], $values);
    }

    private static function matchesAny(array $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = mb_strtolower(trim((string)$needle));
            if ($needle === '') {
                continue;
            }
            foreach ($haystack as $value) {
                if (mb_strtolower(trim((string)$value)) === $needle) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function authorName(array $meta): string
    {
        return self::text((string)($meta['author'] ?? ''));
    }

    /** Embed host + thumbnail for a known video provider, or null. */
    private static function embedInfo(string $src): ?array
    {
        if ($src === '') {
            return null;
        }
        $url = $src;
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $host = (string)preg_replace('/^www\./', '', $host);
        $id = '';
        if ($host === 'youtube.com' || $host === 'youtube-nocookie.com' || $host === 'm.youtube.com') {
            if (preg_match('#/embed/([A-Za-z0-9_-]{6,})#', $url, $m) || preg_match('#/v/([A-Za-z0-9_-]{6,})#', $url, $m)) {
                $id = $m[1];
            } else {
                parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $query);
                $id = (string)($query['v'] ?? '');
            }
            if ($id === '') {
                return null;
            }
            return [
                'id' => 'youtube-' . $id,
                'embed' => 'https://www.youtube.com/embed/' . $id,
                'thumbnail' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
            ];
        }
        if ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{6,})#', (string)(parse_url($url, PHP_URL_PATH) ?: ''), $m)) {
            return [
                'id' => 'youtube-' . $m[1],
                'embed' => 'https://www.youtube.com/embed/' . $m[1],
                'thumbnail' => 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg',
            ];
        }
        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            if (preg_match('#/(?:video/)?(\d{6,})#', $url, $m)) {
                return [
                    'id' => 'vimeo-' . $m[1],
                    'embed' => 'https://player.vimeo.com/video/' . $m[1],
                    'thumbnail' => '',
                ];
            }
        }
        return null;
    }

    private static function aggregateRating(string $value, string $count): array
    {
        $ratingValue = (float)str_replace(',', '.', trim($value));
        $ratingCount = (int)$count;
        if ($ratingValue <= 0 || $ratingCount <= 0) {
            return [];
        }
        return [
            '@type' => 'AggregateRating',
            'ratingValue' => round(min(5, max(1, $ratingValue)), 1),
            'ratingCount' => $ratingCount,
            'bestRating' => 5,
            'worstRating' => 1,
        ];
    }

    private static function postalAddress(array $declaration): array
    {
        $street = self::themeSetting($declaration, 'org_street', '');
        $city = self::themeSetting($declaration, 'org_city', '');
        $region = self::themeSetting($declaration, 'org_region', '');
        $postalCode = self::themeSetting($declaration, 'org_postal_code', '');
        if (trim($street . $city . $region . $postalCode) === '') {
            return [];
        }
        return self::prune([
            '@type' => 'PostalAddress',
            'streetAddress' => $street,
            'addressLocality' => $city,
            'addressRegion' => $region,
            'postalCode' => $postalCode,
            'addressCountry' => self::themeSetting($declaration, 'org_country', ''),
        ]);
    }

    private static function geo(array $declaration): array
    {
        $lat = self::themeSetting($declaration, 'org_latitude', '');
        $lng = self::themeSetting($declaration, 'org_longitude', '');
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return [];
        }
        return ['@type' => 'GeoCoordinates', 'latitude' => (float)$lat, 'longitude' => (float)$lng];
    }

    /** One URL per line (or comma separated). */
    private static function linkList(string $raw): array
    {
        $links = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $url) {
            $url = trim($url);
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $links[] = $url;
            }
        }
        return array_values(array_unique($links));
    }

    private static function lines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    private static function selectorList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\n]+/', $raw) ?: [] as $selector) {
            $selector = trim($selector);
            if ($selector !== '') {
                $out[] = $selector;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Decode a hand-written JSON setting into a list of node arrays. A single
     * object is accepted as a one element list; invalid JSON yields [].
     */
    private static function jsonNodes(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return [];
        }
        if (isset($decoded['@type']) || isset($decoded['gallery']) || isset($decoded['path'])) {
            return [$decoded];
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && (isset($entry['@type']) || isset($entry['gallery']))) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** Drop null/empty leaves so the graph stays clean and easy to audit. */
    private static function prune(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $value = self::prune($value);
                if ($value === []) {
                    continue;
                }
            } elseif ($value === '' || $value === null || $value === []) {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private static function text(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
