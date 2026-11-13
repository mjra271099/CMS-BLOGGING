<?php /** @var array $page @var App\Theme\Ui $ui */ ?>
<?= $ui->head() ?>
<?= $ui->header() ?>
<div class="docs-layout shell">
  <aside class="docs-nav">
    <?= $ui->widgetArea('menu') ?>
    <?= $ui->widgetArea('sidebar-left') ?>
  </aside>
  <main id="main" class="docs-content">
    <?= $ui->breadcrumbs() ?>
    <?= $ui->contentInner() ?>
  </main>
  <aside class="docs-aside">
    <?= $ui->widgetArea('sidebar') ?>
  </aside>
</div>
<?= $ui->footer() ?>