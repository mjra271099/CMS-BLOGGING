<?php
declare(strict_types=1);

/**
 * Web installer for fresh installations.
 *
 * Writes config.php, creates the v2 schema (database/schema.sql), registers
 * the bundled themes and creates the first administrator + blog. Existing
 * installations (config.php present) are sent to the login screen; legacy
 * databases are upgraded with `php bin/console.php migrate` instead.
 */

$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    header('Location: admin/index.php');
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
$suggestedUrl = ($isHttps ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string)($_POST['db_host'] ?? ''));
    $name = trim((string)($_POST['db_name'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');
    $port = (int)($_POST['db_port'] ?? 3306);
    $siteName = trim((string)($_POST['site_name'] ?? ''));
    $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/') . '/';
    $username = trim((string)($_POST['admin_username'] ?? ''));
    $email = trim((string)($_POST['admin_email'] ?? ''));
    $password = (string)($_POST['admin_password'] ?? '');
    $siteUrlParts = parse_url($siteUrl);

    if ($host === '' || $name === '' || $user === '' || $port < 1 || $port > 65535 || $siteName === '' || $siteUrl === '/'
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

            // 1. Prove the credentials work before touching config.php.
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );

            // 2. Write config.php atomically so bootstrap can load it.
            $config = [
                'db_host' => $host,
                'db_port' => $port,
                'db_name' => $name,
                'db_user' => $user,
                'db_pass' => $pass,
                'db_charset' => 'utf8mb4',
                'site_url' => $siteUrl,
                'debug' => false,
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
            unset($pdo); // Database opens its own connection from the new config.

            require __DIR__ . '/app/bootstrap.php';

            // Refuse to run over a legacy schema that needs the migration path.
            if (\App\Core\Database::tableExists('posts')
                && !\App\Core\Database::tableExists('blogs')) {
                throw new RuntimeException(
                    'Database ini berisi instalasi lama. Unggah folder baru lalu jalankan: '
                    . 'php bin/console.php migrate'
                );
            }

            // 3. Schema + bundled themes.
            \App\Core\Migrator::installSchema();
            \App\Core\Migrator::markAllShippedAsApplied();
            \App\Core\ThemeEngine::syncBundledThemes();

            $existingUsers = (int)(\App\Core\Database::value('SELECT COUNT(*) FROM users') ?: 0);
            if ($existingUsers > 0) {
                header('Location: admin/index.php');
                exit;
            }

            // 4. First user, first blog, defaults, menus.
            \App\Core\Database::transaction(static function () use ($siteName, $siteUrl, $username, $email, $password): void {
                $userId = \App\Core\Database::insert('users', [
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'display_name' => $username,
                    'role' => 'admin',
                ]);
                $blogId = \App\Core\Database::insert('blogs', [
                    'owner_id' => $userId,
                    'name' => $siteName,
                    'slug' => \App\Core\Slug::make($siteName, 'blog'),
                    'description' => '',
                    'theme' => 'aurora',
                    'language' => 'id',
                    'timezone' => 'Asia/Jakarta',
                    'is_primary' => 1,
                    'status' => 'active',
                ]);
                \App\Core\Database::insert('blog_users', [
                    'blog_id' => $blogId,
                    'user_id' => $userId,
                    'role' => 'owner',
                    'is_owner' => 1,
                ]);
                \App\Core\Database::insert('menus', [
                    'blog_id' => $blogId,
                    'name' => 'Menu Utama',
                    'slug' => 'primary',
                    'location' => 'primary',
                ]);
                \App\Core\Settings::setMany(\App\Core\Settings::defaults(), $blogId);
                \App\Core\Settings::setMany([
                    'site_name' => $siteName,
                    'site_url' => $siteUrl,
                    'theme' => 'aurora',
                ], $blogId);
                \App\Core\Context::forceBlog($blogId);
                log_activity('install', 'CMS dipasang melalui web installer');
            });

            // 5. Activate the default theme for the new blog.
            \App\Core\ThemeEngine::activate('aurora');

            header('Location: admin/index.php');
            exit;
        } catch (Throwable $exception) {
            if ($configCreated && is_file($configPath)) {
                unlink($configPath);
            }
            error_log('CMS installation failed: ' . $exception->getMessage());
            $error = 'Instalasi gagal: ' . $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalasi CMS</title>
<style>
    :root { color-scheme: light; }
    body { font: 16px/1.55 system-ui, sans-serif; background: #f5f6fa; color: #1f2430; margin: 0; padding: 24px; }
    .box { max-width: 700px; margin: 3vh auto; background: #fff; padding: 28px; border-radius: 12px; box-shadow: 0 10px 32px rgba(20, 30, 60, .08); }
    h1 { margin-top: 0; }
    label { display: block; font-weight: 600; margin: 14px 0 5px; }
    input { width: 100%; padding: 10px 12px; border: 1px solid #b9c0cc; border-radius: 6px; box-sizing: border-box; }
    button { background: #1a5fd0; color: #fff; border: 0; padding: 12px 20px; border-radius: 6px; margin-top: 22px; cursor: pointer; font-size: 16px; }
    .error { background: #fee; border: 1px solid #f3b4b4; padding: 12px; border-radius: 6px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
    @media (max-width: 620px) { .grid { grid-template-columns: 1fr; } }
    .hint { color: #5b6472; font-size: 14px; }
</style>
</head>
<body>
<main class="box">
    <h1>Instalasi CMS</h1>
    <p class="hint">Masukkan detail database MySQL/MariaDB yang sudah dibuat di hosting. Setelah selesai, hapus atau kunci kembali <code>install.php</code>.</p>
    <?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <h2>Database</h2>
        <div class="grid">
            <div>
                <label for="db_host">Host database</label>
                <input id="db_host" name="db_host" required value="<?= htmlspecialchars((string)($_POST['db_host'] ?? 'localhost'), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label for="db_name">Nama database</label>
                <input id="db_name" name="db_name" required value="<?= htmlspecialchars((string)($_POST['db_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label for="db_port">Port database</label>
                <input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= htmlspecialchars((string)($_POST['db_port'] ?? '3306'), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label for="db_user">Pengguna database</label>
                <input id="db_user" name="db_user" required value="<?= htmlspecialchars((string)($_POST['db_user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label for="db_pass">Password database</label>
                <input id="db_pass" name="db_pass" type="password">
            </div>
        </div>
        <h2>Website</h2>
        <label for="site_name">Nama website</label>
        <input id="site_name" name="site_name" required value="<?= htmlspecialchars((string)($_POST['site_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="site_url">URL website (diakhiri /)</label>
        <input id="site_url" name="site_url" type="url" required value="<?= htmlspecialchars((string)($_POST['site_url'] ?? $suggestedUrl), ENT_QUOTES, 'UTF-8') ?>">
        <h2>Administrator</h2>
        <label for="admin_username">Username (3–80 karakter)</label>
        <input id="admin_username" name="admin_username" required minlength="3" maxlength="80" value="<?= htmlspecialchars((string)($_POST['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="admin_email">Email</label>
        <input id="admin_email" name="admin_email" type="email" required value="<?= htmlspecialchars((string)($_POST['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="admin_password">Password (minimal 12 karakter)</label>
        <input id="admin_password" name="admin_password" type="password" required minlength="12">
        <button type="submit">Pasang CMS</button>
    </form>
</main>
</body>
</html>
