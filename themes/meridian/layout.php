<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<?= $ui->topBanner() ?>
<div class="split-wrap shell">
  <?= $ui->sidebar('left') ?>
  <div class="split-main">
    <?= $ui->hero() ?>
    <?= $ui->content() ?>
  </div>
</div>
<?= $ui->bottomBand() ?>
<?= $ui->footer() ?>