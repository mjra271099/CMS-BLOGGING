<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<main id="main" class="site-main shell">
  <div class="editorial-grid">
    <div class="editorial-main">
      <?= $ui->breadcrumbs() ?>
      <?= $ui->hero() ?>
      <?= $ui->contentInner() ?>
    </div>
    <?= $ui->sidebar('right') ?>
  </div>
</main>
<?= $ui->footer() ?>