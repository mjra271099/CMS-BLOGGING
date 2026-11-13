<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server. Mirrors .htaccess so local development
 * behaves like Apache: real files are served as-is, blocked folders return 403,
 * and everything else goes through the front controller.
 *
 * Usage: php -S 127.0.0.1:8090 router.php
 */

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$root = __DIR__;
$file = realpath($root . $path);

if ($file !== false && is_file($file) && str_starts_with($file, $root)) {
    $relative = ltrim(str_replace($root, '', $file), '/');
    $blocked = preg_match('~^(app|database|storage|tests|bin)/~', $relative)
        || in_array(basename($relative), ['config.php', 'config.local.php', 'config.sample.php', 'functions.php'], true)
        || (str_starts_with($relative, 'uploads/') && preg_match('/\.(php|phtml|phar|cgi|pl)$/i', $relative) === 1);
    if ($blocked) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden';
        return true;
    }
    // Serve the asset with the correct MIME type.
    $types = [
        'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
        'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'xml' => 'application/xml',
    ];
    $extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    if (isset($types[$extension])) {
        header('Content-Type: ' . $types[$extension]);
        header('Content-Length: ' . (string)filesize($file));
        readfile($file);
        return true;
    }
    return false;
}

require $root . '/index.php';
return true;
