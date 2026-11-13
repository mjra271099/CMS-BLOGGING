<?php
declare(strict_types=1);

/**
 * Post list with filters, bulk actions and pagination.
 *
 * @var array  $posts
 * @var array  $counts
 * @var string $status
 * @var string $search
 * @var int    $category
 * @var array  $categories
 * @var int    $paged
 * @var int    $totalPages
 */

use App\Http\AdminUi;

$posts = $posts ?? [];
$counts = $counts ?? [];
$status = $status ?? 'all';
$search = $search ?? '';
$category = (int)($category ?? 0);
$categories = $categories ?? [];
$paged = (int)($paged ?? 1);
$totalPages = (int)($totalPages ?? 1);
$adminBase = url('admin/index.php');
$statusTabs = [
    'all' => ['Semua', (int)(($counts['published'] ?? 0) + ($counts['draft'] ?? 0))],
    'published' => ['Terbit', (int)($counts['published'] ?? 0)],
    'draft' => ['Draf', (int)($counts['draft'] ?? 0)],
    'scheduled' => ['Terjadwal', (int)($counts['scheduled'] ?? 0)],
    'trash' => ['Sampah', (int)($counts['trash'] ?? 0)],
];
?>
<div class="toolbar">
    <h1>Postingan</h1>
    <a class="btn" href="<?= e($adminBase . '?view=post-edit') ?>"><?= AdminUi::icon('plus', 16) ?> Postingan Baru</a>
</div>

<div class="card">
    <div class="filter-tabs">
        <?php foreach ($statusTabs as $key => [$label, $count]): ?>
            <a href="<?= e($adminBase . '?view=posts&status=' . $key . ($search !== '' ? '&q=' . rawurlencode($search) : '')) ?>"
               class="filter-tab<?= $status === $key ? ' active' : '' ?>">
                <?= e($label) ?> <span class="count"><?= $count ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= e($adminBase) ?>" class="toolbar" style="margin:16px 0 0">
        <input type="hidden" name="view" value="posts">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <div class="row" style="flex:1">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari judul atau isi artikel…">
            <select name="category" style="max-width:220px">
                <option value="0">Semua kategori</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"<?= $category === (int)$cat['id'] ? ' selected' : '' ?>>
                        <?= e((string)$cat['name']) ?> (<?= (int)($cat['post_count'] ?? 0) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="secondary"><?= AdminUi::icon('search', 16) ?> Filter</button>
    </form>
</div>

<form method="post" action="<?= e($adminBase . '?view=posts') ?>" class="card" id="post-bulk">
    <input type="hidden" name="action" value="post.bulk">
    <?= \App\Core\Csrf::field() ?>

    <?php if (!$posts): ?>
        <div class="empty-state">
            <div class="es-icon"><?= AdminUi::icon('file', 22) ?></div>
            <p>Belum ada postingan pada filter ini. <a href="<?= e($adminBase . '?view=post-edit') ?>">Tulis artikel baru</a>.</p>
        </div>
    <?php else: ?>
        <div class="bulk-bar">
            <label class="checkbox" style="margin:0"><input type="checkbox" id="select-all"> Pilih semua</label>
            <select name="operation" style="max-width:200px">
                <option value="publish">Terbitkan</option>
                <option value="draft">Jadikan draf</option>
                <option value="trash">Pindahkan ke sampah</option>
                <option value="restore">Pulihkan</option>
                <option value="delete">Hapus permanen</option>
            </select>
            <button type="submit" class="secondary" onclick="return confirm('Terapkan aksi ke postingan terpilih?')">Terapkan</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:34px"></th>
                    <th>Judul</th>
                    <th>Kategori</th>
                    <th>Status</th>
                    <th>Penulis</th>
                    <th>Tanggal</th>
                    <th>Dilihat</th>
                    <th style="width:170px">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= (int)$post['id'] ?>" class="bulk-check"></td>
                    <td>
                        <a href="<?= e($adminBase . '?view=post-edit&id=' . (int)$post['id']) ?>" style="font-weight:650">
                            <?= e((string)$post['title']) ?>
                        </a>
                        <?php if (!empty($post['featured'])): ?><span class="pill info" title="Unggulan"><?= AdminUi::icon('star') ?></span><?php endif; ?>
                        <?php if (!empty($post['sticky'])): ?><span class="pill info" title="Disematkan"><?= AdminUi::icon('pin') ?></span><?php endif; ?>
                        <div class="muted">/<?= e(AdminUi::excerpt(trim(strip_tags((string)($post['excerpt'] ?? ''))), 70)) ?></div>
                    </td>
                    <td class="muted">—</td>
                    <td><?= AdminUi::pill((string)$post['status']) ?></td>
                    <td class="muted"><?= e((string)($post['author_name'] ?? $post['username'] ?? '')) ?></td>
                    <td class="muted"><?= e(AdminUi::dateShort((string)($post['published_at'] ?? $post['created_at'] ?? ''))) ?></td>
                    <td><?= number_format((int)$post['views'], 0, ',', '.') ?></td>
                    <td>
                        <div class="admin-actions">
                            <a class="btn secondary small" href="<?= e($adminBase . '?view=post-edit&id=' . (int)$post['id']) ?>">Edit</a>
                            <a class="btn secondary small" href="<?= e(post_url($post)) ?>" target="_blank" rel="noopener">Lihat</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</form>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="<?= e($adminBase . '?view=posts&status=' . $status . '&paged=' . $i . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($category ? '&category=' . $category : '')) ?>"
           class="page-link<?= $paged === $i ? ' active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php if ($posts): ?>
<div class="card">
    <h2>Aksi per Postingan</h2>
    <div class="admin-actions" style="gap:10px">
    <?php foreach (array_slice($posts, 0, 3) as $post): ?>
        <div class="inline-actions">
            <span class="muted"><?= e(AdminUi::excerpt((string)$post['title'], 32)) ?></span>
            <form method="post" action="<?= e($adminBase . '?view=posts') ?>" class="inline">
                <input type="hidden" name="action" value="post.duplicate">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                <button type="submit" class="secondary small">Duplikat</button>
            </form>
            <form method="post" action="<?= e($adminBase . '?view=posts') ?>" class="inline">
                <input type="hidden" name="action" value="post.trash">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                <button type="submit" class="danger small" onclick="return confirm('Pindahkan ke sampah?')">Sampah</button>
            </form>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
