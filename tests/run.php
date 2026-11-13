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
use App\Core\Widget;
use App\Models\Comment;
use App\Models\Page;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Services\Builder;
use App\Services\Seo;

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

    section('Themes');
    check('Bundled themes exist', count(Theme::bundledSlugs()) >= 15);
    $activeTheme = Theme::instance();
    check('Active theme has metadata', (string)$activeTheme->name() !== '');
    check('Customizer CSS emits variables', str_contains($activeTheme->customizerCss(), ':root'));
    if ($originalTheme !== 'aurora' && Theme::exists('aurora')) {
        \App\Core\ThemeEngine::activate('aurora');
        check('ThemeEngine::activate switches theme', Theme::instance()->slug() === 'aurora');
        \App\Core\ThemeEngine::activate($originalTheme);
        check('ThemeEngine::activate restores theme', Theme::instance()->slug() === $originalTheme);
    } else {
        check('ThemeEngine::activate is available', method_exists(\App\Core\ThemeEngine::class, 'activate'));
    }

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
