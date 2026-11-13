<?php
declare(strict_types=1);

/**
 * Activity log and application log tail.
 *
 * @var array $entries
 * @var array $logger
 */

use App\Http\AdminUi;

$entries = $entries ?? [];
$logger = $logger ?? [];
$adminBase = url('admin/index.php');

$actionLabels = [
    'auth.login' => 'Login berhasil',
    'auth.logout' => 'Logout',
    'post.create' => 'Artikel dibuat',
    'post.update' => 'Artikel diperbarui',
    'post.trash' => 'Artikel ke sampah',
    'page.save' => 'Halaman disimpan',
    'theme.activate' => 'Tema diaktifkan',
    'theme.customize' => 'Kustomisasi tema',
    'widget.save' => 'Widget disimpan',
    'settings.save' => 'Pengaturan disimpan',
    'backup.create' => 'Backup dibuat',
    'backup.restore' => 'Backup dipulihkan',
    'user.save' => 'Pengguna disimpan',
];
?>
<div class="toolbar">
    <h1>Log Aktivitas</h1>
    <span class="muted"><?= count($entries) ?> entri terbaru</span>
</div>

<div class="card">
    <h2>Aktivitas Admin</h2>
    <?php if (!$entries): ?>
        <div class="empty-state"><p>Belum ada aktivitas tercatat.</p></div>
    <?php else: ?>
        <table>
            <thead><tr><th>Waktu</th><th>Aksi</th><th>Detail</th><th>Pengguna</th></tr></thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td class="muted" style="white-space:nowrap"><?= e(AdminUi::dateTime((string)$entry['created_at'])) ?></td>
                    <td><span class="pill info"><?= e($actionLabels[(string)$entry['action']] ?? (string)$entry['action']) ?></span></td>
                    <td><?= e((string)($entry['detail'] ?? '')) ?></td>
                    <td class="muted"><?= e((string)($entry['username'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Log Aplikasi</h2>
    <?php if (!$logger): ?>
        <p class="muted">Berkas log kosong atau belum dibuat.</p>
    <?php else: ?>
        <pre class="log-tail"><?php foreach ($logger as $line): ?><?= e((string)$line) ?><?= "\n" ?><?php endforeach; ?></pre>
    <?php endif; ?>
</div>
