<?php
declare(strict_types=1);
require dirname(__DIR__) . '/functions.php';

$error = '';
$notice = '';
$view = (string)($_GET['view'] ?? 'dashboard');
$validViews = ['dashboard', 'posts', 'pages', 'layout', 'theme', 'comments', 'settings', 'media'];
if (!in_array($view, $validViews, true)) {
    $view = 'dashboard';
}

function admin_url(string $view = 'dashboard', array $params = []): string
{
    return url('admin/index.php?' . http_build_query(['view' => $view] + $params));
}

function admin_date_input(?string $date): string
{
    return $date ? date('Y-m-d\TH:i', strtotime($date)) : '';
}

function scheduled_date(string $input, string $status, ?string $previous = null): ?string
{
    if ($input === '') {
        return $status === 'published' ? ($previous ?: date('Y-m-d H:i:s')) : null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Tanggal publikasi tidak valid.');
    }
    return $date->format('Y-m-d H:i:s');
}

function split_names(string $value): array
{
    $names = array_map('trim', explode(',', $value));
    $names = array_values(array_unique(array_filter($names, static fn(string $name): bool => $name !== '')));
    foreach ($names as $name) {
        if (strlen($name) > 120) {
            throw new InvalidArgumentException('Nama kategori dan tag maksimal 120 karakter.');
        }
    }
    return $names;
}

function unique_content_slug(string $table, string $slug, int $excludeId): string
{
    if (!in_array($table, ['posts', 'pages'], true)) {
        throw new InvalidArgumentException('Jenis konten tidak valid.');
    }
    $base = $slug;
    $suffix = 2;
    $stmt = db()->prepare("SELECT id FROM {$table} WHERE slug=? AND id<>? LIMIT 1");
    do {
        $stmt->execute([$slug, $excludeId]);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $suffix++;
    } while (true);
}

function persist_taxonomy(int $postId, string $table, string $joinTable, string $names): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM {$joinTable} WHERE post_id=?")->execute([$postId]);
    $insertItem = $pdo->prepare("INSERT INTO {$table} (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $linkItem = $pdo->prepare("INSERT INTO {$joinTable} (post_id, " . ($table === 'categories' ? 'category_id' : 'tag_id') . ') VALUES (?, ?)');
    foreach (split_names($names) as $name) {
        $insertItem->execute([$name, slugify($name)]);
        $id = (int)$pdo->lastInsertId();
        $linkItem->execute([$postId, $id]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'login') {
            $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $attemptStmt = db()->prepare('SELECT attempts, locked_until FROM login_attempts WHERE ip_hash=?');
            $attemptStmt->execute([$ipHash]);
            $attempt = $attemptStmt->fetch();
            if ($attempt && $attempt['locked_until'] && strtotime($attempt['locked_until']) > time()) {
                throw new InvalidArgumentException('Login sementara dikunci setelah terlalu banyak percobaan. Coba lagi beberapa menit.');
            }
            if ($attempt && $attempt['locked_until']) {
                db()->prepare('DELETE FROM login_attempts WHERE ip_hash=?')->execute([$ipHash]);
                $attempt = null;
            }
            $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username=? OR email=? LIMIT 1');
            $login = trim((string)($_POST['login'] ?? ''));
            $stmt->execute([$login, $login]);
            $account = $stmt->fetch();
            if ($account && password_verify((string)($_POST['password'] ?? ''), $account['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$account['id'];
                db()->prepare('DELETE FROM login_attempts WHERE ip_hash=?')->execute([$ipHash]);
                log_activity('login', 'Administrator masuk');
                header('Location: ' . admin_url());
                exit;
            }
            $attemptCount = $attempt ? (int)$attempt['attempts'] + 1 : 1;
            $lockedUntil = $attemptCount >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            db()->prepare(
                'INSERT INTO login_attempts (ip_hash, attempts, locked_until) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE attempts=VALUES(attempts), locked_until=VALUES(locked_until)'
            )->execute([$ipHash, $attemptCount, $lockedUntil]);
            $error = 'Username/email atau password tidak sesuai.';
        } elseif ($action === 'logout') {
            require_admin();
            log_activity('logout', 'Administrator keluar');
            $_SESSION = [];
            session_destroy();
            header('Location: ' . url('admin/index.php'));
            exit;
        } else {
            $user = require_admin();
            $pdo = db();
            if ($action === 'save_post') {
                $id = max(0, (int)($_POST['id'] ?? 0));
                $title = trim((string)($_POST['title'] ?? ''));
                $slug = slugify(trim((string)($_POST['slug'] ?? '')) ?: $title);
                $slug = unique_content_slug('posts', $slug, $id);
                $body = (string)($_POST['body'] ?? '');
                $status = in_array($_POST['status'] ?? '', ['draft', 'published'], true) ? (string)$_POST['status'] : 'draft';
                $previousStmt = $id > 0 ? $pdo->prepare('SELECT published_at FROM posts WHERE id=?') : null;
                if ($previousStmt) {
                    $previousStmt->execute([$id]);
                    $previousDate = $previousStmt->fetchColumn() ?: null;
                } else {
                    $previousDate = null;
                }
                $publishedAt = scheduled_date(trim((string)($_POST['published_at'] ?? '')), $status, $previousDate);
                if ($title === '' || $body === '' || strlen($title) > 255 || strlen($slug) > 255) {
                    throw new InvalidArgumentException('Judul dan isi postingan wajib diisi; judul maksimal 255 karakter.');
                }
                $thumbnail = trim((string)($_POST['thumbnail'] ?? ''));
                if (!valid_asset_url($thumbnail)) {
                    throw new InvalidArgumentException('Thumbnail harus memakai URL HTTP(S) atau path lokal.');
                }
                $pdo->beginTransaction();
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE posts SET title=?, slug=?, body=?, excerpt=?, thumbnail=?, meta_title=?, meta_description=?, status=?, featured=?, published_at=? WHERE id=?');
                    $stmt->execute([
                        $title, $slug, $body, trim((string)($_POST['excerpt'] ?? '')),
                        $thumbnail ?: null, trim((string)($_POST['meta_title'] ?? '')),
                        trim((string)($_POST['meta_description'] ?? '')), $status,
                        isset($_POST['featured']) ? 1 : 0, $publishedAt, $id,
                    ]);
                    $postId = $id;
                } else {
                    $stmt = $pdo->prepare('INSERT INTO posts (author_id, title, slug, body, excerpt, thumbnail, meta_title, meta_description, status, featured, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([
                        (int)$user['id'], $title, $slug, $body, trim((string)($_POST['excerpt'] ?? '')),
                        $thumbnail ?: null, trim((string)($_POST['meta_title'] ?? '')),
                        trim((string)($_POST['meta_description'] ?? '')), $status,
                        isset($_POST['featured']) ? 1 : 0, $publishedAt,
                    ]);
                    $postId = (int)$pdo->lastInsertId();
                }
                persist_taxonomy($postId, 'categories', 'post_categories', (string)($_POST['categories'] ?? ''));
                persist_taxonomy($postId, 'tags', 'post_tags', (string)($_POST['tags'] ?? ''));
                $pdo->commit();
                log_activity($id > 0 ? 'post.update' : 'post.create', $title);
                if (($_POST['intent'] ?? '') === 'preview') {
                    $previewDate = $publishedAt ?: date('Y-m-d H:i:s');
                    header('Location: ' . url(date('Y/m/', strtotime($previewDate)) . rawurlencode($slug) . '.html?preview=1'));
                    exit;
                }
                header('Location: ' . admin_url('posts'));
                exit;
            } elseif ($action === 'delete_post') {
                $stmt = $pdo->prepare('DELETE FROM posts WHERE id=?');
                $stmt->execute([(int)($_POST['id'] ?? 0)]);
                log_activity('post.delete', 'ID ' . (int)($_POST['id'] ?? 0));
                header('Location: ' . admin_url('posts'));
                exit;
            } elseif ($action === 'save_page') {
                $id = max(0, (int)($_POST['id'] ?? 0));
                $title = trim((string)($_POST['title'] ?? ''));
                $slug = slugify(trim((string)($_POST['slug'] ?? '')) ?: $title);
                $slug = unique_content_slug('pages', $slug, $id);
                $status = in_array($_POST['status'] ?? '', ['draft', 'published'], true) ? (string)$_POST['status'] : 'draft';
                $previousStmt = $id > 0 ? $pdo->prepare('SELECT published_at FROM pages WHERE id=?') : null;
                if ($previousStmt) {
                    $previousStmt->execute([$id]);
                    $previousDate = $previousStmt->fetchColumn() ?: null;
                } else {
                    $previousDate = null;
                }
                $publishedAt = scheduled_date(trim((string)($_POST['published_at'] ?? '')), $status, $previousDate);
                if ($title === '' || trim((string)($_POST['body'] ?? '')) === '' || strlen($title) > 255 || strlen($slug) > 255) {
                    throw new InvalidArgumentException('Judul dan isi halaman wajib diisi.');
                }
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE pages SET title=?, slug=?, body=?, meta_title=?, meta_description=?, status=?, published_at=? WHERE id=?');
                    $stmt->execute([$title, $slug, (string)$_POST['body'], trim((string)($_POST['meta_title'] ?? '')), trim((string)($_POST['meta_description'] ?? '')), $status, $publishedAt, $id]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO pages (author_id, title, slug, body, meta_title, meta_description, status, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([(int)$user['id'], $title, $slug, (string)$_POST['body'], trim((string)($_POST['meta_title'] ?? '')), trim((string)($_POST['meta_description'] ?? '')), $status, $publishedAt]);
                }
                log_activity($id > 0 ? 'page.update' : 'page.create', $title);
                if (($_POST['intent'] ?? '') === 'preview') {
                    header('Location: ' . url('p/' . rawurlencode($slug) . '.html?preview=1'));
                    exit;
                }
                header('Location: ' . admin_url('pages'));
                exit;
            } elseif ($action === 'delete_page') {
                $pdo->prepare('DELETE FROM pages WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
                log_activity('page.delete', 'ID ' . (int)($_POST['id'] ?? 0));
                header('Location: ' . admin_url('pages'));
                exit;
            } elseif ($action === 'save_widget') {
                $id = max(0, (int)($_POST['id'] ?? 0));
                $section = (string)($_POST['section_id'] ?? '');
                $validSections = ['menu', 'top-banner', 'featured', 'middle-content', 'sidebar', 'footer-about', 'footer-links', 'footer-social', 'footer-bottom'];
                if (!in_array($section, $validSections, true)) {
                    throw new InvalidArgumentException('Area widget tidak valid.');
                }
                $type = (string)($_POST['type'] ?? 'HTML');
                if (!in_array($type, ['HTML', 'Text', 'LinkList', 'PopularPosts', 'Label', 'FeaturedPost', 'Header', 'BlogSearch'], true)) {
                    throw new InvalidArgumentException('Jenis widget tidak valid.');
                }
                if ($type === 'Header' && $section !== 'header') {
                    throw new InvalidArgumentException('Widget Header hanya dapat ditempatkan di area Header.');
                }
                if ($type === 'BlogSearch' && $section !== 'search') {
                    throw new InvalidArgumentException('Widget Pencarian hanya dapat ditempatkan di area Pencarian.');
                }
                $title = trim((string)($_POST['title'] ?? ''));
                $content = (string)($_POST['content'] ?? '');
                $configJson = $type === 'LinkList' ? $content : (string)($_POST['config_json'] ?? '{}');
                if ($type === 'LinkList' && trim($configJson) !== '') {
                    $links = json_decode($configJson, true);
                    if (!is_array($links) || !array_is_list($links)) {
                        throw new InvalidArgumentException('Konten Tautan harus berupa JSON array yang valid.');
                    }
                    foreach ($links as $link) {
                        if (!is_array($link) || !is_string($link['name'] ?? null)
                            || !is_string($link['url'] ?? $link['target'] ?? null)) {
                            throw new InvalidArgumentException('Setiap tautan memerlukan name dan url berupa teks.');
                        }
                    }
                }
                if ($type !== 'LinkList' && !is_array(json_decode($configJson, true))) {
                    throw new InvalidArgumentException('Konfigurasi widget harus berupa JSON yang valid.');
                }
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE widgets SET title=?, type=?, section_id=?, content=?, config_json=?, enabled=?, sort_order=? WHERE id=?');
                    $stmt->execute([$title, $type, $section, $content, $type === 'LinkList' ? null : $configJson, isset($_POST['enabled']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0), $id]);
                } else {
                    $key = 'custom-' . bin2hex(random_bytes(6));
                    $stmt = $pdo->prepare('INSERT INTO widgets (widget_key, title, type, section_id, content, config_json, enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$key, $title, $type, $section, $content, $type === 'LinkList' ? null : $configJson, isset($_POST['enabled']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0)]);
                }
                log_activity('layout.save', $title ?: $type);
                header('Location: ' . admin_url('layout'));
                exit;
            } elseif ($action === 'delete_widget') {
                $widgetStmt = $pdo->prepare('SELECT widget_key, type FROM widgets WHERE id=?');
                $widgetStmt->execute([(int)($_POST['id'] ?? 0)]);
                $widgetToDelete = $widgetStmt->fetch();
                if (!$widgetToDelete || $widgetToDelete['type'] === 'Blog' || $widgetToDelete['widget_key'] === 'Blog1') {
                    throw new InvalidArgumentException('Widget Blog utama terkunci dan tidak dapat dihapus.');
                }
                $pdo->prepare('DELETE FROM widgets WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
                log_activity('layout.delete', 'Widget ID ' . (int)($_POST['id'] ?? 0));
                header('Location: ' . admin_url('layout'));
                exit;
            } elseif ($action === 'save_theme') {
                $templateHtml = trim((string)($_POST['theme_template_html'] ?? ''));
                if ($templateHtml !== '' && !str_contains($templateHtml, '{{main_content}}')) {
                    throw new InvalidArgumentException('Template layout harus menyertakan token {{main_content}}.');
                }
                $pdo->prepare('INSERT INTO theme_revisions (css, template_html, header_code, footer_code) VALUES (?, ?, ?, ?)')->execute([
                    setting('theme_css'), setting('theme_template_html'), setting('theme_header_code'), setting('theme_footer_code'),
                ]);
                save_settings([
                    'theme_css' => (string)($_POST['theme_css'] ?? ''),
                    'theme_template_html' => $templateHtml,
                    'theme_header_code' => (string)($_POST['theme_header_code'] ?? ''),
                    'theme_footer_code' => (string)($_POST['theme_footer_code'] ?? ''),
                ]);
                log_activity('theme.save', 'Pengaturan tema diperbarui');
                header('Location: ' . admin_url('theme'));
                exit;
            } elseif ($action === 'restore_theme') {
                $stmt = $pdo->prepare('SELECT * FROM theme_revisions WHERE id=?');
                $stmt->execute([(int)($_POST['id'] ?? 0)]);
                $revision = $stmt->fetch();
                if (!$revision) {
                    throw new InvalidArgumentException('Versi tema tidak ditemukan.');
                }
                $pdo->prepare('INSERT INTO theme_revisions (css, template_html, header_code, footer_code) VALUES (?, ?, ?, ?)')->execute([
                    setting('theme_css'), setting('theme_template_html'), setting('theme_header_code'), setting('theme_footer_code'),
                ]);
                save_settings([
                    'theme_css' => $revision['css'],
                    'theme_template_html' => $revision['template_html'],
                    'theme_header_code' => $revision['header_code'],
                    'theme_footer_code' => $revision['footer_code'],
                ]);
                log_activity('theme.restore', 'Versi tema #' . $revision['id'] . ' dipulihkan');
                header('Location: ' . admin_url('theme'));
                exit;
            } elseif ($action === 'moderate_comment') {
                $status = (string)($_POST['status'] ?? '');
                if (!in_array($status, ['pending', 'approved', 'spam'], true)) {
                    throw new InvalidArgumentException('Status komentar tidak valid.');
                }
                $pdo->prepare('UPDATE comments SET status=? WHERE id=?')->execute([$status, (int)($_POST['id'] ?? 0)]);
                log_activity('comment.moderate', 'Komentar ' . (int)($_POST['id'] ?? 0) . ': ' . $status);
                header('Location: ' . admin_url('comments'));
                exit;
            } elseif ($action === 'delete_comment') {
                $pdo->prepare('DELETE FROM comments WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
                log_activity('comment.delete', 'Komentar ' . (int)($_POST['id'] ?? 0));
                header('Location: ' . admin_url('comments'));
                exit;
            } elseif ($action === 'save_settings') {
                $siteName = trim((string)($_POST['site_name'] ?? ''));
                $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/') . '/';
                if ($siteName === '' || !valid_site_url($siteUrl)) {
                    throw new InvalidArgumentException('Nama dan URL website wajib diisi dengan benar.');
                }
                $logoUrl = trim((string)($_POST['logo_url'] ?? ''));
                $faviconUrl = trim((string)($_POST['favicon_url'] ?? ''));
                if (!valid_asset_url($logoUrl) || !valid_asset_url($faviconUrl)) {
                    throw new InvalidArgumentException('Logo dan favicon harus memakai URL HTTP(S) atau path lokal.');
                }
                $perPage = max(1, min(50, (int)($_POST['posts_per_page'] ?? 7)));
                $homepageMode = in_array($_POST['homepage_mode'] ?? '', ['posts', 'page'], true) ? (string)$_POST['homepage_mode'] : 'posts';
                $homepagePageId = max(0, (int)($_POST['homepage_page_id'] ?? 0));
                if ($homepageMode === 'page' && $homepagePageId === 0) {
                    throw new InvalidArgumentException('Pilih halaman yang akan digunakan sebagai homepage.');
                }
                if ($homepageMode === 'page') {
                    $pageCheck = $pdo->prepare('SELECT id FROM pages WHERE id=? AND status="published" AND published_at<=NOW()');
                    $pageCheck->execute([$homepagePageId]);
                    if (!$pageCheck->fetchColumn()) {
                        throw new InvalidArgumentException('Halaman homepage harus sudah dipublikasikan.');
                    }
                }
                $newPassword = (string)($_POST['new_password'] ?? '');
                if ($newPassword !== '' && strlen($newPassword) < 12) {
                    throw new InvalidArgumentException('Password baru minimal 12 karakter.');
                }
                save_settings([
                    'site_name' => $siteName,
                    'site_description' => trim((string)($_POST['site_description'] ?? '')),
                    'site_url' => $siteUrl,
                    'posts_per_page' => (string)$perPage,
                    'permalink_format' => 'blogger',
                    'homepage_mode' => $homepageMode,
                    'homepage_page_id' => (string)$homepagePageId,
                    'favicon_url' => $faviconUrl,
                    'logo_url' => $logoUrl,
                    'google_site_verification' => trim((string)($_POST['google_site_verification'] ?? '')),
                    'allow_comments' => isset($_POST['allow_comments']) ? '1' : '0',
                    'comment_moderation' => isset($_POST['comment_moderation']) ? '1' : '0',
                ]);
                if ($newPassword !== '') {
                    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
                    session_regenerate_id(true);
                }
                log_activity('settings.save', 'Pengaturan website diperbarui');
                header('Location: ' . admin_url('settings'));
                exit;
            } elseif ($action === 'upload_media') {
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Pilih file gambar yang valid untuk diunggah.');
                }
                $file = $_FILES['file'];
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new InvalidArgumentException('Ukuran file maksimal 5 MB.');
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
                    throw new InvalidArgumentException('Hanya file gambar JPG, PNG, GIF, atau WebP yang diperbolehkan.');
                }
                $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                $directory = dirname(__DIR__) . '/uploads';
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new RuntimeException('Folder upload tidak dapat dibuat.');
                }
                if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
                    throw new RuntimeException('File gagal disimpan ke folder media.');
                }
                $path = 'uploads/' . $filename;
                $stmt = $pdo->prepare('INSERT INTO media (user_id, file_name, file_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([(int)$user['id'], basename((string)$file['name']), $path, $mime, (int)$file['size']]);
                log_activity('media.upload', $path);
                header('Location: ' . admin_url('media'));
                exit;
            } elseif ($action === 'delete_media') {
                $stmt = $pdo->prepare('SELECT file_path FROM media WHERE id=?');
                $stmt->execute([(int)($_POST['id'] ?? 0)]);
                $mediaPath = $stmt->fetchColumn();
                if (!$mediaPath || !str_starts_with((string)$mediaPath, 'uploads/')) {
                    throw new InvalidArgumentException('File media tidak ditemukan.');
                }
                $usage = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE thumbnail LIKE ? OR body LIKE ?');
                $usage->execute(['%' . $mediaPath . '%', '%' . $mediaPath . '%']);
                $widgetUsage = $pdo->prepare('SELECT COUNT(*) FROM widgets WHERE content LIKE ?');
                $widgetUsage->execute(['%' . $mediaPath . '%']);
                if ((int)$usage->fetchColumn() + (int)$widgetUsage->fetchColumn() > 0) {
                    throw new InvalidArgumentException('Media masih dipakai oleh konten. Hapus referensinya terlebih dahulu.');
                }
                $uploadRoot = realpath(dirname(__DIR__) . '/uploads');
                $filePath = realpath(dirname(__DIR__) . '/' . $mediaPath);
                if ($uploadRoot === false || $filePath === false || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR)) {
                    throw new InvalidArgumentException('Lokasi file media tidak valid.');
                }
                if (!unlink($filePath)) {
                    throw new RuntimeException('File media tidak dapat dihapus.');
                }
                $pdo->prepare('DELETE FROM media WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
                log_activity('media.delete', (string)$mediaPath);
                header('Location: ' . admin_url('media'));
                exit;
            }
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('CMS admin error: ' . $exception->getMessage());
        $error = 'Perubahan tidak tersimpan. Periksa data dan log PHP hosting.';
    }
}

$user = current_user();
if (!$user):
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login Admin</title>
<link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>"></head>
<body><main class="login"><h1><?= e(setting('site_name')) ?></h1><h2>Login Administrator</h2>
<?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login">
<label>Username atau email</label><input name="login" required autocomplete="username">
<label>Password</label><input name="password" type="password" required autocomplete="current-password">
<button type="submit">Masuk</button></form></main></body></html>
<?php
exit;
endif;

$counts = [
    'posts' => (int)db()->query('SELECT COUNT(*) FROM posts')->fetchColumn(),
    'pages' => (int)db()->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
    'comments' => (int)db()->query('SELECT COUNT(*) FROM comments')->fetchColumn(),
    'pending' => (int)db()->query('SELECT COUNT(*) FROM comments WHERE status="pending"')->fetchColumn(),
];
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — <?= e(setting('site_name')) ?></title><style>
*{box-sizing:border-box}body{margin:0;background:#f5f6fa;color:#263238;font:15px system-ui,-apple-system,"Segoe UI",sans-serif}a{color:#1a5fd0;text-decoration:none}.top{height:60px;background:#fff;border-bottom:1px solid #e3e7ed;display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:3}.top strong{font-size:18px}.layout{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:calc(100vh - 60px)}nav{background:#fff;border-right:1px solid #e3e7ed;padding:18px 10px}nav a{display:block;padding:11px 14px;border-radius:5px;color:#394b59}nav a.active,nav a:hover{background:#eaf1ff;color:#1458c8}.main{padding:24px;min-width:0}.card{background:#fff;border:1px solid #e4e8ee;border-radius:7px;padding:18px;margin-bottom:18px;overflow:auto}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px}.stat{font-size:30px;font-weight:700;color:#1a5fd0}.stat-label{color:#667085}.row{display:flex;gap:14px;flex-wrap:wrap}.row>*{flex:1;min-width:200px}label{display:block;font-weight:600;margin:12px 0 5px}input[type=text],input[type=email],input[type=url],input[type=password],input[type=datetime-local],input[type=number],select,textarea{width:100%;padding:9px 10px;border:1px solid #cbd2dc;border-radius:4px;font:inherit;background:#fff}textarea{min-height:120px;font-family:ui-monospace,monospace}.body-editor{min-height:360px}.btn,button{display:inline-block;background:#1a5fd0;color:#fff;border:0;padding:9px 14px;border-radius:4px;cursor:pointer;font:inherit}.btn.secondary,button.secondary{background:#eef1f5;color:#283747}.btn.danger,button.danger{background:#c0392b}.inline{display:inline}.error,.notice{padding:12px;border-radius:5px;margin-bottom:14px}.error{background:#ffebee;color:#9b1c1c}.notice{background:#e8f5e9;color:#256029}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{text-align:left;padding:10px;border-bottom:1px solid #edf0f4;vertical-align:top}th{font-size:13px;color:#667085}small.muted{color:#788391}.toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}.pill{display:inline-block;padding:3px 8px;border-radius:20px;background:#edf2f7;font-size:12px}.checkbox{display:flex;align-items:center;gap:8px;margin:12px 0}.checkbox input{width:auto}.help{font-size:13px;color:#667085}.widget-row{display:grid;grid-template-columns:1fr 2fr 130px;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid #eee}.admin-actions{display:flex;gap:8px;align-items:center}@media(max-width:750px){.layout{grid-template-columns:1fr}nav{display:flex;overflow:auto;border-right:0;border-bottom:1px solid #ddd;padding:8px}nav a{white-space:nowrap}.main{padding:14px}.top{padding:0 14px}.widget-row{grid-template-columns:1fr}.row>*{min-width:100%}}
/* TinyMCE adjust */
.tox-tinymce{border-radius:4px!important;border-color:#cbd2dc!important}
</style></head><body>
<header class="top"><strong><?= e(setting('site_name')) ?> <small class="muted">/ Blogger-style CMS</small></strong><div><a href="<?= e(url()) ?>" target="_blank" rel="noopener">Lihat website</a> · <?= e($user['username']) ?>
<form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="secondary">Keluar</button></form></div></header>
<div class="layout"><nav>
<?php foreach (['dashboard'=>'Ringkasan','posts'=>'Postingan','pages'=>'Halaman','layout'=>'Tata Letak','theme'=>'Tema','comments'=>'Komentar','media'=>'Media','settings'=>'Pengaturan'] as $key=>$label): ?>
<a class="<?= $view === $key ? 'active' : '' ?>" href="<?= e(admin_url($key)) ?>"><?= e($label) ?><?= $key === 'comments' && $counts['pending'] ? ' (' . $counts['pending'] . ')' : '' ?></a>
<?php endforeach; ?>
</nav><main class="main">
<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
<?php if ($view === 'dashboard'): ?>
<h1>Ringkasan</h1><div class="grid">
<?php foreach (['posts'=>'Postingan','pages'=>'Halaman','comments'=>'Komentar','pending'=>'Menunggu moderasi'] as $key=>$label): ?>
<section class="card"><div class="stat"><?= $counts[$key] ?></div><div class="stat-label"><?= e($label) ?></div></section>
<?php endforeach; ?></div>
<section class="card"><h2>Postingan terbaru</h2>
<?php $latest = db()->query('SELECT id,title,status,updated_at FROM posts ORDER BY updated_at DESC LIMIT 8')->fetchAll(); ?>
<?php if ($latest): ?><table><thead><tr><th>Judul</th><th>Status</th><th>Diperbarui</th><th></th></tr></thead><tbody><?php foreach ($latest as $item): ?><tr><td><?= e($item['title']) ?></td><td><?= e($item['status']) ?></td><td><?= e($item['updated_at']) ?></td><td><a href="<?= e(admin_url('posts', ['edit'=>(int)$item['id']])) ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p>Belum ada postingan. Tambahkan postingan pertama Anda.</p><?php endif; ?></section>
<section class="card"><h2>Aktivitas terbaru</h2><?php $activities = db()->query('SELECT action,detail,created_at FROM activity_log ORDER BY id DESC LIMIT 10')->fetchAll(); ?>
<?php if ($activities): ?><table><thead><tr><th>Aktivitas</th><th>Detail</th><th>Waktu</th></tr></thead><tbody><?php foreach ($activities as $activity): ?><tr><td><?= e($activity['action']) ?></td><td><?= e($activity['detail']) ?></td><td><?= e($activity['created_at']) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p>Belum ada aktivitas.</p><?php endif; ?></section>
<?php elseif ($view === 'posts' || $view === 'pages'):
    $isPage = $view === 'pages';
    $tableName = $isPage ? 'pages' : 'posts';
    $edit = max(0, (int)($_GET['edit'] ?? 0));
    $record = null;
    if ($edit) {
        $stmt = db()->prepare("SELECT * FROM {$tableName} WHERE id=?");
        $stmt->execute([$edit]);
        $record = $stmt->fetch() ?: null;
    }
?>
<div class="toolbar"><h1><?= $isPage ? 'Halaman' : 'Postingan' ?></h1><a class="btn" href="<?= e(admin_url($view)) ?>">Tambah baru</a></div>
<section class="card"><h2><?= $record ? 'Edit' : 'Tambah' ?> <?= $isPage ? 'halaman' : 'postingan' ?></h2>
<form method="post" novalidate data-content-form><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $isPage ? 'save_page' : 'save_post' ?>"><input type="hidden" name="id" value="<?= (int)($record['id'] ?? 0) ?>">
<div class="row"><div><label>Judul</label><input type="text" name="title" required maxlength="255" value="<?= e($record['title'] ?? '') ?>"></div><div><label>Permalink slug</label><input type="text" name="slug" value="<?= e($record['slug'] ?? '') ?>"><div class="help">URL dibentuk otomatis dari judul jika dikosongkan.</div></div></div>
<label>Konten</label>
<textarea class="body-editor" name="body" spellcheck="false"><?= e($record['body'] ?? '') ?></textarea>
<div class="help">Editor visual (TinyMCE) — gunakan tombol <em>Source code</em> (<code>&lt;/&gt;</code>) untuk edit HTML langsung.</div>
<?php if (!$isPage):
    $selectedCategories = $record ? implode(', ', array_column(post_categories((int)$record['id']), 'name')) : '';
    $tagStmt = $record ? db()->prepare('SELECT t.name FROM tags t JOIN post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=? ORDER BY t.name') : null;
    if ($tagStmt) { $tagStmt->execute([(int)$record['id']]); $selectedTags = implode(', ', array_column($tagStmt->fetchAll(), 'name')); } else { $selectedTags = ''; }
?>
<div class="row"><div><label>Kategori (pisahkan koma)</label><input type="text" name="categories" value="<?= e($selectedCategories) ?>"></div><div><label>Label / tag</label><input type="text" name="tags" value="<?= e($selectedTags) ?>"></div></div>
<div class="row"><div><label>Thumbnail URL</label><input type="url" name="thumbnail" value="<?= e($record['thumbnail'] ?? '') ?>"></div><div><label>Ringkasan</label><textarea name="excerpt"><?= e($record['excerpt'] ?? '') ?></textarea></div></div>
<label class="checkbox"><input type="checkbox" name="featured" <?= !empty($record['featured']) ? 'checked' : '' ?>> Jadikan artikel unggulan</label>
<?php endif; ?>
<div class="row"><div><label>Status</label><select name="status"><option value="draft" <?= ($record['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= ($record['status'] ?? '') === 'published' ? 'selected' : '' ?>>Publikasikan</option></select></div><div><label>Tanggal publikasi/jadwal</label><input type="datetime-local" name="published_at" value="<?= e(admin_date_input($record['published_at'] ?? null)) ?>"><div class="help">Tanggal mendatang akan terbit otomatis saat waktunya tiba.</div></div></div>
<div class="row"><div><label>Meta title</label><input type="text" name="meta_title" maxlength="255" value="<?= e($record['meta_title'] ?? '') ?>"></div><div><label>Meta description</label><textarea name="meta_description" maxlength="320"><?= e($record['meta_description'] ?? '') ?></textarea></div></div>
<button type="submit" name="intent" value="save">Simpan</button> <button type="submit" name="intent" value="preview" class="secondary">Simpan &amp; Preview</button>
</form></section>
<section class="card"><h2>Semua <?= $isPage ? 'halaman' : 'postingan' ?></h2>
<?php $records = db()->query("SELECT id,title,slug,status,updated_at FROM {$tableName} ORDER BY updated_at DESC LIMIT 200")->fetchAll(); ?>
<table><thead><tr><th>Judul</th><th>Status</th><th>Diperbarui</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($records as $item): ?><tr><td><?= e($item['title']) ?><br><small class="muted"><?= e($item['slug']) ?></small></td><td><?= e($item['status']) ?></td><td><?= e($item['updated_at']) ?></td><td class="admin-actions"><a href="<?= e(admin_url($view, ['edit'=>(int)$item['id']])) ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus item ini?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $isPage ? 'delete_page' : 'delete_post' ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="danger" type="submit">Hapus</button></form></td></tr><?php endforeach; ?>
</tbody></table></section>
<?php elseif ($view === 'layout'):
    $widgets = db()->query('SELECT * FROM widgets ORDER BY section_id, sort_order, id')->fetchAll();
    $editId = max(0, (int)($_GET['edit'] ?? 0));
    $editWidget = null;
    foreach ($widgets as $widget) { if ((int)$widget['id'] === $editId) { $editWidget = $widget; break; } }
    $sections = ['header'=>'Header','menu'=>'Menu','search'=>'Pencarian','top-banner'=>'Banner atas','featured'=>'Artikel unggulan','main'=>'Konten utama','middle-content'=>'Konten tengah','sidebar-labels'=>'Label','sidebar'=>'Sidebar','footer-about'=>'Footer tentang','footer-links'=>'Footer tautan','footer-social'=>'Footer sosial','footer-bottom'=>'Footer bawah'];
?>
<div class="toolbar"><h1>Tata Letak</h1><a class="btn" href="<?= e(admin_url('layout', ['add'=>1])) ?>">Tambah widget</a></div>
<?php if ($editWidget || isset($_GET['add'])): ?>
<section class="card"><h2><?= $editWidget ? 'Edit widget' : 'Tambah widget' ?></h2><form method="post">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_widget"><input type="hidden" name="id" value="<?= (int)($editWidget['id'] ?? 0) ?>">
<div class="row"><div><label>Judul</label><input name="title" value="<?= e($editWidget['title'] ?? '') ?>"></div><div><label>Jenis</label><select name="type"><?php foreach (['HTML','Text','LinkList','PopularPosts','Label','FeaturedPost','Header','BlogSearch'] as $type): ?><option <?= ($editWidget['type'] ?? 'HTML') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div></div>
<div class="row"><div><label>Area</label><select name="section_id"><?php foreach ($sections as $id=>$name): ?><option value="<?= e($id) ?>" <?= ($editWidget['section_id'] ?? 'middle-content') === $id ? 'selected' : '' ?>><?= e($name) ?> (<?= e($id) ?>)</option><?php endforeach; ?></select></div><div><label>Urutan</label><input type="number" name="sort_order" value="<?= (int)($editWidget['sort_order'] ?? 0) ?>"></div></div>
<label>Konten widget / link JSON</label><textarea name="content" class="body-editor" spellcheck="false"><?= e($editWidget['content'] ?? '') ?></textarea>
<div class="help">Untuk LinkList, isi array JSON misalnya [{"name":"Tentang","url":"/p/tentang.html"}]. HTML widget dirender sebagai HTML administrator.</div>
<label>Konfigurasi JSON (mis. Popular Posts)</label><textarea name="config_json" spellcheck="false"><?= e($editWidget['config_json'] ?? '{}') ?></textarea>
<label class="checkbox"><input type="checkbox" name="enabled" <?= !isset($editWidget['enabled']) || $editWidget['enabled'] ? 'checked' : '' ?>> Aktif</label>
<button type="submit">Simpan widget</button></form></section>
<?php endif; ?>
<section class="card"><h2>Widget aktif dan susunan area</h2>
<?php foreach ($widgets as $widget): ?><div class="widget-row"><div><strong><?= e($widget['title'] ?: $widget['widget_key']) ?></strong><br><small class="muted"><?= e($sections[$widget['section_id']] ?? $widget['section_id']) ?> · <?= e($widget['type']) ?> · urutan <?= (int)$widget['sort_order'] ?></small></div><div><span class="pill"><?= $widget['enabled'] ? 'Aktif' : 'Nonaktif' ?></span></div><div class="admin-actions"><?php if ($widget['type'] === 'Blog'): ?><span class="pill">Inti · terkunci</span><?php else: ?><a href="<?= e(admin_url('layout', ['edit'=>(int)$widget['id']])) ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus widget?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_widget"><input type="hidden" name="id" value="<?= (int)$widget['id'] ?>"><button type="submit" class="danger">Hapus</button></form><?php endif; ?></div></div><?php endforeach; ?>
</section>
<?php elseif ($view === 'theme'):
    $revisions = db()->query('SELECT id,created_at FROM theme_revisions ORDER BY id DESC LIMIT 30')->fetchAll();
?>
<h1>Tema</h1><section class="card"><h2>Tema aktif: template-Dipake</h2><p>CSS asli dipertahankan pada <code>assets/template.css</code>. Perubahan di bawah ini ditambahkan sebagai override; simpan versi sebelum mengedit dan tinjau pratinjau setelah perubahan.</p>
<p><a class="btn secondary" href="<?= e(url()) ?>" target="_blank" rel="noopener">Preview frontend</a></p>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_theme">
<label>CSS tambahan</label><textarea name="theme_css" class="body-editor" spellcheck="false"><?= e(setting('theme_css')) ?></textarea>
<label>HTML layout frontend</label><p class="muted">Kosongkan untuk memakai layout asli. Jika diisi, sertakan <code>{{main_content}}</code>. Token: <code>{{header}}</code>, <code>{{search}}</code>, <code>{{top_banner}}</code>, <code>{{featured}}</code>, <code>{{main_content}}</code>, <code>{{middle_content}}</code>, <code>{{sidebar}}</code>, <code>{{footer}}</code>.</p><textarea name="theme_template_html" class="body-editor" spellcheck="false"><?= e(setting('theme_template_html')) ?></textarea>
<label>Kode tambahan header</label><textarea name="theme_header_code" spellcheck="false"><?= e(setting('theme_header_code')) ?></textarea>
<label>Kode tambahan footer</label><textarea name="theme_footer_code" spellcheck="false"><?= e(setting('theme_footer_code')) ?></textarea>
<button type="submit">Simpan perubahan tema</button></form></section>
<section class="card"><h2>Backup dan pemulihan tema</h2><?php if (!$revisions): ?><p>Belum ada versi sebelumnya. Setiap penyimpanan akan membuat snapshot tema.</p><?php else: ?><table><thead><tr><th>Versi</th><th>Dibuat</th><th></th></tr></thead><tbody><?php foreach ($revisions as $revision): ?><tr><td>#<?= (int)$revision['id'] ?></td><td><?= e($revision['created_at']) ?></td><td><form method="post" onsubmit="return confirm('Pulihkan versi tema ini? Tema saat ini akan disimpan sebagai snapshot.')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="restore_theme"><input type="hidden" name="id" value="<?= (int)$revision['id'] ?>"><button class="secondary">Pulihkan versi ini</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php elseif ($view === 'comments'):
    $filter = (string)($_GET['status'] ?? 'all');
    $allowed = ['all','pending','approved','spam'];
    if (!in_array($filter, $allowed, true)) { $filter = 'all'; }
    $sql = 'SELECT c.*, p.title AS post_title FROM comments c JOIN posts p ON p.id=c.post_id';
    if ($filter !== 'all') { $stmt = db()->prepare($sql . ' WHERE c.status=? ORDER BY c.created_at DESC LIMIT 200'); $stmt->execute([$filter]); $comments = $stmt->fetchAll(); }
    else { $comments = db()->query($sql . ' ORDER BY c.created_at DESC LIMIT 200')->fetchAll(); }
?>
<h1>Komentar</h1><section class="card">
<p><?php foreach ($allowed as $status): ?> <a class="btn secondary" href="<?= e(admin_url('comments', ['status'=>$status])) ?>"><?= e(ucfirst($status)) ?></a><?php endforeach; ?></p>
<?php if (!$comments): ?><p>Tidak ada komentar.</p><?php else: ?><table><thead><tr><th>Penulis</th><th>Komentar</th><th>Postingan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($comments as $comment): ?><tr><td><?= e($comment['author']) ?><br><small class="muted"><?= e($comment['email']) ?></small></td><td><?= nl2br(e($comment['body'])) ?><br><small class="muted"><?= e($comment['created_at']) ?></small></td><td><?= e($comment['post_title']) ?></td><td><?= e($comment['status']) ?></td><td>
<form method="post" class="admin-actions"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="moderate_comment"><input type="hidden" name="id" value="<?= (int)$comment['id'] ?>"><select name="status"><option <?= $comment['status']==='pending'?'selected':'' ?>>pending</option><option <?= $comment['status']==='approved'?'selected':'' ?>>approved</option><option <?= $comment['status']==='spam'?'selected':'' ?>>spam</option></select><button class="secondary">Simpan</button></form>
<form method="post" onsubmit="return confirm('Hapus komentar?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_comment"><input type="hidden" name="id" value="<?= (int)$comment['id'] ?>"><button class="danger">Hapus</button></form></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></section>
<?php elseif ($view === 'settings'): ?>
<h1>Pengaturan</h1><section class="card"><form method="post">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_settings">
<label>Nama website</label><input name="site_name" required value="<?= e(setting('site_name')) ?>">
<label>Deskripsi website</label><textarea name="site_description"><?= e(setting('site_description')) ?></textarea>
<label>URL website</label><input type="url" name="site_url" required value="<?= e(setting('site_url')) ?>">
<div class="row"><div><label>Logo URL</label><input type="url" name="logo_url" value="<?= e(setting('logo_url')) ?>"></div><div><label>Favicon URL</label><input type="url" name="favicon_url" value="<?= e(setting('favicon_url')) ?>"></div></div>
<label>Google site verification</label><input type="text" name="google_site_verification" value="<?= e(setting('google_site_verification')) ?>">
<label>Jumlah postingan per halaman</label><input type="number" name="posts_per_page" min="1" max="50" value="<?= e(setting('posts_per_page', '7')) ?>">
<?php $homepagePages = db()->query('SELECT id,title FROM pages WHERE status="published" AND published_at<=NOW() ORDER BY title')->fetchAll(); ?>
<div class="row"><div><label>Homepage</label><select name="homepage_mode"><option value="posts" <?= setting('homepage_mode', 'posts') === 'posts' ? 'selected' : '' ?>>Postingan terbaru</option><option value="page" <?= setting('homepage_mode') === 'page' ? 'selected' : '' ?>>Halaman statis</option></select></div><div><label>Halaman homepage</label><select name="homepage_page_id"><option value="0">Pilih halaman</option><?php foreach ($homepagePages as $homepagePage): ?><option value="<?= (int)$homepagePage['id'] ?>" <?= (int)setting('homepage_page_id', '0') === (int)$homepagePage['id'] ? 'selected' : '' ?>><?= e($homepagePage['title']) ?></option><?php endforeach; ?></select></div></div>
<label class="checkbox"><input type="checkbox" name="allow_comments" <?= setting('allow_comments', '1') === '1' ? 'checked' : '' ?>> Izinkan komentar</label>
<label class="checkbox"><input type="checkbox" name="comment_moderation" <?= setting('comment_moderation', '1') === '1' ? 'checked' : '' ?>> Moderasi komentar sebelum ditampilkan</label>
<label>Password administrator baru (kosongkan untuk tidak mengubah)</label><input type="password" name="new_password" minlength="12" autocomplete="new-password">
<button type="submit">Simpan pengaturan</button></form></section>
<section class="card"><h2>SEO & URL</h2><p>Permalink artikel mempertahankan bentuk Blogger <code>/YYYY/MM/slug.html</code>. Sitemap: <a href="<?= e(url('sitemap.xml')) ?>" target="_blank"><?= e(url('sitemap.xml')) ?></a></p><p>RSS: <a href="<?= e(url('feed.xml')) ?>" target="_blank"><?= e(url('feed.xml')) ?></a> · Robots: <a href="<?= e(url('robots.txt')) ?>" target="_blank"><?= e(url('robots.txt')) ?></a></p></section>
<?php elseif ($view === 'media'):
    $media = db()->query('SELECT * FROM media ORDER BY created_at DESC LIMIT 200')->fetchAll();
?>
<h1>Media Library</h1><section class="card"><h2>Unggah gambar</h2><p class="help">JPG, PNG, GIF, WebP; maksimal 5 MB.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="upload_media"><input type="file" name="file" accept="image/jpeg,image/png,image/gif,image/webp" required><button type="submit">Unggah</button></form></section>
<section class="card"><h2>File tersimpan</h2><?php if (!$media): ?><p>Belum ada media.</p><?php else: ?><table><thead><tr><th>Pratinjau</th><th>Nama</th><th>URL</th><th>Ukuran</th><th>Tanggal</th></tr></thead><tbody><?php foreach ($media as $item): ?><tr><td><img src="<?= e(url($item['file_path'])) ?>" alt="" width="90"></td><td><?= e($item['file_name']) ?></td><td><input readonly value="<?= e(url($item['file_path'])) ?>" onclick="this.select()"></td><td><?= number_format((int)$item['file_size'] / 1024, 1) ?> KB</td><td><?= e($item['created_at']) ?></td><td><form method="post" onsubmit="return confirm('Hapus media ini?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_media"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="danger">Hapus</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
<?php endif; ?>
</main></div>
<?php if ($view === 'posts' || $view === 'pages'): ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    'use strict';
    function initEditor() {
        if (typeof window.tinymce === 'undefined') {
            console.warn('TinyMCE gagal dimuat — textarea tetap bisa dipakai sebagai editor HTML.');
            return;
        }
        window.tinymce.init({
            selector: 'textarea[name="body"]',
            license_key: 'gpl',
            base_url: 'https://cdn.jsdelivr.net/npm/tinymce@7',
            suffix: '.min',
            height: 540,
            min_height: 300,
            menubar: false,
            branding: false,
            promotion: false,
            resize: true,
            statusbar: true,
            elementpath: true,
            browser_spellcheck: true,
            contextmenu: 'link image table',
            plugins: [
                'anchor', 'autolink', 'charmap', 'codesample', 'emoticons',
                'image', 'link', 'lists', 'media', 'searchreplace',
                'table', 'visualblocks', 'wordcount', 'code', 'fullscreen', 'quickbars'
            ].join(' '),
            toolbar: [
                'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | ' +
                'link image media table | align lineheight | numlist bullist indent outdent | ' +
                'emoticons charmap | removeformat code fullscreen'
            ].join(''),
            quickbars_selection_toolbar: 'bold italic underline | quicklink h2 h3 blockquote',
            quickbars_insert_toolbar: 'quickimage quicktable',
            content_style: [
                'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;',
                'font-size:15px;line-height:1.7;color:#263238;padding:12px 16px}',
                'a{color:#1a5fd0}',
                'img{max-width:100%;height:auto}',
                'table{border-collapse:collapse;width:100%}',
                'td,th{border:1px solid #cbd2dc;padding:6px 8px}',
                'blockquote{border-left:3px solid #1a5fd0;margin:0;padding:4px 12px;color:#455a64;background:#f5f7fb}'
            ].join(''),
            setup: function (editor) {
                editor.on('change keyup', function () { editor.save(); });
                editor.on('init', function () { editor.save(); });
            }
        });

        document.querySelectorAll('form[data-content-form]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (window.tinymce) { window.tinymce.triggerSave(); }
                var titleEl = form.querySelector('input[name="title"]');
                var bodyEl  = form.querySelector('textarea[name="body"]');
                if (titleEl && !titleEl.value.trim()) {
                    e.preventDefault();
                    titleEl.focus();
                    alert('Judul wajib diisi.');
                    return false;
                }
                if (bodyEl && !bodyEl.value.trim()) {
                    e.preventDefault();
                    var ed = window.tinymce && window.tinymce.get(bodyEl.id || undefined);
                    if (ed) { ed.focus(); } else { bodyEl.focus(); }
                    alert('Konten wajib diisi.');
                    return false;
                }
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEditor);
    } else {
        initEditor();
    }
})();
</script>
<?php endif; ?>
</body></html>