<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<main id="main" class="site-main shell">
  <div class="journal-layout">
    <div class="journal-stream">
      <?= $ui->breadcrumbs() ?>
      <?= $ui->contentInner() ?>
    </div>
    <?= $ui->sidebar('right') ?>
  </div>
</main>
<?= $ui->bottomBand() ?>
<?= $ui->footer() ?>