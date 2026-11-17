<?php
declare(strict_types=1);

/**
 * Self-contained integration test runner (no PHPUnit required).
 *
 *   CMS_CONFIG=config.local.php php tests/run.php
 *
 * The runner uses the configured database and creates isolated `zztest-*`
 * records, then removes them. It refuses to run when the database is not
 * installed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Context;
use App\Core\Database;
use App\Core\Html;
use App\Core\Settings;
use App\Core\Slug;
use App\Core\Str;
use App\Core\Theme;
use App\Core\ThemeEngine;
use App\Core\Widget;
use App\Models\Comment;
use App\Models\Page;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Services\Builder;
use App\Services\QrPage;
use App\Services\CvMakerPage;
use App\Services\SalesOrder;
use App\Services\ToolConfig;
use App\Services\Seo;
use App\Services\StructuredData;
use App\Services\TmdbArticle;

if (!Database::tableExists('users')) {
    fwrite(STDERR, "Database belum diinstal. Jalankan bin/console.php install.\n");
    exit(1);
}

$blogId = (int)(Database::value('SELECT id FROM blogs ORDER BY is_primary DESC, id LIMIT 1') ?: 0);
if ($blogId <= 0) {
    fwrite(STDERR, "Tidak ada blog di database.\n");
    exit(1);
}
Context::forceBlog($blogId);

$passed = 0;
$failed = 0;
$failures = [];
$createdPostIds = [];
$createdPageIds = [];
$createdCategoryIds = [];
$createdCommentIds = [];
$createdWidgetIds = [];
$createdRedirectIds = [];
$originalTheme = Theme::instance()->slug();
// Guard rails so a failure in the middle of the theme/structured-data tests
// still leaves the site, its theme and its theme settings untouched.
$themeSwitched = false;
$sdSettingsWritten = false;
$sdBackup = [];

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    $failures[] = $name . ($detail !== '' ? ' — ' . $detail : '');
    echo "  FAIL {$name}" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function section(string $name): void
{
    echo "\n== {$name} ==\n";
}

try {
    section('Core utilities');
    check('Slug::make normalises text', Slug::make('Halo Dunia!') === 'halo-dunia');
    check('Slug::make handles empty input', Slug::make('') !== '');
    check('Str::limit truncates', mb_strlen(Str::limit(str_repeat('a', 50), 20)) <= 23);
    check('Str::readingTime is positive', Str::readingTime(str_repeat('word ', 400)) >= 1);
    check('Html::sanitize strips scripts', !str_contains(strtolower(Html::sanitize('<p onclick="x()">Hi</p><script>alert(1)</script>')), '<script'));
    check('e() escapes HTML', e('<b>"x"</b>') === '&lt;b&gt;&quot;x&quot;&lt;/b&gt;');

    section('Settings + cache');
    // Blog context must apply the configured timezone to the PHP process.
    $blogTz = (string)(\App\Core\Context::blog()['timezone'] ?? '');
    check('Blog timezone is applied to PHP', $blogTz === '' || date_default_timezone_get() === $blogTz);
    Settings::set('zztest_setting', 'nilai-uji');
    check('Settings::set/get round-trip', Settings::get('zztest_setting') === 'nilai-uji');
    Settings::forget('zztest_setting');
    check('Settings::forget removes key', Settings::get('zztest_setting', 'fallback') === 'fallback');
    Cache::put('zztest.cache', ['ok' => true], 60);
    check('Cache::set/get round-trip', Cache::get('zztest.cache') === ['ok' => true]);
    Cache::forget('zztest.cache');
    check('Cache::forget removes key', Cache::get('zztest.cache') === null);
    // Bulk reset must preserve the active theme and integration secrets.
    $prevTheme = Settings::get('theme', '');
    $prevToken = Settings::get('tmdb_api_token', '');
    $prevPerPage = Settings::get('posts_per_page', '9');
    $prevSiteName = Settings::get('site_name', '');
    Settings::set('site_name', 'ZZ Reset Probe');
    Settings::set('theme', 'amp-theme');
    Settings::set('tmdb_api_token', 'secret-token-xyz');
    Settings::reset();
    check('Settings::reset restores default', Settings::get('site_name') === Settings::defaults()['site_name']);
    check('Settings::reset keeps active theme', Settings::get('theme') === 'amp-theme');
    check('Settings::reset keeps integration secrets', Settings::get('tmdb_api_token') === 'secret-token-xyz');
    // Group reset only touches the named keys.
    Settings::set('site_name', 'ZZ Group Probe');
    Settings::set('posts_per_page', '42');
    Settings::reset(['site_name']);
    check('Settings::reset(keys) restores that key', Settings::get('site_name') === Settings::defaults()['site_name']);
    check('Settings::reset(keys) leaves others untouched', Settings::get('posts_per_page') === '42');
    // Restore the real values we probed.
    Settings::set('theme', $prevTheme);
    if ($prevToken === '') {
        Settings::forget('tmdb_api_token');
    } else {
        Settings::set('tmdb_api_token', $prevToken);
    }
    Settings::set('posts_per_page', $prevPerPage);
    Settings::set('site_name', $prevSiteName);

    section('Posts');
    $postId = Post::save([
        'title' => 'ZZ Test Post',
        'slug' => 'zztest-post-' . bin2hex(random_bytes(3)),
        'body' => '<p>Isi uji otomatis.</p>',
        'excerpt' => 'Ringkasan uji.',
        'status' => 'draft',
        'author_id' => 1,
    ], ['ZZ Test Category'], ['zz-test-tag']);
    $createdPostIds[] = $postId;
    check('Post::save creates a post', $postId > 0);
    $post = Post::find($postId);
    check('Post::find returns the post', is_array($post) && (int)$post['id'] === $postId);
    check('Post slug is normalised', (string)$post['slug'] === Slug::make((string)$post['slug']));
    check('Post categories linked', Taxonomy::postCategories($postId) !== []);
    check('Post tags linked', Taxonomy::postTags($postId) !== []);
    $updated = Post::save([
        'id' => $postId,
        'title' => 'ZZ Test Post Updated',
        'slug' => (string)$post['slug'],
        'body' => '<p>Diperbarui.</p>',
        'status' => 'published',
        'author_id' => 1,
    ]);
    check('Post::save updates existing post', $updated === $postId);
    $published = Post::find($postId);
    check('Post status updated', (string)$published['status'] === 'published');
    check('Post::findBySlug works', Post::findBySlug((string)$published['slug']) !== null);
    check('Post::query searches title', Post::query(['status' => 'all', 'search' => 'ZZ Test Post Updated'], 5) !== []);

    section('Pages');
    $pageId = Page::save([
        'title' => 'ZZ Test Page',
        'slug' => 'zztest-page-' . bin2hex(random_bytes(3)),
        'body' => '<p>Halaman uji.</p>',
        'status' => 'published',
        'author_id' => 1,
    ]);
    $createdPageIds[] = $pageId;
    check('Page::save creates a page', $pageId > 0);
    $page = Page::find($pageId);
    check('Page::findBySlug works', $page !== null && Page::findBySlug((string)$page['slug']) !== null);
    // Page edits must invalidate the cached sitemap, else new pages stay invisible.
    Seo::sitemap();
    $probeSlug = 'zz-sitemap-probe-' . bin2hex(random_bytes(3));
    $probeId = Page::save(['title' => 'ZZ Sitemap Probe', 'slug' => $probeSlug, 'body' => '<p>x</p>', 'status' => 'published', 'visibility' => 'public', 'author_id' => 1]);
    check('Saved page appears in sitemap immediately', str_contains(Seo::sitemap(), $probeSlug));
    Page::delete($probeId);
    check('Deleted page leaves the sitemap immediately', !str_contains(Seo::sitemap(), $probeSlug));

    /*
     * Halaman QR Code Generator: fitur Page dengan template `qr-generator`.
     * Asetnya hanya dimuat untuk template tersebut, dan tautan unduhan bisa
     * diarahkan ke Direct Link iklan dari Pengaturan situs.
     */
    section('QR code page');
    check('Page::templates lists the QR template', array_key_exists('qr-generator', Page::templates()));
    check('QR assets skipped on other templates', QrPage::headHtml('default') === '');
    $qrHead = QrPage::headHtml('qr-generator');
    check('QR head loads its stylesheet', str_contains($qrHead, 'qr-generator.css'));
    check('QR head loads its script deferred', str_contains($qrHead, 'qr-generator.js') && str_contains($qrHead, 'defer'));
    check('QR direct link empty by default', QrPage::directUrl() === '');
    $qrPreviousDirect = Settings::get(QrPage::DIRECT_LINK_KEY, '');
    Settings::set(QrPage::DIRECT_LINK_KEY, 'https://iklan.example/go');
    check('QR direct link reads the setting', QrPage::directUrl() === 'https://iklan.example/go');
    Settings::set(QrPage::DIRECT_LINK_KEY, 'bukan-url');
    check('QR direct link rejects non-http values', QrPage::directUrl() === '');
    Settings::set(QrPage::DIRECT_LINK_KEY, $qrPreviousDirect);
    $qrBody = QrPage::body();
    check('QR body renders the app root', str_contains($qrBody, 'class="qr-app"'));
    check('QR body has the download controls', str_contains($qrBody, 'data-fmt="png"') && str_contains($qrBody, 'data-fmt="svg"'));
    check('QR body has a toast element', str_contains($qrBody, 'id="toast"'));
    check('QR body drops the blog promo', !str_contains($qrBody, 'Visit Our Blog') && !str_contains($qrBody, 'blog.v-dey.com'));
    check('QR direct link setting is registered', array_key_exists(QrPage::DIRECT_LINK_KEY, Settings::defaults()));

    section('Taxonomy');
    $categoryId = Taxonomy::createCategory('ZZ Test Kategori ' . bin2hex(random_bytes(2)));
    $createdCategoryIds[] = $categoryId;
    check('Taxonomy::createCategory creates a category', $categoryId > 0);
    check('Category appears in list', in_array($categoryId, array_map(static fn(array $row): int => (int)$row['id'], Taxonomy::categories()), true));

    section('Comments');
    $commentId = Comment::create([
        'post_id' => $postId,
        'author' => 'ZZ Tester',
        'email' => 'zztest@example.com',
        'body' => 'Komentar uji otomatis.',
    ]);
    $createdCommentIds[] = $commentId;
    check('Comment::create creates a pending comment', $commentId > 0);
    Comment::moderate($commentId, 'approved');
    check('Comment::moderate approves', in_array($commentId, array_map(static fn(array $row): int => (int)$row['id'], Comment::forPost($postId)), true));

    section('Widgets');
    Widget::flush();
    $widgetId = Database::insert('widgets', [
        'blog_id' => Context::blogId(),
        'widget_key' => 'zztest-' . bin2hex(random_bytes(4)),
        'title' => 'ZZ Test Widget',
        'type' => 'RecentPosts',
        'section_id' => 'sidebar',
        'content' => '',
        'config_json' => '{"numItemsToShow":3}',
        'enabled' => 1,
        'sort_order' => 0,
    ]);
    $createdWidgetIds[] = $widgetId;
    Widget::flush();
    check('Widget appears in its section', in_array($widgetId, array_map(static fn(array $row): int => (int)$row['id'], Widget::forSection('sidebar')), true));
    Widget::reorder([['id' => $widgetId, 'section' => 'footer-1', 'order' => 2]]);
    Widget::flush();
    $widgetRow = Database::fetch('SELECT section_id, sort_order FROM widgets WHERE id = ?', [$widgetId]);
    check('Widget::reorder moves and orders', $widgetRow !== null && (string)$widgetRow['section_id'] === 'footer-1' && (int)$widgetRow['sort_order'] === 2);
    check('Widget::renderSection returns HTML', trim(Widget::renderSection('footer-1', ['route' => 'home'])) !== '');
    // Ad widget: empty content must not emit an empty slot; filled content must.
    $adEmpty = Widget::render(['type' => 'Ad', 'title' => '', 'content' => '', 'config' => []], []);
    check('Ad widget omits empty slot', trim($adEmpty) === '');
    $adFilled = Widget::render(['type' => 'Ad', 'title' => '', 'content' => '<span>ads</span>', 'config' => ['label' => 'leaderboard']], []);
    check('Ad widget renders filled slot', str_contains($adFilled, 'class="ad-slot"') && str_contains($adFilled, 'data-slot="leaderboard"') && str_contains($adFilled, '<span>ads</span>'));

    section('Builder');
    $document = Builder::normalize((string)json_encode([
        'version' => 1,
        'sections' => [[
            'id' => 's1',
            'settings' => ['padding' => 'md'],
            'blocks' => [
                ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Judul Uji', 'level' => 2]],
                ['id' => 'b2', 'type' => 'html', 'settings' => ['html' => '<script>alert(1)</script><p>Aman</p>']],
                ['id' => 'b3', 'type' => 'features', 'settings' => ['items' => [['title' => 'Fitur', 'text' => 'OK']], 'columns' => 2]],
            ],
        ]],
    ]));
    $decoded = json_decode($document, true);
    check('Builder normalises a document', is_array($decoded) && count($decoded['sections'][0]['blocks']) === 3);
    check('Builder strips script from custom HTML', !str_contains($document, '<script'));
    $rendered = Builder::render($document);
    check('Builder renders heading text', str_contains($rendered, 'Judul Uji'));
    check('Builder renders feature item', str_contains($rendered, 'Fitur'));

    $previewDoc = json_decode(Builder::normalize((string)json_encode([
        'version' => 1,
        'sections' => [[
            'id' => 's1',
            'settings' => ['background' => '#fde68a', 'padding' => 'lg', 'width' => 'wide', 'class' => 'sorotan'],
            'blocks' => [['id' => 'b1', 'type' => 'text', 'settings' => ['text' => 'Pratinjau Uji', 'align' => 'left']]],
        ]],
    ])), true);
    $sectionSettings = (array)($previewDoc['sections'][0]['settings'] ?? []);
    check('Builder keeps a section background', ($sectionSettings['background'] ?? '') === '#fde68a');
    check('Builder keeps section padding/width', ($sectionSettings['padding'] ?? '') === 'lg' && ($sectionSettings['width'] ?? '') === 'wide');

    $preview = Builder::previewDocument((string)json_encode($previewDoc), 'Judul Pratinjau');
    check('Builder preview is a full HTML document', str_contains($preview, '<!doctype html') && str_contains($preview, '</html>'));
    check('Builder preview loads the shared design system', str_contains($preview, 'assets/front/system.css'));
    check('Builder preview renders block content', str_contains($preview, 'Pratinjau Uji'));
    check('Builder preview shows the page title', str_contains($preview, 'Judul Pratinjau'));
    check('Builder preview applies the section background', str_contains($preview, 'background:#fde68a'));

    section('SEO');
    $meta = Seo::meta(['title' => 'Judul SEO', 'description' => str_repeat('deskripsi ', 40)]);
    check('Seo::meta builds a title', (string)$meta['title'] !== '');
    // The homepage passes the (possibly empty) seo_title; Seo::meta() must use
    // it as the default title, else fall back to the site name.
    $prevSeoTitle = Settings::get('seo_title', '');
    $siteName = Settings::get('site_name', '');
    Settings::set('seo_title', 'ZZ Judul SEO Default');
    $homeMeta = Seo::meta(['title' => Settings::get('seo_title', '')]);
    check('Seo::meta uses seo_title default', str_starts_with((string)$homeMeta['title'], 'ZZ Judul SEO Default'));
    Settings::set('seo_title', '');
    $homeMetaFallback = Seo::meta(['title' => Settings::get('seo_title', '')]);
    check('Seo::meta falls back to site name', (string)$homeMetaFallback['title'] === $siteName);
    Settings::set('seo_title', $prevSeoTitle);
    // seo_keywords must reach the rendered <head>.
    $prevKeywords = Settings::get('seo_keywords', '');
    Settings::set('seo_keywords', 'zz-alpha, zz-beta');
    $kwMeta = Seo::meta([]);
    check('Seo::meta carries seo_keywords', (string)($kwMeta['keywords'] ?? '') === 'zz-alpha, zz-beta');
    $kwHtml = (new \App\Theme\Ui(Theme::instance(), ['meta' => $kwMeta, 'route' => 'home']))->head();
    check('Head renders keywords meta', str_contains($kwHtml, '<meta name="keywords" content="zz-alpha, zz-beta">'));
    Settings::set('seo_keywords', '');
    $noKwHtml = (new \App\Theme\Ui(Theme::instance(), ['meta' => Seo::meta([]), 'route' => 'home']))->head();
    check('Head omits empty keywords', !str_contains($noKwHtml, 'name="keywords"'));
    Settings::set('seo_keywords', $prevKeywords);
    // default_role drives new-user role; it must be a valid global role.
    check('default_role is a valid global role', in_array(Settings::get('default_role', 'author'), Auth::ROLES, true));
    check('Seo::clamp limits length', mb_strlen(Seo::clamp(str_repeat('kata ', 80), 160)) <= 163);
    check('Sitemap includes a <urlset>', str_contains(Seo::sitemap(), '<urlset'));
    check('Sitemap includes a published post', str_contains(Seo::sitemap(), (string)$published['slug']));
    check('Robots returns text', str_contains(Seo::robots(), 'Sitemap:'));
    $redirectId = Seo::saveRedirect(['from_path' => '/zztest-lama', 'to_path' => '/zztest-baru', 'status_code' => 301]);
    $createdRedirectIds[] = $redirectId;
    check('Redirect is stored', (Seo::redirectFor('/zztest-lama')['status_code'] ?? 0) === 301);
    check('Seo::audit returns a score', isset(Seo::audit()['score']));

    section('TMDB article');
    $video = static fn(string $type, string $key, bool $official, string $lang, string $date, string $site = 'YouTube'): array => [
        'type' => $type,
        'key' => $key,
        'official' => $official,
        'iso_639_1' => $lang,
        'published_at' => $date,
        'site' => $site,
        'name' => $key,
    ];
    $trailerKey = static function (array $videos): string {
        $article = TmdbArticle::build([
            'id' => 1,
            'title' => 'Uji',
            'release_date' => '2020-01-01',
            'overview' => 'Sinopsis uji.',
            'poster_path' => '/p.jpg',
            'backdrop_path' => '/b.jpg',
            'videos' => ['results' => $videos],
        ], ['trailer']);
        return preg_match('~youtube\.com/embed/([A-Za-z0-9_-]+)~', (string)$article['body'], $match) ? $match[1] : '';
    };
    check('TMDB trailer picks the newest official trailer', $trailerKey([
        $video('Trailer', 'LAMA', true, 'en', '2020-01-01T00:00:00.000Z'),
        $video('Trailer', 'BARU', true, 'en', '2023-01-01T00:00:00.000Z'),
    ]) === 'BARU');
    check('TMDB trailer prefers English uploads', $trailerKey([
        $video('Trailer', 'FR', true, 'fr', '2024-01-01T00:00:00.000Z'),
        $video('Trailer', 'EN', true, 'en', '2019-01-01T00:00:00.000Z'),
    ]) === 'EN');
    check('TMDB trailer falls back to an official teaser', $trailerKey([
        $video('Teaser', 'TEASER', true, 'en', '2021-01-01T00:00:00.000Z'),
        $video('Clip', 'CLIP', true, 'en', '2025-01-01T00:00:00.000Z'),
    ]) === 'TEASER');
    check('TMDB trailer ignores unofficial uploads', $trailerKey([
        $video('Trailer', 'FAN', false, 'en', '2024-01-01T00:00:00.000Z'),
    ]) === '');
    check('TMDB trailer ignores non-YouTube hosts', $trailerKey([
        $video('Trailer', 'VIMEO', true, 'en', '2024-01-01T00:00:00.000Z', 'Vimeo'),
    ]) === '');
    check('TMDB trailer is omitted without an official video', $trailerKey([
        $video('Clip', 'CLIP', true, 'en', '2024-01-01T00:00:00.000Z'),
    ]) === '');

    section('Themes');
    check('Bundled themes exist', count(Theme::bundledSlugs()) >= 2);
    check('AMP theme is still bundled', Theme::exists('amp-theme'));
    check('Schema theme is bundled', Theme::exists('schema-theme'));
    check('Schema theme passes validation', (bool)(ThemeEngine::validate(Theme::dir('schema-theme'))['valid'] ?? false));
    $activeTheme = Theme::instance();
    check('Active theme has metadata', (string)$activeTheme->name() !== '');
    check('Customizer CSS emits variables', str_contains($activeTheme->customizerCss(), ':root'));
    // Font pairs: curated list, and the choice must drive the CSS font stacks.
    $pairs = Theme::fontPairs();
    check('Theme exposes curated font pairs', count($pairs) > 3 && array_key_exists('', $pairs));
    $customizerTheme = Theme::make('amp-theme');
    $prevPair = $customizerTheme->setting('font_pair');
    $poppins = '';
    foreach ($pairs as $url => $label) {
        if (str_contains($label, 'Poppins')) {
            $poppins = $url;
        }
    }
    $customizerTheme->saveSettings(['font_pair' => $poppins]);
    Theme::reset();
    check('Font pair drives the text font stack', str_contains(Theme::make('amp-theme')->customizerCss(), '"Poppins"'));
    $customizerTheme->saveSettings(['font_pair' => 'javascript:alert(1)']);
    Theme::reset();
    check('Invalid font pair is rejected', Theme::make('amp-theme')->setting('font_pair') === $poppins);
    // Reset returns the theme to its schema defaults.
    $customizerTheme->saveSettings(['primary_color' => '#ff0000']);
    $customizerTheme->resetSettings();
    Theme::reset();
    check('Theme::resetSettings restores defaults', Theme::make('amp-theme')->setting('primary_color') !== '#ff0000');
    Theme::make('amp-theme')->saveSettings(['font_pair' => $prevPair]);
    Theme::reset();

    /*
     * Structured Data: the theme decides how far the JSON-LD graph goes. A
     * theme without a `structured_data` declaration keeps the minimal graph
     * (Organization + WebSite + article/breadcrumb only); the Schema Theme
     * turns on the rest of the Google Search Gallery.
     */
    section('Structured data');
    $gallery = StructuredData::coverage();
    check('Structured data covers all 25 gallery types', count($gallery) === 25, 'count=' . count($gallery));
    check('Every gallery type resolves to a schema.org type', array_filter($gallery, static fn(string $name): bool => StructuredData::schemaType($name) === null) === []);
    check('Unknown gallery names resolve to null', StructuredData::schemaType('Tidak Ada') === null);
    $declaredCovers = (array)(Theme::make('schema-theme')->metadata()['structured_data']['covers'] ?? []);
    check('Schema Theme declares the whole gallery', $declaredCovers !== [] && array_diff($gallery, $declaredCovers) === []);

    ThemeEngine::activate('amp-theme');
    $themeSwitched = Theme::instance()->slug() !== $originalTheme;
    check('ThemeEngine::activate switches theme', Theme::instance()->slug() === 'amp-theme');
    $plainTypes = array_column(StructuredData::graph([
        'route' => 'home',
        'meta' => ['title' => 'Uji', 'canonical' => url(), 'schema' => []],
    ])['@graph'], '@type');
    check('A theme without a declaration gets the minimal graph', $plainTypes === ['Organization', 'WebSite'], implode(',', $plainTypes));

    ThemeEngine::activate('schema-theme');
    check('Schema Theme becomes active for the graph tests', Theme::instance()->slug() === 'schema-theme');

    $sdBody = '<p>Isi artikel uji.</p><iframe src="https://www.youtube.com/embed/zzTESTvid01" title="Trailer uji"></iframe>';
    $sdItems = [
        ['name' => 'Satu', 'url' => url('post/satu'), 'image' => url('media/1.jpg'), 'date' => '2026-01-01T00:00:00+07:00'],
        ['name' => 'Dua', 'url' => url('post/dua'), 'image' => '', 'date' => '2026-01-02T00:00:00+07:00'],
    ];
    $sdHome = ['route' => 'home', 'schema' => ['items' => $sdItems], 'meta' => ['title' => 'Beranda', 'canonical' => url(), 'schema' => []]];
    $sdPost = [
        'route' => 'post',
        'slug' => 'zztest-film',
        'context' => ['body' => $sdBody],
        'breadcrumbs' => [
            ['name' => 'Beranda', 'url' => url()],
            ['name' => 'Film', 'url' => url('category/film')],
            ['name' => 'Film Uji', 'url' => url('post/zztest-film')],
        ],
        'schema' => ['post' => ['id' => $postId, 'visibility' => 'public', 'category_slugs' => ['film']]],
        'meta' => [
            'title' => 'Film Uji',
            'description' => 'Sinopsis uji </script> <b>tebal</b>.',
            'canonical' => url('post/zztest-film'),
            'image' => url('media/zztest.jpg'),
            'published' => '2026-01-02T09:00:00+07:00',
            'modified' => '2026-01-03T09:00:00+07:00',
            'author' => 'ZZ Tester',
            'article' => ['title' => 'Film Uji', 'body' => $sdBody, 'categories' => ['Film']],
            'schema' => [],
        ],
    ];
    $sdTypes = static fn(array $page): array => array_column(StructuredData::graph($page)['@graph'], '@type');

    $postTypes = $sdTypes($sdPost);
    check('Post emits Organization and WebSite', in_array('Organization', $postTypes, true) && in_array('WebSite', $postTypes, true));
    check('Post emits a BlogPosting', in_array('BlogPosting', $postTypes, true));
    check('Post emits ImageObject metadata', in_array('ImageObject', $postTypes, true));
    check('Post emits VideoObject for an embed', in_array('VideoObject', $postTypes, true));
    check('Movie category adds a Movie node', in_array('Movie', $postTypes, true));
    check('Post emits a BreadcrumbList', in_array('BreadcrumbList', $postTypes, true));

    $homeTypes = $sdTypes($sdHome);
    check('Home emits an ItemList carousel', in_array('ItemList', $homeTypes, true));
    $homeList = null;
    foreach (StructuredData::graph($sdHome)['@graph'] as $node) {
        if (($node['@type'] ?? '') === 'ItemList') {
            $homeList = $node;
        }
    }
    check('Carousel lists the published posts', ($homeList['numberOfItems'] ?? 0) === 2);
    check('Carousel items are articles', (string)($homeList['itemListElement'][0]['item']['@type'] ?? '') === 'Article');

    $category = $sdHome + ['route' => 'category', 'slug' => 'film'];
    $category['breadcrumbs'] = [['name' => 'Beranda', 'url' => url()], ['name' => 'Film', 'url' => url('category/film')]];
    $categoryList = null;
    foreach (StructuredData::graph($category)['@graph'] as $node) {
        if (($node['@type'] ?? '') === 'ItemList') {
            $categoryList = $node;
        }
    }
    check('Movie archive turns the carousel into movies', (string)($categoryList['itemListElement'][0]['item']['@type'] ?? '') === 'Movie');

    $authorTypes = $sdTypes([
        'route' => 'author',
        'breadcrumbs' => [['name' => 'Beranda', 'url' => url()], ['name' => 'ZZ Tester', 'url' => url('author/zz-tester')]],
        'schema' => ['author' => ['name' => 'ZZ Tester', 'url' => url('author/zz-tester'), 'bio' => 'Penulis uji.', 'image' => '']],
        'meta' => ['title' => 'ZZ Tester', 'canonical' => url('author/zz-tester'), 'schema' => []],
    ]);
    check('Author page emits a ProfilePage', in_array('ProfilePage', $authorTypes, true));

    $sdJson = StructuredData::json($sdPost);
    check('JSON-LD decodes as valid JSON', json_decode($sdJson, true) !== null);
    check('JSON-LD keeps slashes readable', str_contains($sdJson, 'https://schema.org'));
    check('JSON-LD escapes tag delimiters', !str_contains($sdJson, '</script') && str_contains($sdJson, '\u003C/script'));
    check('Seo::structuredData delegates to StructuredData', Seo::structuredData($sdPost) === StructuredData::graph($sdPost));

    // Manual nodes (Recipe/Dataset/…) come from the theme settings; store a
    // payload, read the graph, then put the rows back exactly as they were.
    $sdBackup = Database::run(
        'SELECT setting_key, setting_value FROM theme_settings WHERE blog_id = ? AND theme = ?',
        [Context::blogId(), 'schema-theme']
    )->fetchAll(\PDO::FETCH_KEY_PAIR);
    $schemaTheme = Theme::make('schema-theme');
    $schemaTheme->saveSettings([
        'schema_pages' => '[{"path":"/resep/*","gallery":"Recipe","data":{"recipeCategory":"Dessert"}},'
            . '{"path":"/produk/*","gallery":"Product","data":{"sku":"SKU-ZZ"}}]',
        'schema_site' => '{"@type":"Dataset","name":"Data Uji"}',
        'org_type' => 'LocalBusiness',
        'org_city' => 'Bandung',
        'org_rating_value' => '4.7',
        'org_rating_count' => '128',
        'movie_categories' => 'sinema-uji',
    ]);
    Theme::reset();
    $sdSettingsWritten = true;
    $pageTypes = $sdTypes([
        'route' => 'page',
        'breadcrumbs' => [['name' => 'Beranda', 'url' => url()], ['name' => 'Resep Uji', 'url' => url('resep/uji')]],
        'meta' => ['title' => 'Resep Uji', 'canonical' => url('resep/uji'), 'published' => '2026-01-01T00:00:00+07:00', 'schema' => []],
    ]);
    check('schema_pages adds a Recipe for the matching path', in_array('Recipe', $pageTypes, true), implode(',', $pageTypes));
    check('schema_site adds a site-wide Dataset', in_array('Dataset', $pageTypes, true));
    check('Customizer JSON also validates a Product node', StructuredData::schemaType('Product') === 'Product');
    $org = null;
    foreach (StructuredData::graph(['route' => 'home', 'meta' => ['canonical' => url(), 'schema' => []]])['@graph'] as $node) {
        if (str_contains((string)($node['@id'] ?? ''), '#organization')) {
            $org = $node;
        }
    }
    check('Organisation settings pick LocalBusiness', (string)($org['@type'] ?? '') === 'LocalBusiness');
    check('Organisation settings pick the address', (string)($org['address']['addressLocality'] ?? '') === 'Bandung');
    check('Organisation settings pick the aggregate rating', (float)($org['aggregateRating']['ratingValue'] ?? 0) === 4.7);

    Database::run('DELETE FROM theme_settings WHERE blog_id = ? AND theme = ?', [Context::blogId(), 'schema-theme']);
    foreach ($sdBackup as $sdKey => $sdValue) {
        Database::insert('theme_settings', [
            'blog_id' => Context::blogId(),
            'theme' => 'schema-theme',
            'setting_key' => (string)$sdKey,
            'setting_value' => (string)$sdValue,
        ]);
    }
    $sdSettingsWritten = false;
    Theme::reset();

    ThemeEngine::activate($originalTheme);
    $themeSwitched = Theme::instance()->slug() !== $originalTheme;
    check('ThemeEngine::activate restores theme', Theme::instance()->slug() === $originalTheme);

    /*
     * Tools & landing: konfigurasi terpusat (ToolConfig) + halaman mandiri.
     * Semua nilai disimpan di tabel settings, jadi tidak ada tabel baru.
     */
    section('Tool config');
    check('ToolConfig exposes defaults', isset(ToolConfig::defaults()['tool_cv_title']));
    check('ToolConfig all() includes landing keys', array_key_exists('price_headline', ToolConfig::all()));
    check('ToolConfig exposes testimonial keys', array_key_exists('price_testimonials', ToolConfig::all()) && array_key_exists('price_show_testimonials', ToolConfig::all()));
    $prevScope = ToolConfig::all();
    $saved = ToolConfig::save([
        '_scope' => 'tool_cv_',
        'tool_cv_title' => 'CV Uji',
        'tool_cv_enabled' => '',
        'tool_cv_templates' => 'modern,ats',
        'tool_cv_default_template' => 'ats',
        'tool_cv_direct_url' => 'https://iklan.example/cv',
        'tool_cv_monetize' => '1',
    ]);
    check('ToolConfig save keeps scoped keys', ($saved['tool_cv_title'] ?? '') === 'CV Uji');
    check('ToolConfig save coerces unchecked boolean to 0', ($saved['tool_cv_enabled'] ?? '') === '0');
    check('ToolConfig save keeps default template inside list', ($saved['tool_cv_default_template'] ?? '') === 'ats');
    $afterCv = ToolConfig::all();
    check('ToolConfig scoped save leaves other tools alone', $afterCv['tool_qr_title'] === $prevScope['tool_qr_title']);
    check('ToolConfig persists the value', ToolConfig::get('tool_cv_title') === 'CV Uji');
    $priceSaved = ToolConfig::save([
        '_scope' => 'price_',
        'price_testimonials' => "Rina | Freelancer | Rapi\nBudi | Toko | Cepat",
        'price_show_testimonials' => '1',
    ]);
    check('Landing save stores testimonials', str_contains((string)($priceSaved['price_testimonials'] ?? ''), 'Rina'));
    check('Landing save does not overwrite CV scope', ToolConfig::get('tool_cv_title') === 'CV Uji');
    $cvConfig = ToolConfig::publicConfig('cv');
    check('CV public config exposes monetised direct URL', ($cvConfig['directUrl'] ?? '') === 'https://iklan.example/cv');
    check('CV public config lists templates', ($cvConfig['templates'] ?? []) === ['modern', 'ats']);
    $invalidUrl = false;
    try {
        ToolConfig::save(['_scope' => 'tool_qr_', 'tool_qr_direct_url' => 'javascript:alert(1)', 'tool_qr_monetize' => '1']);
    } catch (\Throwable $e) {
        $invalidUrl = true;
    }
    check('ToolConfig rejects non-http direct links', $invalidUrl);
    check('QR public config falls back to bundled QRIS', str_contains((string)(ToolConfig::publicConfig('qr')['qris'] ?? ''), 'QR-DANA'));
    check('CV data attribute is HTML-safe JSON', str_contains(ToolConfig::dataAttribute('cv'), 'data-config=') && !str_contains(ToolConfig::dataAttribute('cv'), '"tool"'));
    // Bersihkan jejak uji: buang semua key tool_/price_ agar tabel settings
    // kembali seperti sebelum tes (default tetap datang dari ToolConfig).
    Database::pdo()->exec("DELETE FROM settings WHERE setting_key LIKE 'tool\\\\_%' OR setting_key LIKE 'price\\\\_%'");


    section('Standalone tools');
    check('QR legacy template still recognised', QrPage::isTemplate('qr-generator'));
    check('QR head loads standalone tokens too', str_contains(QrPage::headHtml('qr-generator'), 'standalone.css'));
    check('QR head skipped elsewhere', QrPage::headHtml('default') === '');
    check('QR page class exists', class_exists(CvMakerPage::class));
    SalesOrder::ensureTable();
    check('SalesOrder table is available', Database::tableExists('orders'));
    $orderId = SalesOrder::create(['name' => 'ZZ Test Buyer', 'email' => 'zz@example.com', 'whatsapp' => '0812', 'amount' => 1000]);
    check('SalesOrder::create stores a pending order', $orderId > 0);
    $order = Database::fetch('SELECT * FROM orders WHERE id = ?', [$orderId]);
    check('SalesOrder defaults to pending', (string)($order['status'] ?? '') === 'pending');
    $badOrder = false;
    try {
        SalesOrder::create(['name' => '', 'email' => 'bukan-email']);
    } catch (\Throwable $e) {
        $badOrder = true;
    }
    check('SalesOrder validates name and email', $badOrder);
    Database::delete('orders', 'id = ?', [$orderId]);

    section('i18n');
    $prevLocale = Settings::get('language', 'id');
    \App\Core\Lang::reset();
    check('Admin chrome keys exist in both catalogs', array_key_exists('admin_panel', \App\Core\Lang::catalog('id')) && array_key_exists('admin_panel', \App\Core\Lang::catalog('en')));
    $idCatalog = \App\Core\Lang::catalog('id');
    $enCatalog = \App\Core\Lang::catalog('en');
    check('ID and EN catalogs cover the same keys', array_keys($idCatalog) === array_keys($enCatalog));
    check('Admin nav group translates in ID', $idCatalog['admin_group_content'] === 'Konten');
    check('Admin nav group translates in EN', $enCatalog['admin_group_content'] === 'Content');
    Settings::set('language', 'en');
    \App\Core\Lang::reset();
    check('Admin nav group follows the blog language', isset(App\Http\AdminController::navigation()['Content']));
    Settings::set('language', $prevLocale);
    \App\Core\Lang::reset();

    section('Security');
    check('Guest cannot publish posts', !Auth::can('post.publish'));
    check('No user is authenticated in CLI', !Auth::check());
    // URL sanitizer must reject script-capable data URIs but keep raster images.
    check('isSafeUrl rejects data:text/html', !Html::isSafeUrl('data:text/html;base64,PHNjcmlwdD4='));
    check('isSafeUrl rejects data:image/svg+xml', !Html::isSafeUrl('data:image/svg+xml,<svg onload=alert(1)>'));
    check('isSafeUrl rejects javascript:', !Html::isSafeUrl('javascript:alert(1)'));
    check('isSafeUrl keeps base64 png', Html::isSafeUrl('data:image/png;base64,iVBORw0KGgoAAAANSUhEUg=='));
    check('isSafeUrl keeps https', Html::isSafeUrl('https://example.com/a.png'));
    // Failed login must not leak account existence through timing.
    $timeAttempt = static function (string $login): float {
        $start = microtime(true);
        try {
            Auth::attempt($login, 'zz-wrong-password');
        } catch (Throwable) {
            // Lockout/security exceptions are irrelevant to the timing sample.
        }
        return microtime(true) - $start;
    };
    $tMissing = $timeAttempt('zz-no-such-user-' . bin2hex(random_bytes(3)));
    $tExisting = $timeAttempt((string)Database::value('SELECT username FROM users WHERE status = "active" ORDER BY id LIMIT 1'));
    $ratio = $tExisting > 0 ? $tMissing / $tExisting : 1.0;
    check('Login timing does not reveal account existence', $ratio > 0.5 && $ratio < 2.0);
    Database::delete('login_attempts', '1 = 1', []);
} catch (Throwable $exception) {
    $failed++;
    $failures[] = 'Uncaught ' . $exception::class . ': ' . $exception->getMessage();
    echo "\n  FAIL uncaught " . $exception::class . ': ' . $exception->getMessage() . "\n";
    echo $exception->getTraceAsString() . "\n";
} finally {
    // Clean up test rows only.
    foreach ($createdCommentIds as $id) {
        Database::delete('comments', 'id = ?', [$id]);
    }
    foreach ($createdPostIds as $id) {
        Post::purge($id);
    }
    foreach ($createdPageIds as $id) {
        Database::delete('pages', 'id = ?', [$id]);
    }
    foreach ($createdCategoryIds as $id) {
        Database::delete('categories', 'id = ?', [$id]);
    }
    foreach ($createdWidgetIds as $id) {
        Database::delete('widgets', 'id = ?', [$id]);
    }
    foreach ($createdRedirectIds as $id) {
        Database::delete('redirects', 'id = ?', [$id]);
    }
    Widget::flush();
    if ($sdSettingsWritten) {
        Database::run('DELETE FROM theme_settings WHERE blog_id = ? AND theme = ?', [Context::blogId(), 'schema-theme']);
        foreach ((array)$sdBackup as $sdKey => $sdValue) {
            Database::insert('theme_settings', [
                'blog_id' => Context::blogId(),
                'theme' => 'schema-theme',
                'setting_key' => (string)$sdKey,
                'setting_value' => (string)$sdValue,
            ]);
        }
    }
    if ($themeSwitched && Theme::exists($originalTheme)) {
        ThemeEngine::activate($originalTheme);
    }
    Theme::reset();
}

echo "\n" . str_repeat('-', 56) . "\n";
echo sprintf("%d passed, %d failed\n", $passed, $failed);
if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}
exit(0);
