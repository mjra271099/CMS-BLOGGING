<?php
declare(strict_types=1);

/**
 * Kerangka dokumen mandiri untuk halaman alat (CV Maker, QR Code Generator)
 * dan landing penjualan.
 *
 * Tidak memuat navbar, footer, atau sidebar CMS. Hanya token gaya dasar
 * (base.css) + stylesheet halaman itu sendiri, lalu header ringkas dengan
 * tombol Home ke beranda. CSS/JS tambahan serta atribut `data-config`
 * diberikan pemanggil bila diperlukan.
 *
 * @var string $title
 * @var array  $page      ['slug'=>string, 'home'=>string, 'lang'=>string, 'bodyClass'=>string, 'config'=>string, 'head'=>string, 'scripts'=>string]
 * @var string $content
 * @var string $siteName
 * @var string $logo
 * @var string $home
 */

use App\Core\Lang;

$page = is_array($page ?? null) ? $page : [];
$home = (string)($page['home'] ?? $home ?? url());
$initial = mb_substr((string)($siteName ?? 'CMS'), 0, 1);
$slug = (string)($page['slug'] ?? 'tools');
$bodyClass = 'standalone-body page-' . $slug . ' ' . (string)($page['bodyClass'] ?? '');
$favicon = (string)($favicon ?? Settings::get('favicon_url', '')) ?: url('favicon.ico');
?>
<!doctype html>
<html lang="<?= e((string)($page['lang'] ?? Lang::htmlLocale())) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($title) ?> — <?= e((string)$siteName) ?></title>
<link rel="icon" href="<?= e($favicon) ?>">
<link rel="stylesheet" href="<?= e(url('assets/front/base.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/front/standalone.css')) ?>?v=<?= (int)@filemtime(CMS_ROOT . '/assets/front/standalone.css') ?>">
<?= (string)($page['head'] ?? '') ?>
<meta name="robots" content="index,follow">
</head>
<body class="<?= e(trim($bodyClass)) ?>"<?= (string)($page['config'] ?? '') ?>>
<header class="sa-header">
    <div class="sa-shell sa-header-inner">
        <a class="sa-brand" href="<?= e($home) ?>">
            <?php if ((string)($logo ?? '') !== ''): ?>
                <img src="<?= e((string)$logo) ?>" alt="<?= e((string)$siteName) ?>" height="28">
            <?php else: ?>
                <span class="sa-mark" aria-hidden="true"><?= e($initial) ?></span>
            <?php endif; ?>
            <strong><?= e((string)$siteName) ?></strong>
        </a>
        <a class="sa-home" href="<?= e($home) ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
            Home
        </a>
    </div>
</header>
<?= $content ?>
<?= (string)($page['scripts'] ?? '') ?>
</body>
</html>
