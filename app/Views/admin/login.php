<?php
declare(strict_types=1);

/**
 * Admin login screen.
 *
 * @var string $error
 * @var string $siteName
 * @var string $logo
 * @var string $csrf
 */

$error = $error ?? '';
$siteName = $siteName ?? 'CMS';
$logo = $logo ?? '';
?>
<!doctype html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light dark">
    <title>Masuk — <?= e($siteName) ?></title>
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
</head>
<body class="login-page">
<main class="login">
    <div class="login-logo">
        <?php if ($logo !== ''): ?>
            <img src="<?= e(media_url($logo)) ?>" alt="<?= e($siteName) ?>">
        <?php else: ?>
            <div class="login-brand"><?= e($siteName) ?></div>
        <?php endif; ?>
    </div>
    <h1>Panel Admin</h1>
    <p class="muted" style="text-align:center">Masuk untuk mengelola blog Anda.</p>

    <?php if ($error !== ''): ?>
        <div class="error" style="margin-top:18px"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin/index.php?view=login')) ?>" novalidate>
        <?= $csrf ?>
        <label for="login">Username atau email</label>
        <input id="login" type="text" name="login" value="<?= e((string)($_POST['login'] ?? '')) ?>" autocomplete="username" required autofocus>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>

        <button type="submit" style="width:100%;margin-top:22px">Masuk</button>
    </form>

    <div class="login-footer">
        <a href="<?= e(url()) ?>">← Kembali ke situs</a>
    </div>
</main>
</body>
</html>
