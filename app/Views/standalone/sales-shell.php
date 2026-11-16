<?php
declare(strict_types=1);

/**
 * Kerangka dokumen untuk halaman mandiri (tools & landing penjualan).
 *
 * Tidak memuat navbar, footer, atau sidebar CMS. Hanya token gaya dasar dari
 * base.css + stylesheet milik halaman itu sendiri, lalu dua zona widget opsional
 * (`header` dan `slot`) yang bisa dikelola dari Admin → Tata Letak.
 *
 * @var string $title
 * @var array  $page    ['slug'=>string, 'home'=>string, 'lang'=>string]
 * @var string $content
 * @var string $siteName
 * @var string $logo
 * @var string $home
 */

use App\Core\Lang;
use App\Core\Settings;
use App\Core\Widget;

$page = is_array($page ?? null) ? $page : [];
$home = (string)($page['home'] ?? $home ?? url());
$headZone = '';
$slotZone = '';
try {
    $headZone = Widget::renderSection('tools-header', ['route' => 'page', 'slug' => (string)($page['slug'] ?? 'tools')]);
    $slotZone = Widget::renderSection('sales-slot', ['route' => 'page', 'slug' => (string)($page['slug'] ?? 'sales')]);
} catch (\Throwable) {
    $headZone = '';
}
$initial = mb_substr((string)($siteName ?? 'CMS'), 0, 1);
?>
<!doctype html>
<html lang="<?= e((string)($page['lang'] ?? Lang::htmlLocale())) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($title) ?> — <?= e((string)$siteName) ?></title>
<?php $favicon = (string)($favicon ?? Settings::get('favicon_url', '')); ?>
<link rel="icon" href="<?= e($favicon !== '' ? $favicon : url('favicon.ico')) ?>">
<?php $desc = (string)($page['desc'] ?? Settings::get('site_description', '')); ?>
<?php if ($desc !== ''): ?>
<meta name="description" content="<?= e($desc) ?>">
<?php endif; ?>
<?php
$canonical = (string)($page['canonical'] ?? '');
if ($canonical === '') {
    $reqPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $canonical = url(ltrim($reqPath, '/'));
}
?>
<link rel="canonical" href="<?= e($canonical) ?>">
<link rel="stylesheet" href="<?= e(url('assets/front/base.css')) ?>?v=<?= (int)@filemtime(CMS_ROOT . '/assets/front/base.css') ?>">
<link rel="stylesheet" href="<?= e(url('assets/front/standalone.css')) ?>?v=<?= (int)@filemtime(CMS_ROOT . '/assets/front/standalone.css') ?>">
<meta name="robots" content="index,follow">
</head>
<body class="standalone-body page-<?= e((string)($page['slug'] ?? 'tools')) ?>">
<header class="sa-header">
    <div class="sa-shell sa-header-inner">
        <a class="sa-brand" href="<?= e($home) ?>">
            <?php if ((string)($logo ?? '') !== ''): ?>
                <img src="<?= e((string)$logo) ?>" alt="<?= e((string)$siteName) ?>" width="120" height="30" style="width:auto;object-fit:contain">
            <?php else: ?>
                <span class="sa-mark" aria-hidden="true"><?= e($initial) ?></span>
                <strong><?= e((string)$siteName) ?></strong>
            <?php endif; ?>
        </a>
        <a class="sa-home" href="<?= e($home) ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
            Home
        </a>
    </div>
</header>
<div class="sa-nav-zone"><?= $headZone ?></div>
<?= $content ?>
<?= $slotZone ?>
</body>
</html>
