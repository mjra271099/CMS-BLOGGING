<?php
declare(strict_types=1);

/**
 * Dashboard: KPIs, quick actions, publishing trend, activity feed,
 * recent comments and the SEO health score.
 *
 * @var array $stats
 * @var array $activity
 * @var array $latest
 * @var array $comments
 * @var array $topPosts
 * @var array $seo
 * @var array $user
 */

use App\Core\Settings;
use App\Http\AdminUi;

$stats = $stats ?? [];
$posts = $stats['posts'] ?? [];
$commentsTotal = $stats['comments'] ?? [];
$blog = $stats['blog'] ?? [];
$media = $stats['media'] ?? [];
$traffic = $stats['traffic'] ?? [];
$activity = $activity ?? [];
$latest = $latest ?? [];
$comments = $comments ?? [];
$topPosts = $topPosts ?? [];
$seo = $seo ?? [];
$userName = (string)($user['display_name'] ?? $user['username'] ?? '');
$published = (int)($posts['published'] ?? 0);
$drafts = (int)($posts['draft'] ?? 0);
$scheduled = (int)($posts['scheduled'] ?? 0);
$pendingComments = (int)($commentsTotal['pending'] ?? 0);
$seoScore = (int)($seo['score'] ?? 0);
$seoIssues = count((array)($seo['issues'] ?? []));
$publishedToday = 0;
foreach ($traffic as $row) {
    if (($row['day'] ?? '') === gmdate('Y-m-d')) {
        $publishedToday = (int)$row['published'];
    }
}
?>
<section class="dashboard-welcome">
    <div>
        <h2>Selamat datang kembali, <?= e($userName) ?> 👋</h2>
        <p><?= e(Settings::get('site_name', 'Blog Anda')) ?> — <?= $published ?> artikel terbit, <?= $drafts ?> draf menunggu, <?= $pendingComments ?> komentar perlu ditinjau.</p>
    </div>
    <div class="quick-actions" style="flex:0 1 auto">
        <a class="btn" href="<?= e(url('admin/index.php?view=post-edit')) ?>"><?= AdminUi::icon('plus', 16) ?> Tulis Artikel</a>
        <a class="btn" href="<?= e(url('admin/index.php?view=media')) ?>" style="background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.3)"><?= AdminUi::icon('upload', 16) ?> Unggah Media</a>
    </div>
</section>

<div class="grid">
    <a class="stat-card" href="<?= e(url('admin/index.php?view=posts')) ?>">
        <span class="stat-icon blue"><?= AdminUi::icon('file', 22) ?></span>
        <span class="stat-info">
            <span class="stat-value"><?= $published ?></span>
            <span class="stat-label">Artikel terbit</span>
        </span>
    </a>
    <a class="stat-card" href="<?= e(url('admin/index.php?view=comments')) ?>">
        <span class="stat-icon orange"><?= AdminUi::icon('message', 22) ?></span>
        <span class="stat-info">
            <span class="stat-value"><?= $pendingComments ?></span>
            <span class="stat-label">Komentar menunggu</span>
        </span>
    </a>
    <a class="stat-card" href="<?= e(url('admin/index.php?view=pages')) ?>">
        <span class="stat-icon purple"><?= AdminUi::icon('layers', 22) ?></span>
        <span class="stat-info">
            <span class="stat-value"><?= (int)($blog['pages'] ?? 0) ?></span>
            <span class="stat-label">Halaman statis</span>
        </span>
    </a>
    <a class="stat-card" href="<?= e(url('admin/index.php?view=media')) ?>">
        <span class="stat-icon green"><?= AdminUi::icon('image', 22) ?></span>
        <span class="stat-info">
            <span class="stat-value"><?= (int)($media['files'] ?? 0) ?></span>
            <span class="stat-label">Berkas media</span>
        </span>
    </a>
</div>

<div class="dashboard-cols">
    <div class="card">
        <h2>Tren Publikasi 14 Hari</h2>
        <?php if ($traffic): ?>
            <?= AdminUi::sparkline($traffic) ?>
            <p class="help">Tinggi batang = jumlah artikel yang terbit per hari. Total hari ini: <strong><?= $publishedToday ?></strong>.</p>
        <?php else: ?>
            <div class="empty-state"><p>Belum ada data publikasi.</p></div>
        <?php endif; ?>

        <h3 style="margin-top:26px">Artikel Terpopuler</h3>
        <?php if ($topPosts): ?>
            <table>
                <thead><tr><th>Judul</th><th>Dilihat</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($topPosts as $post): ?>
                    <tr>
                        <td><?= e(AdminUi::excerpt((string)$post['title'], 52)) ?></td>
                        <td><strong><?= number_format((int)$post['views'], 0, ',', '.') ?></strong></td>
                        <td><a href="<?= e(post_url($post)) ?>" target="_blank" rel="noopener">Lihat</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">Belum ada kunjungan tercatat.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Kesehatan SEO</h2>
        <div class="seo-score <?= $seoScore >= 80 ? 'good' : ($seoScore >= 60 ? 'warn' : 'bad') ?>">
            <span class="seo-number"><?= $seoScore ?></span>
            <span class="seo-caption">dari 100</span>
        </div>
        <p class="help"><?= $seoIssues ?> temuan pada <?= (int)($seo['checked'] ?? 0) ?> artikel terbit.</p>
        <?php if (!empty($seo['counts'])): ?>
        <div class="pill-row">
            <span class="pill danger"><?= (int)$seo['counts']['error'] ?> error</span>
            <span class="pill warning"><?= (int)$seo['counts']['warning'] ?> peringatan</span>
            <span class="pill info"><?= (int)$seo['counts']['info'] ?> info</span>
        </div>
        <?php endif; ?>
        <a class="btn secondary" style="margin-top:16px" href="<?= e(url('admin/index.php?view=seo')) ?>">Buka audit SEO</a>

        <h3 style="margin-top:26px">Aktivitas Terbaru</h3>
        <?php if ($activity): ?>
            <ul class="activity-list">
            <?php foreach ($activity as $entry): ?>
                <li>
                    <span class="act-dot"></span>
                    <span class="act-body">
                        <span class="act-action"><?= e(str_replace(['.', '_'], ' ', (string)$entry['action'])) ?></span>
                        <span class="act-detail"><?= e((string)($entry['detail'] ?? '')) ?><?= !empty($entry['username']) ? ' · ' . e((string)$entry['username']) : '' ?></span>
                    </span>
                    <span class="act-time"><?= e(AdminUi::dateShort((string)$entry['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Belum ada aktivitas.</p>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-cols" style="margin-top:20px">
    <div class="card">
        <div class="toolbar" style="margin-bottom:6px">
            <h2 style="margin:0">Artikel Terakhir</h2>
            <a class="btn secondary small" href="<?= e(url('admin/index.php?view=posts')) ?>">Semua</a>
        </div>
        <?php if ($latest): ?>
            <table>
                <thead><tr><th>Judul</th><th>Status</th><th>Tanggal</th></tr></thead>
                <tbody>
                <?php foreach ($latest as $post): ?>
                    <tr>
                        <td><a href="<?= e(url('admin/index.php?view=post-edit&id=' . (int)$post['id'])) ?>"><?= e(AdminUi::excerpt((string)$post['title'], 48)) ?></a></td>
                        <td><?= AdminUi::pill((string)$post['status']) ?></td>
                        <td class="muted"><?= e(AdminUi::dateShort((string)($post['published_at'] ?? $post['created_at'] ?? ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <div class="es-icon">✍️</div>
                <p>Belum ada artikel. <a href="<?= e(url('admin/index.php?view=post-edit')) ?>">Tulis yang pertama</a>.</p>
            </div>
        <?php endif; ?>
        <?php if ($scheduled > 0): ?>
            <p class="help"><?= $scheduled ?> artikel terjadwal akan terbit otomatis.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="toolbar" style="margin-bottom:6px">
            <h2 style="margin:0">Komentar Terbaru</h2>
            <a class="btn secondary small" href="<?= e(url('admin/index.php?view=comments')) ?>">Moderasi</a>
        </div>
        <?php if ($comments): ?>
            <ul class="activity-list">
            <?php foreach ($comments as $comment): ?>
                <li>
                    <span class="act-dot" style="background:<?= $comment['status'] === 'pending' ? '#d97706' : '#16a34a' ?>"></span>
                    <span class="act-body">
                        <span class="act-action"><?= e((string)$comment['author']) ?> · <?= AdminUi::pill((string)$comment['status']) ?></span>
                        <span class="act-detail"><?= e(AdminUi::excerpt((string)$comment['body'], 70)) ?></span>
                    </span>
                    <span class="act-time"><?= e(AdminUi::dateShort((string)$comment['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Belum ada komentar masuk.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <h2>Aksi Cepat</h2>
    <div class="quick-actions">
        <a class="quick-action" href="<?= e(url('admin/index.php?view=post-edit')) ?>"><span class="qa-icon"><?= AdminUi::icon('plus') ?></span> Artikel baru</a>
        <a class="quick-action" href="<?= e(url('admin/index.php?view=page-edit')) ?>"><span class="qa-icon"><?= AdminUi::icon('layers') ?></span> Halaman baru</a>
        <a class="quick-action" href="<?= e(url('admin/index.php?view=themes')) ?>"><span class="qa-icon"><?= AdminUi::icon('palette') ?></span> Ganti tema</a>
        <a class="quick-action" href="<?= e(url('admin/index.php?view=customize')) ?>"><span class="qa-icon"><?= AdminUi::icon('sliders') ?></span> Kustomisasi tampilan</a>
        <a class="quick-action" href="<?= e(url('admin/index.php?view=layout')) ?>"><span class="qa-icon"><?= AdminUi::icon('columns') ?></span> Atur widget</a>
        <a class="quick-action" href="<?= e(url('admin/index.php?view=builder')) ?>"><span class="qa-icon"><?= AdminUi::icon('blocks') ?></span> Page builder</a>
        <a class="quick-action" href="<?= e(url('admin/index.php?view=seo')) ?>"><span class="qa-icon"><?= AdminUi::icon('search') ?></span> Audit SEO</a>
        <a class="quick-action" href="<?= e(url('admin/index.php?view=backup')) ?>"><span class="qa-icon"><?= AdminUi::icon('database') ?></span> Buat backup</a>
    </div>
</div>
