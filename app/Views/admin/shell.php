<?php
declare(strict_types=1);

/**
 * Admin shell: collapsible sidebar, top bar with search / theme toggle / user
 * menu, breadcrumb and the flash toasts. Every admin screen is rendered inside
 * this layout by AdminController.
 *
 * @var string $title
 * @var string $view
 * @var string $content
 * @var array  $user
 * @var array  $blogs
 * @var array  $activeBlog
 * @var array  $flash
 * @var array  $nav
 * @var int    $commentsPending
 * @var string $siteName
 */

use App\Core\Csrf;
use App\Core\Settings;
use App\Http\AdminController;
use App\Http\AdminUi;
use App\Models\Post;

$flash = is_array($flash ?? null) ? $flash : [];
$user = is_array($user ?? null) ? $user : [];
$blogs = is_array($blogs ?? null) ? $blogs : [];
$activeBlog = is_array($activeBlog ?? null) ? $activeBlog : [];
$nav = is_array($nav ?? null) ? $nav : [];
$commentsPending = (int)($commentsPending ?? 0);
$siteName = (string)($siteName ?? Settings::get('site_name', 'CMS'));
$adminBase = url('admin/index.php');
$title = (string)($title ?? 'Admin');
$view = (string)($view ?? 'dashboard');
$content = (string)($content ?? '');

// Draft count is shown as a badge next to "Postingan".
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

// Breadcrumb: resolve the current navigation label + group.
$currentLabel = 'Ringkasan';
$currentGroup = '';
foreach ($nav as $group => $items) {
    foreach ((array)$items as $key => $item) {
        if ((string)$key === $view) {
            $currentLabel = (string)($item[0] ?? $key);
            $currentGroup = (string)$group;
        }
    }
}

// Editor screens load the Tiptap bundle and its styles.
$isEditor = in_array($view, ['post-edit', 'page-edit'], true);
?>
<!doctype html>
<html lang="<?= e(\App\Core\Lang::htmlLocale()) ?>" data-theme="light">
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
    <?php if ($isEditor): ?>
        <link rel="stylesheet" href="<?= e(asset_url('assets/admin-editor.css')) ?>">
    <?php endif; ?>
    <?php if (Settings::get('favicon_url') !== ''): ?>
        <link rel="icon" href="<?= e(media_url(Settings::get('favicon_url'))) ?>">
    <?php endif; ?>
</head>
<body class="admin-body">
<script>
try { if (localStorage.getItem('cms-sidebar') === 'collapsed') document.body.classList.add('sidebar-collapsed'); } catch (e) {}
</script>
<a class="skip-link" href="#main-content"><?= e(__('admin_skip_to_content')) ?></a>

<div class="admin-shell" id="admin-shell">
    <aside class="sidebar" id="admin-sidebar" aria-label="<?= e(__('admin_main_nav')) ?>">
        <div class="sidebar-brand">
            <a class="brand-link" href="<?= e($adminBase . '?view=dashboard') ?>">
                <?php if ($brandLogo !== ''): ?>
                    <img class="brand-logo" src="<?= e(media_url($brandLogo)) ?>" alt="">
                <?php else: ?>
                    <span class="brand-mark" aria-hidden="true"><?= e(mb_substr($activeBlogName, 0, 1)) ?></span>
                <?php endif; ?>
                <span class="brand-text">
                    <strong><?= e($activeBlogName) ?></strong>
                    <small><?= e(__('admin_panel')) ?></small>
                </span>
            </a>
            <button type="button" class="sidebar-collapse" data-sidebar-collapse aria-label="<?= e(__('admin_collapse_sidebar')) ?>" title="<?= e(__('admin_collapse_sidebar')) ?>">
                <?= AdminUi::icon('panel-left', 16) ?>
            </button>
            <button type="button" class="icon-btn sidebar-close" data-sidebar-close aria-label="<?= e(__('admin_close_nav')) ?>">
                <?= AdminUi::icon('x', 18) ?>
            </button>
        </div>

        <nav class="sidebar-nav" aria-label="<?= e(__('admin_nav')) ?>">
            <?php foreach ($nav as $group => $items): ?>
                <div class="nav-section"><?= e($group) ?></div>
                <?php foreach ((array)$items as $key => $item): ?>
                    <?php
                    [$label, $icon] = [(string)($item[0] ?? $key), (string)($item[1] ?? 'grid')];
                    // Modules that live on their own URL link there instead of ?view=key.
                    $moduleUrl = AdminController::moduleUrl((string)$key);
                    ?>
                    <a href="<?= e($moduleUrl ?? ($adminBase . '?view=' . $key)) ?>"<?= AdminUi::active($view, (string)$key) ?>>
                        <span class="nav-icon"><?= AdminUi::icon($icon, 17) ?></span>
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
                    <small><?= e($userRole !== '' ? $userRole : __('admin_profile')) ?></small>
                </span>
            </a>
        </div>
    </aside>

    <button type="button" class="sidebar-scrim" data-sidebar-close aria-label="<?= e(__('admin_close_nav')) ?>" hidden></button>

    <div class="admin-main">
        <header class="top">
            <button type="button" class="icon-btn sidebar-toggle" data-sidebar-toggle aria-label="<?= e(__('admin_open_nav')) ?>" aria-controls="admin-sidebar" aria-expanded="false">
                <?= AdminUi::icon('panel-left', 19) ?>
            </button>

            <form class="top-search" role="search" method="get" action="<?= e($adminBase) ?>">
                <input type="hidden" name="view" value="posts">
                <span class="icon" aria-hidden="true"><?= AdminUi::icon('search', 16) ?></span>
                <input type="search" name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>"
                       placeholder="<?= e(__('admin_search_articles')) ?>" aria-label="<?= e(__('admin_search_articles_label')) ?>" autocomplete="off">
            </form>

            <div class="top-actions">
                <?php if (count($blogs) > 1): ?>
                    <form method="post" action="<?= e($adminBase) ?>?view=dashboard" class="blog-switcher">
                        <input type="hidden" name="action" value="blog.switch">
                        <?= Csrf::field() ?>
                        <select name="blog_id" onchange="this.form.submit()" aria-label="Pilih blog">
                            <?php foreach ($blogs as $blog): ?>
                                <option value="<?= (int)$blog['id'] ?>"<?= (int)($activeBlog['id'] ?? 0) === (int)$blog['id'] ? ' selected' : '' ?>>
                                    <?= e((string)$blog['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>

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
                    $icon = ['success' => 'check-circle', 'error' => 'x-circle', 'warning' => 'alert', 'info' => 'info'][$type];
                    ?>
                    <div class="toast <?= e($type) ?>">
                        <span class="toast-icon"><?= AdminUi::icon($icon, 18) ?></span>
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
<?php if ($isEditor): ?>
    <script src="<?= e(asset_url('assets/vendor/tiptap/editor.js')) ?>" defer></script>
    <script src="<?= e(asset_url('assets/admin-embed.js')) ?>" defer></script>
<?php endif; ?>
<script src="<?= e(asset_url('assets/admin-app.js')) ?>" defer></script>
</body>
</html>
