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
    Settings::set('zztest_setting', 'nilai-uji');
    check('Settings::set/get round-trip', Settings::get('zztest_setting') === 'nilai-uji');
    Settings::forget('zztest_setting');
    check('Settings::forget removes key', Settings::get('zztest_setting', 'fallback') === 'fallback');
    Cache::put('zztest.cache', ['ok' => true], 60);
    check('Cache::set/get round-trip', Cache::get('zztest.cache') === ['ok' => true]);
    Cache::forget('zztest.cache');
    check('Cache::forget removes key', Cache::get('zztest.cache') === null);

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

    section('Security');
    check('Guest cannot publish posts', !Auth::can('post.publish'));
    check('No user is authenticated in CLI', !Auth::check());
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
