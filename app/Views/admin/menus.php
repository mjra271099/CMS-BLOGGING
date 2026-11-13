<?php
declare(strict_types=1);

/**
 * Menu builder: switch menus, edit items, add new links.
 *
 * @var array $menus
 * @var int   $selected
 * @var array $items
 * @var array $pages
 * @var array $categories
 */

use App\Http\AdminUi;

$menus = $menus ?? [];
$selected = (int)($selected ?? 0);
$items = $items ?? [];
$pages = $pages ?? [];
$categories = $categories ?? [];
$adminBase = url('admin/index.php');
$currentMenu = null;
foreach ($menus as $menu) {
    if ((int)$menu['id'] === $selected) {
        $currentMenu = $menu;
        break;
    }
}
?>
<div class="toolbar">
    <h1>Menu</h1>
    <div class="admin-actions">
        <?php foreach ($menus as $menu): ?>
            <a class="btn <?= (int)$menu['id'] === $selected ? '' : 'secondary' ?> small"
               href="<?= e($adminBase . '?view=menus&menu=' . (int)$menu['id']) ?>">
                <?= e((string)$menu['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="dashboard-cols">
    <div class="card">
        <h2><?= $currentMenu ? 'Item Menu: ' . e((string)$currentMenu['name']) : 'Item Menu' ?></h2>
        <?php if (!$items): ?>
            <div class="empty-state"><p>Menu ini belum memiliki item.</p></div>
        <?php else: ?>
            <ul class="menu-item-list">
                <?php foreach ($items as $item): ?>
                    <li>
                        <span class="menu-order"><?= (int)($item['sort_order'] ?? 0) ?></span>
                        <div style="flex:1;min-width:0">
                            <strong><?= e((string)$item['label']) ?></strong>
                            <span class="muted"><?= e((string)$item['url']) ?></span>
                            <?php if (!empty($item['parent_id'])): ?>
                                <span class="pill info">sub</span>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?= e($adminBase . '?view=menus&menu=' . $selected) ?>" class="inline">
                            <input type="hidden" name="action" value="menu.item.delete">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <button type="submit" class="danger small">Hapus</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3 style="margin-top:24px">Tambah Item</h3>
        <form method="post" action="<?= e($adminBase . '?view=menus&menu=' . $selected) ?>">
            <input type="hidden" name="action" value="menu.item.save">
            <input type="hidden" name="menu_id" value="<?= $selected ?>">
            <?= \App\Core\Csrf::field() ?>
            <div class="row">
                <div>
                    <label for="label">Label</label>
                    <input id="label" type="text" name="label" required>
                </div>
                <div>
                    <label for="url">URL</label>
                    <input id="url" type="text" name="url" placeholder="<?= e(base_url()) ?>" required>
                </div>
            </div>
            <div class="row">
                <div>
                    <label for="parent_id">Induk</label>
                    <select id="parent_id" name="parent_id">
                        <option value="0">— Tidak ada —</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?= (int)$item['id'] ?>"><?= e((string)$item['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="sort_order">Urutan</label>
                    <input id="sort_order" type="number" name="sort_order" value="<?= count($items) ?>">
                </div>
                <div>
                    <label for="target">Target</label>
                    <select id="target" name="target">
                        <option value="_self">Tab sama</option>
                        <option value="_blank">Tab baru</option>
                    </select>
                </div>
            </div>
            <button type="submit" style="margin-top:14px">Tambah Item</button>
        </form>
    </div>

    <div class="card">
        <h2>Menu Baru</h2>
        <form method="post" action="<?= e($adminBase . '?view=menus') ?>">
            <input type="hidden" name="action" value="menu.create">
            <?= \App\Core\Csrf::field() ?>
            <label for="name">Nama menu</label>
            <input id="name" type="text" name="name" required placeholder="Menu Utama">
            <label for="location">Lokasi</label>
            <select id="location" name="location">
                <option value="primary">Navigasi utama</option>
                <option value="footer">Footer</option>
                <option value="mobile">Mobile</option>
            </select>
            <button type="submit" style="margin-top:14px">Buat Menu</button>
        </form>

        <?php if ($currentMenu): ?>
        <h3 style="margin-top:26px">Hapus Menu</h3>
        <form method="post" action="<?= e($adminBase . '?view=menus') ?>">
            <input type="hidden" name="action" value="menu.delete">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $selected ?>">
            <button type="submit" class="danger" onclick="return confirm('Hapus menu beserta itemnya?')">Hapus Menu Ini</button>
        </form>
        <?php endif; ?>

        <h3 style="margin-top:26px">Tautan Cepat</h3>
        <p class="help">Klik untuk menyalin URL ke kolom URL.</p>
        <div class="pill-row">
            <?php foreach ($pages as $page): ?>
                <button type="button" class="chip" data-fill-url="<?= e(page_url($page)) ?>" data-fill-label="<?= e((string)$page['title']) ?>">
                    <?= e((string)$page['title']) ?>
                </button>
            <?php endforeach; ?>
            <?php foreach ($categories as $category): ?>
                <button type="button" class="chip" data-fill-url="<?= e(category_url($category)) ?>" data-fill-label="<?= e((string)$category['name']) ?>">
                    <?= e((string)$category['name']) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>
