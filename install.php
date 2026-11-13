<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    header('Location: index.php');
    exit;
}

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals((string)$_SESSION['install_csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    exit('Token keamanan tidak valid. Muat ulang halaman instalasi.');
}

$error = '';
$configCreated = false;
$suggestedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string)($_POST['db_host'] ?? ''));
    $name = trim((string)($_POST['db_name'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');
    $siteName = trim((string)($_POST['site_name'] ?? ''));
    $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/') . '/';
    $username = trim((string)($_POST['admin_username'] ?? ''));
    $email = trim((string)($_POST['admin_email'] ?? ''));
    $password = (string)($_POST['admin_password'] ?? '');
    $siteUrlParts = parse_url($siteUrl);

    if ($host === '' || $name === '' || $user === '' || $siteName === '' || $siteUrl === '/'
        || !filter_var($siteUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($siteUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
        || !is_array($siteUrlParts) || empty($siteUrlParts['host']) || isset($siteUrlParts['query']) || isset($siteUrlParts['fragment'])
        || isset($siteUrlParts['user']) || isset($siteUrlParts['pass'])
        || !preg_match('/^[a-zA-Z0-9_]{3,80}$/', $username)
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
        $error = 'Lengkapi semua kolom dengan benar. Password administrator minimal 12 karakter.';
    } else {
        try {
            if (!is_writable(__DIR__)) {
                throw new RuntimeException('Folder aplikasi tidak dapat ditulis.');
            }
            $pdo = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Schema database tidak dapat dibaca.');
            }
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
                $pdo->exec($statement);
            }
            $pdo->beginTransaction();
            $userStmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
            $userStmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int)$pdo->lastInsertId();

            $settings = [
                'site_name' => $siteName,
                'site_description' => 'V-Dey: kumpulan video viral, trending, dan pilihan terbaru dengan akses mudah dan cepat. Update setiap hari.',
                'site_url' => $siteUrl,
                'posts_per_page' => '7',
                'permalink_format' => 'blogger',
                'homepage_mode' => 'posts',
                'homepage_page_id' => '0',
                'meta_title_suffix' => $siteName,
                'favicon_url' => $siteUrl . 'favicon.ico',
                'google_site_verification' => 'fGxZNVoOq2QuBqCT3h6HV_GMBVtXFJ4bVJ4hbdY2rxg',
                'allow_comments' => '1',
                'comment_moderation' => '1',
                'theme_css' => '',
                'theme_template_html' => '',
                'theme_header_code' => '',
                'theme_footer_code' => '',
            ];
            $settingStmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
            foreach ($settings as $key => $value) {
                $settingStmt->execute([$key, $value]);
            }

            $widgets = json_decode((string)file_get_contents(__DIR__ . '/assets/template-widgets.json'), true, 512, JSON_THROW_ON_ERROR);
            $widgetStmt = $pdo->prepare(
                'INSERT INTO widgets (widget_key, title, type, section_id, content, config_json, enabled, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $orders = [];
            foreach ($widgets as $widget) {
                $section = $widget['section'];
                $order = $orders[$section] ?? 0;
                $orders[$section] = $order + 1;
                $content = $widget['settings']['content'] ?? '';
                if ($widget['type'] === 'PageList') {
                    $content = '';
                }
                $widgetStmt->execute([
                    $widget['id'],
                    $widget['title'],
                    $widget['type'],
                    $section,
                    $content,
                    json_encode($widget['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $widget['visible'] ? 1 : 0,
                    $order,
                ]);
            }
            $activity = $pdo->prepare('INSERT INTO activity_log (user_id, action, detail) VALUES (?, ?, ?)');
            $activity->execute([$userId, 'install', 'CMS berhasil dipasang']);

            $config = [
                'db_host' => $host,
                'db_name' => $name,
                'db_user' => $user,
                'db_pass' => $pass,
                'db_charset' => 'utf8mb4',
            ];
            $configCode = "<?php\nreturn " . var_export($config, true) . ";\n";
            $temporaryConfig = $configPath . '.tmp.' . bin2hex(random_bytes(6));
            if (file_put_contents($temporaryConfig, $configCode, LOCK_EX) === false || !rename($temporaryConfig, $configPath)) {
                if (is_file($temporaryConfig)) {
                    unlink($temporaryConfig);
                }
                throw new RuntimeException('Tidak dapat menulis config.php. Berikan izin tulis sementara pada folder aplikasi.');
            }
            $configCreated = true;
            $pdo->commit();
            header('Location: admin/index.php');
            exit;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($configCreated && is_file($configPath)) {
                unlink($configPath);
            }
            error_log('CMS installation failed: ' . $exception->getMessage());
            $error = 'Instalasi gagal. Periksa koneksi database, izin tulis folder aplikasi, dan log PHP hosting.';
        }
    }
}
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Instalasi CMS</title>
<style>body{font:16px system-ui;background:#f5f6fa;color:#222;margin:0;padding:24px}.box{max-width:680px;margin:3vh auto;background:#fff;padding:24px;border-radius:8px}label{display:block;font-weight:600;margin:14px 0 5px}input{width:100%;padding:10px;border:1px solid #bbb;border-radius:4px;box-sizing:border-box}button{background:#1a5fd0;color:white;border:0;padding:12px 18px;border-radius:5px;margin-top:20px;cursor:pointer}.error{background:#fee;padding:12px;border-radius:4px}</style>
</head><body><main class="box"><h1>Instalasi CMS</h1><p>Masukkan detail database MySQL/MariaDB yang sudah dibuat di hosting.</p>
<?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<h2>Database</h2>
<label for="db_host">Host database</label><input id="db_host" name="db_host" required value="<?= htmlspecialchars((string)($_POST['db_host'] ?? 'localhost'), ENT_QUOTES, 'UTF-8') ?>">
<label for="db_name">Nama database</label><input id="db_name" name="db_name" required value="<?= htmlspecialchars((string)($_POST['db_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<label for="db_user">Pengguna database</label><input id="db_user" name="db_user" required value="<?= htmlspecialchars((string)($_POST['db_user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<label for="db_pass">Password database</label><input id="db_pass" name="db_pass" type="password">
<h2>Website</h2>
<label for="site_name">Nama website</label><input id="site_name" name="site_name" required value="<?= htmlspecialchars((string)($_POST['site_name'] ?? 'V-Dey'), ENT_QUOTES, 'UTF-8') ?>">
<label for="site_url">URL website (diakhiri /)</label><input id="site_url" name="site_url" type="url" required value="<?= htmlspecialchars((string)($_POST['site_url'] ?? $suggestedUrl), ENT_QUOTES, 'UTF-8') ?>">
<h2>Administrator</h2>
<label for="admin_username">Username (3–80 karakter)</label><input id="admin_username" name="admin_username" required minlength="3" maxlength="80" value="<?= htmlspecialchars((string)($_POST['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<label for="admin_email">Email</label><input id="admin_email" name="admin_email" type="email" required value="<?= htmlspecialchars((string)($_POST['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<label for="admin_password">Password (minimal 12 karakter)</label><input id="admin_password" name="admin_password" type="password" required minlength="12">
<button type="submit">Pasang CMS</button>
</form></main></body></html>
