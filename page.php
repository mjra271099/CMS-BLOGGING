<?php
declare(strict_types=1);

/**
 * Legacy page entry point. Resolves ?slug= and redirects to /p/<slug>.html.
 */

if (!defined('CMS_ROOT')) {
    require __DIR__ . '/app/bootstrap.php';
}

$slug = trim((string)($_GET['slug'] ?? $_GET['p'] ?? ''));
if ($slug !== '') {
    $page = \App\Models\Page::findBySlug($slug);
    if ($page) {
        \App\Core\Response::redirect(page_url($page), 301);
    }
}

\App\Core\Response::redirect(url(), 302);
