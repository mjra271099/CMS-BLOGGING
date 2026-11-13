<?php
declare(strict_types=1);

/**
 * Backup & restore of blog content as JSON.
 *
 * @var array $files
 */

use App\Http\AdminUi;

$files = $files ?? [];
$adminBase = url('admin/index.php');
?>
<div class="toolbar">
    <h1>Backup</h1>
    <form method="post" action="<?= e($adminBase . '?view=backup') ?>">
        <input type="hidden" name="action" value="backup.create">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit"><?= AdminUi::icon('download', 16) ?> Buat Backup Sekarang</button>
    </form>
</div>

<div class="card">
    <h2>Berkas Backup</h2>
    <p class="help">Backup mencakup artikel, halaman, kategori, tag, media metadata, pengaturan, widget dan menu blog aktif (format <code>cms-backup-v2</code>).</p>
    <?php if (!$files): ?>
        <div class="empty-state">
            <div class="es-icon">🗄️</div>
            <p>Belum ada berkas backup. Klik "Buat Backup Sekarang".</p>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>Berkas</th><th>Ukuran</th><th>Dibuat</th><th style="width:250px">Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($files as $file): ?>
                <tr>
                    <td><code><?= e((string)$file['name']) ?></code></td>
                    <td><?= AdminUi::bytes((int)$file['size']) ?></td>
                    <td class="muted"><?= e(AdminUi::dateTime((string)$file['date'])) ?></td>
                    <td>
                        <div class="admin-actions">
                            <form method="post" action="<?= e($adminBase . '?view=backup') ?>" class="inline">
                                <input type="hidden" name="action" value="backup.restore">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="file" value="<?= e((string)$file['name']) ?>">
                                <button type="submit" class="secondary small" onclick="return confirm('Pulihkan data dari backup ini? Data saat ini akan digabung.')">Restore</button>
                            </form>
                            <form method="post" action="<?= e($adminBase . '?view=backup') ?>" class="inline">
                                <input type="hidden" name="action" value="backup.restore">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="file" value="<?= e((string)$file['name']) ?>">
                                <input type="hidden" name="wipe" value="1">
                                <button type="submit" class="danger small" onclick="return confirm('Hapus SEMUA artikel dan halaman blog aktif lalu pulihkan dari backup ini?')">Restore Bersih</button>
                            </form>
                            <form method="post" action="<?= e($adminBase . '?view=backup') ?>" class="inline">
                                <input type="hidden" name="action" value="backup.delete">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="file" value="<?= e((string)$file['name']) ?>">
                                <button type="submit" class="danger small" onclick="return confirm('Hapus berkas backup ini?')">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Panduan Pemulihan</h2>
    <ol class="help" style="padding-left:18px">
        <li>Backup disimpan di <code>storage/backups/</code> pada server.</li>
        <li>Restore dengan mode gabung akan memperbarui data yang sama dan menambahkan yang baru.</li>
        <li>Restore bersih menghapus artikel/halaman blog aktif terlebih dahulu — gunakan hanya saat perlu.</li>
        <li>Unduh berkas secara berkala ke penyimpanan luar untuk perlindungan terhadap kegagalan server.</li>
    </ol>
</div>
