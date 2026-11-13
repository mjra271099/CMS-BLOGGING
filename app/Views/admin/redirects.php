<?php
declare(strict_types=1);

/**
 * Redirect manager.
 *
 * @var array $redirects
 * @var int   $editId
 */

use App\Http\AdminUi;

$redirects = $redirects ?? [];
$editId = (int)($editId ?? 0);
$adminBase = url('admin/index.php');
$editing = null;
foreach ($redirects as $redirect) {
    if ((int)$redirect['id'] === $editId) {
        $editing = $redirect;
        break;
    }
}
?>
<div class="toolbar">
    <h1>Redirect</h1>
    <span class="muted"><?= count($redirects) ?> aturan aktif</span>
</div>

<div class="dashboard-cols">
    <div class="card">
        <h2>Aturan Baru</h2>
        <form method="post" action="<?= e($adminBase . '?view=redirects') ?>">
            <input type="hidden" name="action" value="redirect.save">
            <?= \App\Core\Csrf::field() ?>
            <label for="from_path">Dari path</label>
            <input id="from_path" type="text" name="from_path" placeholder="/2020/01/lama.html" value="<?= e((string)($editing['from_path'] ?? '')) ?>" required>
            <label for="to_path">Ke path / URL</label>
            <input id="to_path" type="text" name="to_path" placeholder="/2026/01/baru.html" value="<?= e((string)($editing['to_path'] ?? '')) ?>" required>
            <label for="status_code">Kode status</label>
            <select id="status_code" name="status_code">
                <option value="301"<?= (int)($editing['status_code'] ?? 301) === 301 ? ' selected' : '' ?>>301 · Permanen</option>
                <option value="302"<?= (int)($editing['status_code'] ?? 0) === 302 ? ' selected' : '' ?>>302 · Sementara</option>
                <option value="410"<?= (int)($editing['status_code'] ?? 0) === 410 ? ' selected' : '' ?>>410 · Hilang</option>
            </select>
            <button type="submit" style="margin-top:14px">Simpan Redirect</button>
        </form>
        <p class="help">Gunakan path relatif (diawali <code>/</code>) atau URL lengkap. Redirect dievaluasi sebelum router konten.</p>
    </div>

    <div class="card">
        <h2>Daftar Redirect</h2>
        <?php if (!$redirects): ?>
            <div class="empty-state"><p>Belum ada redirect. Tambahkan untuk mempertahankan peringkat URL lama.</p></div>
        <?php else: ?>
            <table>
                <thead><tr><th>Dari</th><th>Ke</th><th>Kode</th><th>Hit</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($redirects as $redirect): ?>
                    <tr>
                        <td><code><?= e((string)$redirect['from_path']) ?></code></td>
                        <td class="muted"><?= e((string)$redirect['to_path']) ?></td>
                        <td><span class="pill <?= (int)$redirect['status_code'] === 301 ? 'info' : '' ?>"><?= (int)$redirect['status_code'] ?></span></td>
                        <td><?= (int)$redirect['hits'] ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn secondary small" href="<?= e($adminBase . '?view=redirects&edit=' . (int)$redirect['id']) ?>">Edit</a>
                                <form method="post" action="<?= e($adminBase . '?view=redirects') ?>" class="inline">
                                    <input type="hidden" name="action" value="redirect.delete">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$redirect['id'] ?>">
                                    <button type="submit" class="danger small">Hapus</button>
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
