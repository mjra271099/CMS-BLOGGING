<?php
declare(strict_types=1);

/**
 * Legacy category entry point. Resolves ?slug= and redirects to the current
 * category URL (which is preserved as /category/<slug>).
 */

if (!defined('CMS_ROOT')) {
    require __DIR__ . '/app/bootstrap.php';
}

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug !== '') {
    foreach (\App\Models\Taxonomy::categories() as $category) {
        if ((string)$category['slug'] === $slug) {
            \App\Core\Response::redirect(category_url($category), 301);
        }
    }
}

\App\Core\Response::redirect(url(), 302);
