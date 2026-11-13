<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->header() ?>
<?= $ui->hero() ?>
<?= $ui->content() ?>
<?= $ui->bottomBand() ?>
<section class="landing-cta">
  <?= $ui->widgetArea('footer-about') ?>
</section>
<?= $ui->footer() ?>