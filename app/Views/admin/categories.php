<?php
declare(strict_types=1);

/**
 * Category manager: create/edit form plus the full list with counts.
 *
 * @var array $categories
 * @var int   $editId
 */

use App\Http\AdminUi;

$categories = $categories ?? [];
$editId = (int)($editId ?? 0);
$adminBase = url('admin/index.php');
$editing = null;
foreach ($categories as $category) {
    if ((int)$category['id'] === $editId) {
        $editing = $category;
        break;
    }
}
?>
<div class="toolbar">
    <h1>Kategori</h1>
    <span class="muted"><?= count($categories) ?> kategori</span>
</div>

<div class="dashboard-cols">
    <div class="card">
        <h2><?= $editing ? 'Edit Kategori' : 'Kategori Baru' ?></h2>
        <form method="post" action="<?= e($adminBase . '?view=categories' . ($editing ? '&edit=' . (int)$editing['id'] : '')) ?>">
            <input type="hidden" name="action" value="category.save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
            <?= \App\Core\Csrf::field() ?>
            <label for="name">Nama</label>
            <input id="name" type="text" name="name" value="<?= e((string)($editing['name'] ?? '')) ?>" required>
            <label for="slug">Slug</label>
            <input id="slug" type="text" name="slug" value="<?= e((string)($editing['slug'] ?? '')) ?>" placeholder="otomatis">
            <label for="description">Deskripsi</label>
            <textarea id="description" name="description" rows="3"><?= e((string)($editing['description'] ?? '')) ?></textarea>
            <div class="row">
                <div>
                    <label for="color">Warna</label>
                    <input id="color" type="text" name="color" value="<?= e((string)($editing['color'] ?? '')) ?>" placeholder="#2563eb">
                </div>
                <div>
                    <label for="sort_order">Urutan</label>
                    <input id="sort_order" type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                </div>
            </div>
            <label for="parent_id">Induk kategori</label>
            <select id="parent_id" name="parent_id">
                <option value="0">— Tidak ada —</option>
                <?php foreach ($categories as $category): ?>
                    <?php if ($editing && (int)$category['id'] === (int)$editing['id']) { continue; } ?>
                    <option value="<?= (int)$category['id'] ?>"<?= (int)($editing['parent_id'] ?? 0) === (int)$category['id'] ? ' selected' : '' ?>>
                        <?= e((string)$category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="image">Gambar kategori</label>
            <input id="image" type="text" name="image" value="<?= e((string)($editing['image'] ?? '')) ?>" placeholder="URL gambar">
            <label for="meta_title">Meta title</label>
            <input id="meta_title" type="text" name="meta_title" value="<?= e((string)($editing['meta_title'] ?? '')) ?>">
            <label for="meta_description">Meta description</label>
            <textarea id="meta_description" name="meta_description" rows="2"><?= e((string)($editing['meta_description'] ?? '')) ?></textarea>
            <div class="admin-actions" style="margin-top:16px">
                <button type="submit"><?= $editing ? 'Simpan Perubahan' : 'Tambah Kategori' ?></button>
                <?php if ($editing): ?>
                    <a class="btn secondary" href="<?= e($adminBase . '?view=categories') ?>">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Daftar Kategori</h2>
        <?php if (!$categories): ?>
            <div class="empty-state"><p>Belum ada kategori.</p></div>
        <?php else: ?>
            <table>
                <thead><tr><th>Nama</th><th>Slug</th><th>Artikel</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td>
                            <?php if (!empty($category['color'])): ?>
                                <span class="color-dot" style="background:<?= e((string)$category['color']) ?>"></span>
                            <?php endif; ?>
                            <strong><?= e((string)$category['name']) ?></strong>
                            <?php if (!empty($category['description'])): ?>
                                <div class="muted"><?= e(AdminUi::excerpt((string)$category['description'], 60)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= e((string)$category['slug']) ?></td>
                        <td><?= (int)($category['post_count'] ?? 0) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn secondary small" href="<?= e($adminBase . '?view=categories&edit=' . (int)$category['id']) ?>">Edit</a>
                                <form method="post" action="<?= e($adminBase . '?view=categories') ?>" class="inline">
                                    <input type="hidden" name="action" value="category.delete">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">
                                    <button type="submit" class="danger small" onclick="return confirm('Hapus kategori ini? Artikel tetap tersimpan.')">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
