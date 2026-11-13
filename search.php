<?php
declare(strict_types=1);

/**
 * Legacy search entry point. Redirects to /search?q=… so the new controller
 * renders results with the active theme.
 */

if (!defined('CMS_ROOT')) {
    require __DIR__ . '/app/bootstrap.php';
}

$term = trim((string)($_GET['q'] ?? $_GET['term'] ?? $_GET['s'] ?? ''));
$target = url('search');
if ($term !== '') {
    $target .= '?q=' . rawurlencode($term);
}

\App\Core\Response::redirect($target, 302);
