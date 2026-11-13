<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<?= $ui->topBanner() ?>
<main id="main" class="site-main shell">
  <?= $ui->widgetArea('before-content') ?>
  <?= $ui->contentInner() ?>
</main>
<?= $ui->footer() ?>