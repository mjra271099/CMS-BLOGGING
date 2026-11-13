<?php
declare(strict_types=1);

/**
 * Legacy header partial.
 *
 * Old themes/templates used to include this file. It now delegates to the
 * active theme's UI kit so markup, SEO tags and customizer CSS match the
 * modern front controller. Variables still supported:
 * $pageTitle, $pageDescription, $canonical, $socialImage, $preview, $pageMeta.
 */

if (!defined('CMS_ROOT')) {
    require __DIR__ . '/app/bootstrap.php';
}

$theme = \App\Core\Theme::instance();
$meta = is_array($pageMeta ?? null) ? $pageMeta : [];
$meta += [
    'title' => (string)($pageTitle ?? \App\Core\Settings::get('site_name', 'Blog')),
    'description' => (string)($pageDescription ?? \App\Core\Settings::get('site_description', '')),
    'canonical' => (string)($canonical ?? url()),
    'image' => (string)($socialImage ?? \App\Core\Settings::get('social_image', '')),
];
if (!empty($preview)) {
    $meta['robots'] = 'noindex,nofollow';
}

$ui = new \App\Theme\Ui($theme, [
    'meta' => $meta,
    'route' => (string)($route ?? ''),
    'context' => is_array($context ?? null) ? $context : [],
    'body_class' => (string)($bodyClass ?? ''),
]);

echo $ui->head();
echo $ui->header();
