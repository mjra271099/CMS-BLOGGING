<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<?= $ui->topBanner() ?>
<?= $ui->hero() ?>
<main id="main" class="site-main shell wide-shell">
  <div class="narrow-column">
    <?= $ui->breadcrumbs() ?>
    <?= $ui->contentInner() ?>
  </div>
</main>
<?= $ui->bottomBand() ?>
<?= $ui->footer() ?>