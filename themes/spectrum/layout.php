<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('announcement') ?>
<?= $ui->header() ?>
<div class="immersive-hero">
  <?= $ui->hero() ?>
</div>
<?= $ui->content() ?>
<?= $ui->bottomBand() ?>
<?= $ui->footer() ?>