<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->widgetArea('header') ?>
<?= $ui->header() ?>
<?= $ui->hero() ?>
<?= $ui->content() ?>
<section class="portfolio-strip shell">
  <?= $ui->widgetArea('after-content') ?>
</section>
<?= $ui->bottomBand() ?>
<?= $ui->footer() ?>