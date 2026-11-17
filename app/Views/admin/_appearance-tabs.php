<?php
declare(strict_types=1);

/**
 * Shared sub-navigation for the "Tampilan" (appearance) screens so Tema,
 * Kustomisasi, Tata Letak and Menu feel like one place instead of four
 * disconnected pages.
 *
 * @var string $appearanceActive one of: themes, customize, layout, menus
 */

use App\Http\AdminUi;

$appearanceActive = (string)($appearanceActive ?? '');
$appearanceBase = url('admin/index.php');
$appearanceTabs = [
    'themes' => ['Tema', 'palette', 'Ganti tampilan dasar situs'],
    'customize' => ['Kustomisasi', 'sliders', 'Warna, font, dan tata letak'],
    'layout' => ['Tata Letak', 'columns', 'Posisi widget dan area'],
    'menus' => ['Menu', 'menu', 'Navigasi header dan footer'],
];
?>
<nav class="filter-tabs appearance-tabs" style="margin-bottom:16px" aria-label="Pengaturan tampilan">
    <?php foreach ($appearanceTabs as $key => [$label, $icon, $hint]): ?>
        <a class="filter-tab<?= $key === $appearanceActive ? ' active' : '' ?>"
           href="<?= e($appearanceBase . '?view=' . $key) ?>"
           title="<?= e($hint) ?>"
           <?= $key === $appearanceActive ? 'aria-current="page"' : '' ?>>
            <?= AdminUi::icon($icon, 15) ?> <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>
