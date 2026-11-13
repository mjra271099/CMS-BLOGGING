<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<?= $ui->topBanner() ?>
<div class="magazine-cover shell">
  <?= $ui->hero() ?>
</div>
<?= $ui->content() ?>
<section class="magazine-extras shell">
  <?= $ui->widgetArea('after-content') ?>
</section>
<?= $ui->bottomBand() ?>
<?= $ui->footer() ?>