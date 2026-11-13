<?php
/**
 * AMP Theme — layout.
 *
 * Composition is intentionally thin: every block below is produced by the
 * shared UI kit, so the theme keeps working when widgets, menus or the
 * sidebar are empty. Order:
 *
 *   announcement + <head>  →  header widgets  →  header
 *   →  top banner  →  featured (hero)  →  main content
 *   →  call-to-action band  →  footer
 *
 * @var array            $page
 * @var App\Theme\Ui     $ui
 * @var App\Core\Theme   $theme
 */

// The front controller injects the featured area into the homepage content
// itself; rendering it again here would duplicate those widgets. Only add the
// hero when the content does not already contain it (post, page, archives).
$content = (string)($page['content'] ?? '');
if (!str_contains($content, 'class="hero-area')) {
    $hero = $ui->hero();
} else {
    $hero = '';
}
?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<?= $ui->topBanner() ?>
<?= $hero ?>
<?= $ui->content() ?>
<?= $ui->bottomBand() ?>
<?= $ui->footer() ?>
