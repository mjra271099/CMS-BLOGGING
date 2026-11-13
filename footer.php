<?php
declare(strict_types=1);

/**
 * Legacy footer partial. Delegates to the active theme's UI kit, which also
 * closes </body></html> and emits footer widgets/scripts.
 */

if (!defined('CMS_ROOT')) {
    require __DIR__ . '/app/bootstrap.php';
}

$ui = new \App\Theme\Ui(\App\Core\Theme::instance(), is_array($page ?? null) ? $page : []);
echo $ui->footer();
