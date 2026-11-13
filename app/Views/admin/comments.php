<?php
declare(strict_types=1);

/**
 * Comment moderation queue with status tabs and inline actions.
 *
 * @var array  $items
 * @var array  $counts
 * @var string $status
 */

use App\Http\AdminUi;

$items = $items ?? [];
$counts = $counts ?? [];
$status = $status ?? 'pending';
$adminBase = url('admin/index.php');
$search = (string)($_GET['q'] ?? '');
$tabs = [
    'pending' => ['Menunggu', (int)($counts['pending'] ?? 0)],
    'approved' => ['Disetujui', (int)($counts['approved'] ?? 0)],
    'spam' => ['Spam', (int)($counts['spam'] ?? 0)],
    'trash' => ['Sampah', (int)($counts['trash'] ?? 0)],
    'all' => ['Semua', array_sum(array_map('intval', $counts))],
];
?>
<div class="toolbar">
    <h1>Komentar</h1>
    <span class="muted"><?= (int)($counts['pending'] ?? 0) ?> menunggu moderasi</span>
</div>

<div class="card">
    <div class="filter-tabs">
        <?php foreach ($tabs as $key => [$label, $count]): ?>
            <a href="<?= e($adminBase . '?view=comments&status=' . $key) ?>" class="filter-tab<?= $status === $key ? ' active' : '' ?>">
                <?= e($label) ?> <span class="count"><?= $count ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= e($adminBase) ?>" class="toolbar" style="margin-top:16px">
        <input type="hidden" name="view" value="comments">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama, email atau isi komentar…" style="flex:1">
        <button type="submit" class="secondary"><?= AdminUi::icon('search', 16) ?> Cari</button>
    </form>
</div>

<?php if (!$items): ?>
<div class="card">
    <div class="empty-state">
        <div class="es-icon"><?= AdminUi::icon('message', 22) ?></div>
        <p>Tidak ada komentar pada filter ini.</p>
    </div>
</div>
<?php else: ?>
<div class="comment-list">
    <?php foreach ($items as $comment): ?>
        <article class="card comment-card">
            <div class="comment-head">
                <div class="avatar"><?= e(AdminUi::initials((string)$comment['author'])) ?></div>
                <div style="flex:1;min-width:0">
                    <strong><?= e((string)$comment['author']) ?></strong>
                    <span class="muted"><?= e((string)$comment['email']) ?></span>
                    <?php if (!empty($comment['url'])): ?>
                        <a href="<?= e((string)$comment['url']) ?>" target="_blank" rel="noopener nofollow">situs</a>
                    <?php endif; ?>
                    <div class="muted">
                        pada <a href="<?= e($adminBase . '?view=post-edit&id=' . (int)$comment['post_id']) ?>"><?= e((string)($comment['post_title'] ?? 'Postingan')) ?></a>
                        · <?= e(AdminUi::dateTime((string)$comment['created_at'])) ?>
                    </div>
                </div>
                <div><?= AdminUi::pill((string)$comment['status']) ?></div>
            </div>
            <p class="comment-body"><?= nl2br(e((string)$comment['body'])) ?></p>
            <div class="admin-actions">
                <?php foreach (['approved' => 'Setujui', 'pending' => 'Tunda', 'spam' => 'Spam', 'trash' => 'Sampah'] as $value => $label): ?>
                    <?php if ($value !== (string)$comment['status']): ?>
                        <form method="post" action="<?= e($adminBase . '?view=comments&status=' . $status) ?>" class="inline">
                            <input type="hidden" name="action" value="comment.moderate">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$comment['id'] ?>">
                            <input type="hidden" name="status" value="<?= e($value) ?>">
                            <button type="submit" class="<?= $value === 'approved' ? '' : 'secondary' ?> small"><?= e($label) ?></button>
                        </form>
                    <?php endif; ?>
                <?php endforeach; ?>
                <form method="post" action="<?= e($adminBase . '?view=comments&status=' . $status) ?>" class="inline">
                    <input type="hidden" name="action" value="comment.delete">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int)$comment['id'] ?>">
                    <button type="submit" class="danger small" onclick="return confirm('Hapus komentar secara permanen?')">Hapus</button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
