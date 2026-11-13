<?php
declare(strict_types=1);

/**
 * Legacy sidebar partial. Delegates to the theme UI kit so widget visibility,
 * ordering and customizer settings are respected.
 */

if (!defined('CMS_ROOT')) {
    require __DIR__ . '/app/bootstrap.php';
}

$ui = new \App\Theme\Ui(\App\Core\Theme::instance(), is_array($page ?? null) ? $page : []);
$widgets = $ui->widgetArea('sidebar');
if (trim($widgets) !== '') {
    echo '<div class="sidebar-wrapper top"><div class="shell">' . $widgets . '</div></div>';
}
