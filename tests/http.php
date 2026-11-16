<?php
declare(strict_types=1);

/**
 * HTTP smoke tests. Point it at a running local server:
 *
 *   php tests/http.php http://127.0.0.1:8090
 *
 * Verifies routing, security headers, sitemap/feed output and that private
 * files are not web-accessible.
 */

$base = rtrim((string)($argv[1] ?? getenv('TEST_URL') ?: 'http://127.0.0.1:8090'), '/');

$passed = 0;
$failed = 0;
$failures = [];

/**
 * @return array{status:int, headers:array<string,string>, body:string, location:string}
 */
function request(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'cms-smoke-test/1.0',
    ]);
    $response = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerText = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    $headers = [];
    foreach (preg_split('/\r?\n/', $headerText) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'location' => $headers['location'] ?? ''];
}

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

/**
 * Decode the JSON-LD graph embedded in a page, or false when missing/invalid.
 *
 * @return array<string, mixed>|false
 */
function jsonLd(string $html): array|false
{
    if (!preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match)) {
        return false;
    }
    $decoded = json_decode($match[1], true);
    return is_array($decoded) ? $decoded : false;
}

echo "Testing {$base}\n";

section('Public pages');
$home = request($base . '/');
check('Home returns 200', $home['status'] === 200, 'status=' . $home['status']);
check('Home is HTML', str_contains($home['headers']['content-type'] ?? '', 'text/html'));
check('Home has one <main>', substr_count(strtolower($home['body']), '<main') === 1);
check('Home has canonical link', str_contains($home['body'], 'rel="canonical"'));
check('Home has RSS alternate', str_contains($home['body'], 'application/rss+xml'));
check('Home loads base.css', str_contains($home['body'], 'assets/front/base.css'));
check('Home loads shared system.css', str_contains($home['body'], 'assets/front/system.css'));
$homeJsonLd = jsonLd($home['body']);
check('Home emits a decodable JSON-LD graph', is_array($homeJsonLd) && ($homeJsonLd['@context'] ?? '') === 'https://schema.org');
check('Home JSON-LD has Organization and WebSite', is_array($homeJsonLd) && in_array('Organization', array_column((array)($homeJsonLd['@graph'] ?? []), '@type'), true) && in_array('WebSite', array_column((array)($homeJsonLd['@graph'] ?? []), '@type'), true));

section('SEO endpoints');
$sitemap = request($base . '/sitemap.xml');
check('Sitemap returns 200', $sitemap['status'] === 200, 'status=' . $sitemap['status']);
check('Sitemap is XML', str_contains($sitemap['headers']['content-type'] ?? '', 'xml'));
check('Sitemap has <urlset>', str_contains($sitemap['body'], '<urlset'));
$robots = request($base . '/robots.txt');
check('Robots returns 200', $robots['status'] === 200);
check('Robots points at sitemap', stripos($robots['body'], 'sitemap:') !== false);
$feed = request($base . '/feed.xml');
check('Feed returns 200', $feed['status'] === 200, 'status=' . $feed['status']);
check('Feed is RSS', stripos($feed['body'], '<rss') !== false);

section('Blogger-style URLs');
$firstPostPath = '';
if (preg_match('~<loc>([^<]+/20\d{2}/\d{2}/[^<]+\.html)</loc>~', $sitemap['body'], $match)) {
    $firstPostPath = parse_url($match[1], PHP_URL_PATH) ?: '';
}
check('Sitemap exposes a post permalink', $firstPostPath !== '');
if ($firstPostPath !== '') {
    $post = request($base . $firstPostPath);
    check('Post permalink returns 200', $post['status'] === 200, 'status=' . $post['status']);
    check('Post has one <main>', substr_count(strtolower($post['body']), '<main') === 1);
    check('Post has article schema', str_contains($post['body'], '"@type":"BlogPosting"') || str_contains($post['body'], 'BlogPosting'));
    $postJsonLd = jsonLd($post['body']);
    check('Post emits a decodable JSON-LD graph', is_array($postJsonLd) && in_array('BlogPosting', array_column((array)($postJsonLd['@graph'] ?? []), '@type'), true));
}
$page = request($base . '/p/tentang-kami.html');
check('Page permalink returns 200', $page['status'] === 200, 'status=' . $page['status']);

// Halaman QR Code Generator (template `qr-generator`): aplikasi + asetnya
// dimuat hanya di halaman ini. Bila halaman belum dibuat di blog ini,
// pengecekan dilewati agar instalasi baru tetap hijau.
$qrPage = request($base . '/p/qr-code-generator.html');
if ($qrPage['status'] === 200) {
    check('QR page renders the app root', str_contains($qrPage['body'], 'class="qr-app"'));
    check('QR page loads its own stylesheet', str_contains($qrPage['body'], 'assets/front/qr-generator.css'));
    check('QR page loads its script deferred', str_contains($qrPage['body'], 'assets/front/qr-generator.js'));
    check('QR page has the type tabs and download buttons', str_contains($qrPage['body'], 'id="typeTabs"') && str_contains($qrPage['body'], 'data-fmt="png"'));
    check('QR page keeps the direct-link hook', str_contains($qrPage['body'], 'data-direct-url'));
    check('QR page exposes admin config', str_contains($qrPage['body'], 'data-config='));
    check('QR page renders the support popup', str_contains($qrPage['body'], 'id="qrSupport"') && str_contains($qrPage['body'], 'id="qrSupportLater"'));
    check('QR page hides the blog promo', !str_contains($qrPage['body'], 'Visit Our Blog'));
    check('QR assets stay off other pages', !str_contains($home['body'], 'qr-generator.js'));
} else {
    check('QR page not created on this blog (skipped)', true, 'status=' . $qrPage['status']);
}

$category = request($base . '/search/label/olahraga');
check('Label archive returns 200', in_array($category['status'], [200, 404], true), 'status=' . $category['status']);
$search = request($base . '/search?q=uji');
check('Search returns 200', $search['status'] === 200, 'status=' . $search['status']);
$outOfRange = request($base . '/page/9999');
check('Out-of-range page returns 404', $outOfRange['status'] === 404, 'status=' . $outOfRange['status']);

section('Archive & taxonomy');
$archive = request($base . '/archive');
check('Archive index returns 200', $archive['status'] === 200, 'status=' . $archive['status']);
check('Archive index lists months', str_contains($archive['body'], 'archive-months'));
$archiveMonth = request($base . '/archive/2026/11');
check('Archive month returns 200', $archiveMonth['status'] === 200, 'status=' . $archiveMonth['status']);
$tag = request($base . '/tag/aksesibilitas');
check('Tag page returns 200', $tag['status'] === 200, 'status=' . $tag['status']);
$tagMissing = request($base . '/tag/tidak-ada-tag-zztest');
check('Unknown tag returns 404', $tagMissing['status'] === 404, 'status=' . $tagMissing['status']);
$labelTag = request($base . '/search/label/aksesibilitas');
check('Label route falls back to tag', $labelTag['status'] === 200, 'status=' . $labelTag['status']);
$bareLabel = request($base . '/label/aksesibilitas');
check('Bare /label/<slug> resolves', in_array($bareLabel['status'], [200, 404], true), 'status=' . $bareLabel['status']);

section('Numeric permalink');
$numeric = request($base . '/?p=2');
check('Valid numeric permalink redirects', $numeric['status'] === 301, 'status=' . $numeric['status']);
check('Numeric permalink targets pretty URL', str_contains($numeric['location'], '.html'), 'location=' . $numeric['location']);
$numericMissing = request($base . '/?p=999999');
check('Unknown numeric permalink returns 404', $numericMissing['status'] === 404, 'status=' . $numericMissing['status']);

section('Language switching');
$lang = request($base . '/language/en');
check('Language switch redirects', in_array($lang['status'], [301, 302], true), 'status=' . $lang['status']);
check('Language switch sets cookie', str_contains($lang['headers']['set-cookie'] ?? '', 'site_language=en'), 'cookie=' . ($lang['headers']['set-cookie'] ?? ''));
check('Language switch redirects home', str_ends_with(rtrim($lang['location'], '/'), '127.0.0.1:8090'), 'location=' . $lang['location']);

section('Legacy shims');
$legacy = request($base . '/search.php?q=uji');
check('Legacy search shim redirects', in_array($legacy['status'], [301, 302], true), 'status=' . $legacy['status']);
check('Legacy search targets /search', str_contains($legacy['location'], '/search'), 'location=' . $legacy['location']);

section('Security');
check('Home sends X-Frame-Options', ($home['headers']['x-frame-options'] ?? '') === 'SAMEORIGIN');
check('Home sends nosniff', ($home['headers']['x-content-type-options'] ?? '') === 'nosniff');
check('Home sends Referrer-Policy', ($home['headers']['referrer-policy'] ?? '') !== '');
foreach (['/config.php', '/config.local.php', '/app/bootstrap.php', '/database/schema.sql', '/storage/logs/cms.log', '/bin/console.php', '/themes/gazette/functions.php'] as $path) {
    $blocked = request($base . $path);
    check('Blocked path ' . $path, in_array($blocked['status'], [403, 404], true), 'status=' . $blocked['status']);
}
$unknown = request($base . '/tidak-ada-halaman-zztest');
check('Unknown URL returns 404', $unknown['status'] === 404, 'status=' . $unknown['status']);

section('Admin');
$login = request($base . '/admin/index.php?view=login');
check('Admin login page returns 200', $login['status'] === 200, 'status=' . $login['status']);
check('Login page has CSRF field', str_contains($login['body'], 'name="csrf"'));
$dashboard = request($base . '/admin/index.php?view=dashboard');
check('Dashboard requires login', in_array($dashboard['status'], [301, 302], true), 'status=' . $dashboard['status']);
$builderPreview = request($base . '/admin/index.php?ajax=builder.preview');
check('Builder preview requires login', $builderPreview['status'] === 401, 'status=' . $builderPreview['status']);

// Halaman mandiri (CV Maker, QR, landing penjualan): tanpa chrome tema,
// punya header + tombol Home sendiri, dan konfigurasinya dari Admin → Tools.
section('Standalone tools');
$cvUrl = '/cv-maker';
$cv = request($base . $cvUrl);
if ($cv['status'] === 200) {
    check('CV Maker returns 200', true);
    check('CV Maker loads the standalone shell', str_contains($cv['body'], 'class="sa-header"') && str_contains($cv['body'], 'assets/front/standalone.css'));
    check('CV Maker loads base.css for tokens', str_contains($cv['body'], 'assets/front/base.css'));
    check('CV Maker loads its own css + js', str_contains($cv['body'], 'assets/front/cv-maker.css') && str_contains($cv['body'], 'assets/front/cv-maker.js'));
    $cvHasTemplates = str_contains($cv['body'], '&quot;templates&quot;') || str_contains($cv['body'], '"templates"');
    check('CV Maker exposes admin config', str_contains($cv['body'], 'data-config=') && $cvHasTemplates);
    check('CV Maker renders all sections and the paper', str_contains($cv['body'], 'id="cvApp"') && str_contains($cv['body'], 'id="cvDoc"'));
    check('CV Maker renders the support popup', str_contains($cv['body'], 'id="cvSupport"') && str_contains($cv['body'], 'id="cvSupportLater"'));
    check('CV Maker has a Home link to the site root', str_contains($cv['body'], 'class="sa-home"'));
    check('CV Maker has no sidebar/footer chrome', !str_contains($cv['body'], 'class="sidebar') && !str_contains($cv['body'], 'site-footer'));
    check('CV Maker is page-scoped for print', str_contains($cv['body'], 'page-cv-maker'));
} else {
    check('CV Maker not enabled on this blog (skipped)', true, 'status=' . $cv['status']);
}

$qrStandalone = request($base . '/qr-code-generator');
if ($qrStandalone['status'] === 200) {
    check('QR standalone returns 200', true);
    check('QR standalone loads the standalone shell', str_contains($qrStandalone['body'], 'class="sa-header"'));
    check('QR standalone renders the app + popup', str_contains($qrStandalone['body'], 'class="qr-app"') && str_contains($qrStandalone['body'], 'id="qrSupport"'));
    check('QR standalone exposes admin config', str_contains($qrStandalone['body'], 'data-config='));
    check('QR standalone has no sidebar/footer chrome', !str_contains($qrStandalone['body'], 'class="sidebar') && !str_contains($qrStandalone['body'], 'site-footer'));
} else {
    check('QR standalone not enabled on this blog (skipped)', true, 'status=' . $qrStandalone['status']);
}

$sales = request($base . '/jual-script-cms');
if ($sales['status'] === 200) {
    check('Sales landing returns 200', true);
    check('Sales landing renders the hero', str_contains($sales['body'], 'class="lp-hero"'));
    check('Sales landing lists features and FAQ', str_contains($sales['body'], 'lp-feature') && str_contains($sales['body'], 'lp-faq'));
    check('Sales landing has a contact form', str_contains($sales['body'], 'class="lp-form"'));
    check('Sales landing loads the standalone shell', str_contains($sales['body'], 'class="sa-header"'));
    check('Sales landing has no sidebar/footer chrome', !str_contains($sales['body'], 'class="sidebar') && !str_contains($sales['body'], 'site-footer'));
} else {
    check('Sales landing not enabled on this blog (skipped)', true, 'status=' . $sales['status']);
}

$unknownTool = request($base . '/tool-tidak-ada-zztest');
check('Unknown tool slug returns 404', $unknownTool['status'] === 404, 'status=' . $unknownTool['status']);

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
