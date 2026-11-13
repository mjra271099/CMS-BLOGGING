<?php
declare(strict_types=1);

/**
 * CMS bootstrap — single entry point for every request.
 *
 * Loads configuration, registers the autoloader, configures error handling,
 * starts the session and prepares the shared service container.
 */

if (!defined('CMS_ROOT')) {
    define('CMS_ROOT', dirname(__DIR__));
}
if (!defined('CMS_START')) {
    define('CMS_START', microtime(true));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = CMS_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/Core/helpers.php';

$configFile = getenv('CMS_CONFIG') ?: CMS_ROOT . '/config.php';
if ($configFile !== '' && !preg_match('~^(/|[A-Za-z]:\\\\)~', $configFile)) {
    // Relative overrides (CMS_CONFIG=config.local.php) must resolve against the
    // project root: PHP's built-in server changes the working directory when it
    // serves a script directly instead of through router.php.
    $configFile = CMS_ROOT . '/' . ltrim($configFile, './');
}
if (!is_file($configFile)) {
    throw new RuntimeException('CMS belum diinstal. Buka /install.php terlebih dahulu.');
}

App\Core\Config::load(require $configFile);
App\Core\Logger::boot();

ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $exception): void {
    App\Core\Logger::exception($exception);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'CMS error: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if (App\Core\Config::get('debug', false)) {
        echo '<pre>' . htmlspecialchars((string)$exception, ENT_QUOTES, 'UTF-8') . '</pre>';
        return;
    }
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Kesalahan server</title>'
        . '<body style="font:16px system-ui;padding:40px"><h1>Kesalahan server</h1>'
        . '<p>Permintaan tidak dapat diproses. Periksa konfigurasi database dan log PHP hosting.</p></body></html>';
});

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    if (PHP_VERSION_ID >= 80300) {
        ini_set('session.cookie_samesite', 'Lax');
    }
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_name('cms_session');
    session_start();
}

App\Core\Container::boot();
