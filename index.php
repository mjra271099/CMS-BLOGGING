<?php
declare(strict_types=1);

/**
 * Front controller entry point.
 *
 * Kept as the front file for backward compatibility: every legacy URL still
 * resolves here, but routing, queries and rendering now live in app/.
 */

require __DIR__ . '/app/bootstrap.php';

$controller = new App\Http\FrontController();
$output = $controller->handle();

if ($output !== '') {
    $hasContentType = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0) {
            $hasContentType = true;
            break;
        }
    }
    if (!headers_sent() && !$hasContentType) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $output;
}
