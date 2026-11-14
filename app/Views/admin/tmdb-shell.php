<?php
declare(strict_types=1);

/**
 * Shared admin chrome for standalone generator pages.
 *
 * Mirrors app/Views/admin/shell.php markup and classes so the page is visually
 * identical to the rest of the dashboard, but the sidebar links and page title
 * are supplied by the caller. Kept separate so no existing view is modified.
 *
 * @var string $title
 * @var string $content
 * @var array  $nav
 * @var array  $user
 * @var array  $blogs
 * @var array  $activeBlog
 * @var array  $flash
 * @var string $activeKey   nav key highlighted as current
 * @var string $selfUrl     current page URL (module entry point)
 * @var int    $commentsPending
 * @var string $siteName
 */

use App\Core\Csrf;
use App\Core\Settings;
use App\Http\AdminUi;
use App\Models\Post;

$nav = is_array($nav ?? null) ? $nav : [];
$user = is_array($user ?? null) ? $user : [];
$blogs = is_array($blogs ?? null) ? $blogs : [];
$activeBlog = is_array($activeBlog ?? null) ? $activeBlog : [];
$flash = is_array($flash ?? null) ? $flash : [];
$title = (string)($title ?? 'Generator TMDB');
$content = (string)($content ?? '');
$activeKey = (string)($activeKey ?? 'tmdb');
$selfUrl = (string)($selfUrl ?? url('tmdb-generator.php'));
$commentsPending = (int)($commentsPending ?? 0);
$siteName = (string)($siteName ?? Settings::get('site_name', 'CMS'));

$adminBase = url('admin/index.php');
$drafts = 0;
try {
    $drafts = (int)(Post::statusCounts()['draft'] ?? 0);
} catch (\Throwable) {
    $drafts = 0;
}
$badges = ['posts' => $drafts, 'comments' => $commentsPending];
$brandLogo = (string)Settings::get('logo_url', '');
$userName = (string)($user['display_name'] ?? '') ?: (string)($user['username'] ?? 'Pengguna');
$userEmail = (string)($user['email'] ?? '');
$userRole = (string)($user['role'] ?? '');
$userInitials = AdminUi::initials($userName);
$activeBlogName = (string)($activeBlog['name'] ?? $siteName);

$currentLabel = 'Generator TMDB';
$currentGroup = 'Pertumbuhan';
foreach ($nav as $group => $items) {
    foreach ((array)$items as $key => $item) {
        if ((string)$key === $activeKey) {
            $currentLabel = (string)($item[0] ?? $key);
            $currentGroup = (string)$group;
        }
    }
}

/**
 * Icons this module needs that are not in the shared AdminUi set. Rendered
 * inline so we never touch the existing helper.
 */
$icon = static function (string $name, int $size = 18): string {
    $extra = [
        'clapper' => 'M20.2 6 3 11l-.9-2.4c-.3-1.1.3-2.2 1.3-2.5l13.5-4c1.1-.3 2.2.3 2.5 1.3zM6.2 5.3l3.1 3.9M12.4 3.4l3.1 4M3 11h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
        'film' => 'M3 3h18v18H3zM7 3v18M17 3v18M3 8h4M3 16h4M17 8h4M17 16h4M7 12h10',
    ];
    if (isset($extra[$name])) {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<path d="' . $extra[$name] . '"/></svg>';
    }
    return AdminUi::icon($name, $size);
};
?>
<!doctype html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light dark">
    <title><?= e($title) ?> — <?= e($siteName) ?></title>
    <script>
    (function () {
        try {
            var stored = localStorage.getItem('cms-theme');
            var theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        } catch (e) {}
    })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= e(asset_url('assets/admin.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/admin-app.css')) ?>">
    <?php if (Settings::get('favicon_url') !== ''): ?>
        <link rel="icon" href="<?= e(media_url(Settings::get('favicon_url'))) ?>">
    <?php endif; ?>
</head>
<body class="admin-body">
<script>
try { if (localStorage.getItem('cms-sidebar') === 'collapsed') document.body.classList.add('sidebar-collapsed'); } catch (e) {}
</script>
<a class="skip-link" href="#main-content">Lompat ke konten</a>

<div class="admin-shell" id="admin-shell">
    <aside class="sidebar" id="admin-sidebar" aria-label="Navigasi utama">
        <div class="sidebar-brand">
            <a class="brand-link" href="<?= e($adminBase . '?view=dashboard') ?>">
                <?php if ($brandLogo !== ''): ?>
                    <img class="brand-logo" src="<?= e(media_url($brandLogo)) ?>" alt="">
                <?php else: ?>
                    <span class="brand-mark" aria-hidden="true"><?= e(mb_substr($activeBlogName, 0, 1)) ?></span>
                <?php endif; ?>
                <span class="brand-text">
                    <strong><?= e($activeBlogName) ?></strong>
                    <small>Panel Admin</small>
                </span>
            </a>
            <button type="button" class="sidebar-collapse" data-sidebar-collapse aria-label="Ringkas sidebar" title="Ringkas sidebar">
                <?= AdminUi::icon('panel-left', 16) ?>
            </button>
            <button type="button" class="icon-btn sidebar-close" data-sidebar-close aria-label="Tutup navigasi">
                <?= AdminUi::icon('x', 18) ?>
            </button>
        </div>

        <nav class="sidebar-nav" aria-label="Navigasi admin">
            <?php foreach ($nav as $group => $items): ?>
                <div class="nav-section"><?= e($group) ?></div>
                <?php foreach ((array)$items as $key => $item): ?>
                    <?php
                    [$label, $navIcon] = [(string)($item[0] ?? $key), (string)($item[1] ?? 'grid')];
                    $isModule = (string)$key === $activeKey;
                    $href = $isModule ? $selfUrl : $adminBase . '?view=' . $key;
                    ?>
                    <a href="<?= e($href) ?>"<?= $isModule ? ' class="active"' : AdminUi::active('', (string)$key) ?>>
                        <span class="nav-icon"><?= $icon($navIcon, 17) ?></span>
                        <span class="sidebar-label"><?= e($label) ?></span>
                        <?php if (!empty($badges[$key])): ?>
                            <span class="badge"><?= (int)$badges[$key] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <a class="sidebar-user" href="<?= e($adminBase . '?view=profile') ?>">
                <span class="avatar" aria-hidden="true"><?= e($userInitials) ?></span>
                <span class="user-meta">
                    <strong><?= e($userName) ?></strong>
                    <small><?= e($userRole !== '' ? $userRole : 'Profil saya') ?></small>
                </span>
            </a>
        </div>
    </aside>

    <button type="button" class="sidebar-scrim" data-sidebar-close aria-label="Tutup navigasi" hidden></button>

    <div class="admin-main">
        <header class="top">
            <button type="button" class="icon-btn sidebar-toggle" data-sidebar-toggle aria-label="Buka navigasi" aria-controls="admin-sidebar" aria-expanded="false">
                <?= AdminUi::icon('panel-left', 19) ?>
            </button>

            <div class="breadcrumb" aria-label="Breadcrumb" style="display:none"></div>

            <div class="top-actions">
                <a class="icon-btn" href="<?= e(url('admin/index.php?view=posts')) ?>" title="Kembali ke postingan" aria-label="Kembali ke postingan">
                    <?= AdminUi::icon('chevron-left', 18) ?>
                </a>

                <a class="icon-btn" href="<?= e(url()) ?>" target="_blank" rel="noopener" title="Lihat situs" aria-label="Lihat situs">
                    <?= AdminUi::icon('external', 18) ?>
                </a>

                <button type="button" class="icon-btn" data-theme-toggle aria-label="Ganti mode terang/gelap" title="Mode terang / gelap">
                    <span class="icon-moon"><?= AdminUi::icon('moon', 18) ?></span>
                    <span class="icon-sun"><?= AdminUi::icon('sun', 18) ?></span>
                </button>

                <details class="user-menu">
                    <summary>
                        <span class="avatar" aria-hidden="true"><?= e($userInitials) ?></span>
                        <span class="user-name"><?= e($userName) ?></span>
                        <?= AdminUi::icon('chevron-down', 14) ?>
                    </summary>
                    <div class="user-menu-panel">
                        <div class="user-head">
                            <strong><?= e($userName) ?></strong>
                            <span><?= e($userEmail !== '' ? $userEmail : $userRole) ?></span>
                        </div>
                        <a href="<?= e($adminBase . '?view=profile') ?>"><?= AdminUi::icon('user', 16) ?> Profil saya</a>
                        <a href="<?= e($adminBase . '?view=settings') ?>"><?= AdminUi::icon('settings', 16) ?> Pengaturan</a>
                        <a href="<?= e($adminBase . '?view=logs') ?>"><?= AdminUi::icon('activity', 16) ?> Log aktivitas</a>
                        <div class="user-menu-sep"></div>
                        <form method="post" action="<?= e($adminBase) ?>?view=dashboard" class="inline">
                            <input type="hidden" name="action" value="logout">
                            <?= Csrf::field() ?>
                            <button type="submit" class="danger-text"><?= AdminUi::icon('log-out', 16) ?> Keluar</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        <main class="main" id="main-content" tabindex="-1">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="<?= e($adminBase . '?view=dashboard') ?>">Beranda</a>
                <?php if ($currentGroup !== ''): ?>
                    <span class="sep" aria-hidden="true">/</span><span><?= e($currentGroup) ?></span>
                <?php endif; ?>
                <span class="sep" aria-hidden="true">/</span>
                <span class="current" aria-current="page"><?= e($currentLabel) ?></span>
            </nav>

            <?php if ($flash): ?>
            <div class="toast-stack" role="status" aria-live="polite">
                <?php foreach ($flash as $message): ?>
                    <?php
                    $type = (string)($message['type'] ?? 'success');
                    $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
                    $toastIcon = ['success' => 'check-circle', 'error' => 'x-circle', 'warning' => 'alert', 'info' => 'info'][$type];
                    ?>
                    <div class="toast <?= e($type) ?>">
                        <span class="toast-icon"><?= AdminUi::icon($toastIcon, 18) ?></span>
                        <span class="toast-body"><?= e((string)($message['message'] ?? '')) ?></span>
                        <button type="button" class="toast-close" data-toast-close aria-label="Tutup notifikasi">
                            <?= AdminUi::icon('x', 15) ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script>document.body.dataset.adminBase = <?= json_encode($adminBase) ?>;</script>
<script src="<?= e(asset_url('assets/admin-app.js')) ?>" defer></script>
</body>
</html>
