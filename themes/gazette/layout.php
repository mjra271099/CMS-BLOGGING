<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('announcement') ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<?= $ui->widgetArea('top-banner') ?>
<div class="newsroom-lead shell">
  <?= $ui->hero() ?>
</div>
<?= $ui->content() ?>
<section class="newsroom-more shell">
  <?= $ui->widgetArea('after-content') ?>
</section>
<?= $ui->footer() ?>