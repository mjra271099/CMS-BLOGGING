<?php
declare(strict_types=1);

/**
 * Static page list with status, template and quick actions.
 *
 * @var array $pages
 */

use App\Http\AdminUi;

$pages = $pages ?? [];
$adminBase = url('admin/index.php');
?>
<div class="toolbar">
    <h1>Halaman</h1>
    <div class="admin-actions">
        <a class="btn" href="<?= e($adminBase . '?view=page-edit') ?>"><?= AdminUi::icon('plus', 16) ?> Halaman Baru</a>
        <a class="btn secondary" href="<?= e($adminBase . '?view=builder') ?>"><?= AdminUi::icon('blocks', 16) ?> Page Builder</a>
    </div>
</div>

<div class="card">
    <?php if (!$pages): ?>
        <div class="empty-state">
            <div class="es-icon"><?= AdminUi::icon('file', 22) ?></div>
            <p>Belum ada halaman statis. <a href="<?= e($adminBase . '?view=page-edit') ?>">Buat halaman pertama</a>.</p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Judul</th><th>Slug</th><th>Template</th><th>Status</th><th>Diperbarui</th><th style="width:180px">Aksi</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pages as $page): ?>
                <tr>
                    <td>
                        <a href="<?= e($adminBase . '?view=page-edit&id=' . (int)$page['id']) ?>" style="font-weight:650"><?= e((string)$page['title']) ?></a>
                        <?php if (!empty($page['parent_id'])): ?><span class="muted"> · sub-halaman</span><?php endif; ?>
                    </td>
                    <td class="muted"><?= e((string)$page['slug']) ?></td>
                    <td><span class="pill info"><?= e((string)$page['template']) ?></span></td>
                    <td><?= AdminUi::pill((string)$page['status']) ?></td>
                    <td class="muted"><?= e(AdminUi::dateShort((string)$page['updated_at'])) ?></td>
                    <td>
                        <div class="admin-actions">
                            <a class="btn secondary small" href="<?= e($adminBase . '?view=page-edit&id=' . (int)$page['id']) ?>">Edit</a>
                            <a class="btn secondary small" href="<?= e($adminBase . '?view=builder&page=' . (int)$page['id']) ?>">Builder</a>
                            <a class="btn secondary small" href="<?= e(page_url($page)) ?>" target="_blank" rel="noopener">Lihat</a>
                            <form method="post" action="<?= e($adminBase . '?view=pages') ?>" class="inline">
                                <input type="hidden" name="action" value="page.duplicate">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
                                <button type="submit" class="secondary small">Salin</button>
                            </form>
                            <form method="post" action="<?= e($adminBase . '?view=pages') ?>" class="inline">
                                <input type="hidden" name="action" value="page.delete">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
                                <button type="submit" class="danger small" onclick="return confirm('Hapus halaman ini secara permanen?')">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
