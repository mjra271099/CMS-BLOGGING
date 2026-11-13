<?php
declare(strict_types=1);

/**
 * Admin shell: top bar, grouped sidebar navigation, flash messages.
 * Every admin screen is rendered inside this layout by AdminController.
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
$brandLogo = Settings::get('logo_url');
$userName = (string)($user['display_name'] ?? '') ?: (string)($user['username'] ?? 'Pengguna');
$activeBlogName = (string)($activeBlog['name'] ?? $siteName);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/admin.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/admin-app.css')) ?>">
    <?php if (Settings::get('favicon_url') !== ''): ?>
        <link rel="icon" href="<?= e(media_url(Settings::get('favicon_url'))) ?>">
    <?php endif; ?>
</head>
<body class="admin-body">
<header class="top">
    <strong>
        <?php if ($brandLogo !== ''): ?>
            <img src="<?= e(media_url($brandLogo)) ?>" alt="" style="height:26px;width:auto;border-radius:6px">
        <?php endif; ?>
        <?= e($activeBlogName) ?>
        <small>Panel Admin</small>
    </strong>
    <div class="top-actions">
        <a href="<?= e(url()) ?>" target="_blank" rel="noopener">Lihat situs ↗</a>
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
        <span class="top-user" title="<?= e((string)($user['email'] ?? '')) ?>"><?= e($userName) ?></span>
        <form method="post" action="<?= e($adminBase) ?>?view=dashboard" class="inline">
            <input type="hidden" name="action" value="logout">
            <?= Csrf::field() ?>
            <button type="submit" class="secondary small">Keluar</button>
        </form>
    </div>
</header>

<div class="layout">
    <nav aria-label="Navigasi admin">
        <?php foreach ($nav as $group => $items): ?>
            <div class="nav-section"><?= e($group) ?></div>
            <?php foreach ($items as $key => $item): ?>
                <?php [$label, $icon] = [(string)($item[0] ?? $key), (string)($item[1] ?? 'grid')]; ?>
                <a href="<?= e($adminBase . '?view=' . $key) ?>"<?= AdminUi::active($view, (string)$key) ?>>
                    <span class="nav-icon"><?= AdminUi::icon($icon) ?></span>
                    <?= e($label) ?>
                    <?php if (!empty($badges[$key])): ?>
                        <span class="badge"><?= (int)$badges[$key] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <main class="main">
        <?php foreach ($flash as $message): ?>
            <?php
            $type = (string)($message['type'] ?? 'success');
            $class = $type === 'error' ? 'error' : ($type === 'warning' ? 'warning' : 'notice');
            ?>
            <div class="<?= $class ?>"><?= e((string)($message['message'] ?? '')) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</div>

<script>document.body.dataset.adminBase = <?= json_encode($adminBase) ?>;</script>
<script src="<?= e(asset_url('assets/admin-editor.js')) ?>" defer></script>
<script src="<?= e(asset_url('assets/admin-app.js')) ?>" defer></script>
</body>
</html>
