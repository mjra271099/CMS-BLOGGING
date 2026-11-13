<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<?= $ui->topBanner() ?>
<div class="dark-hero shell">
  <?= $ui->hero() ?>
</div>
<?= $ui->content() ?>
<?= $ui->bottomBand() ?>
<?= $ui->footer() ?>