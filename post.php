<?php
declare(strict_types=1);

/**
 * Legacy single-post entry point. New traffic is routed by index.php; this
 * shim keeps old links working by resolving ?slug= (or ?p=) and redirecting
 * to the canonical Blogger-style URL.
 */

if (!defined('CMS_ROOT')) {
    require __DIR__ . '/app/bootstrap.php';
}

$slug = trim((string)($_GET['slug'] ?? $_GET['p'] ?? ''));
if ($slug !== '') {
    $post = \App\Models\Post::findBySlug($slug);
    if ($post) {
        \App\Core\Response::redirect(post_url($post), 301);
    }
}

\App\Core\Response::redirect(url(), 302);
