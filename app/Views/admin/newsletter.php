<?php
declare(strict_types=1);

/**
 * Newsletter subscriber list.
 *
 * @var array $subscribers
 */

use App\Http\AdminUi;

$subscribers = $subscribers ?? [];
$adminBase = url('admin/index.php');
$confirmed = 0;
foreach ($subscribers as $subscriber) {
    if (($subscriber['status'] ?? '') === 'confirmed') {
        $confirmed++;
    }
}
?>
<div class="toolbar">
    <h1>Newsletter</h1>
    <div class="admin-actions">
        <span class="muted"><?= $confirmed ?> pelanggan terkonfirmasi</span>
        <a class="btn secondary" href="<?= e(url('feed/')) ?>" target="_blank" rel="noopener"><?= AdminUi::icon('mail', 16) ?> Lihat formulir berlangganan</a>
    </div>
</div>

<div class="card">
    <h2>Daftar Pelanggan</h2>
    <?php if (!$subscribers): ?>
        <div class="empty-state">
            <div class="es-icon">📬</div>
            <p>Belum ada pelanggan. Formulir berlangganan tersedia di widget Newsletter.</p>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>Email</th><th>Nama</th><th>Status</th><th>Terdaftar</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($subscribers as $subscriber): ?>
                <tr>
                    <td><strong><?= e((string)$subscriber['email']) ?></strong></td>
                    <td class="muted"><?= e((string)($subscriber['name'] ?: '—')) ?></td>
                    <td><?= AdminUi::pill((string)$subscriber['status']) ?></td>
                    <td class="muted"><?= e(AdminUi::dateShort((string)$subscriber['created_at'])) ?></td>
                    <td>
                        <form method="post" action="<?= e($adminBase . '?view=newsletter') ?>" class="inline">
                            <input type="hidden" name="action" value="newsletter.delete">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$subscriber['id'] ?>">
                            <button type="submit" class="danger small" onclick="return confirm('Hapus pelanggan ini?')">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Integrasi</h2>
    <p class="help">
        Data pelanggan tersimpan di tabel <code>newsletter_subscribers</code> per blog. Ekspor melalui menu
        Backup (JSON) atau hubungkan ke layanan email eksternal dengan memakai tabel ini sebagai sumber.
        Endpoint berlangganan: <code><?= e(url('newsletter')) ?></code>.
    </p>
</div>
