<?php
declare(strict_types=1);

/**
 * Tag (label) manager.
 *
 * @var array $tags
 */

use App\Http\AdminUi;

$tags = $tags ?? [];
$adminBase = url('admin/index.php');
?>
<div class="toolbar">
    <h1>Tag</h1>
    <span class="muted"><?= count($tags) ?> tag</span>
</div>

<div class="card">
    <?php if (!$tags): ?>
        <div class="empty-state">
            <div class="es-icon"><?= AdminUi::icon('tag', 22) ?></div>
            <p>Belum ada tag. Tag dibuat otomatis saat Anda menuliskan label pada postingan.</p>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>Nama</th><th>Slug</th><th>Artikel</th><th style="width:110px"></th></tr></thead>
            <tbody>
            <?php foreach ($tags as $tag): ?>
                <tr>
                    <td><strong><?= e((string)$tag['name']) ?></strong></td>
                    <td class="muted"><?= e((string)$tag['slug']) ?></td>
                    <td><?= (int)($tag['post_count'] ?? 0) ?></td>
                    <td>
                        <form method="post" action="<?= e($adminBase . '?view=tags') ?>" class="inline">
                            <input type="hidden" name="action" value="tag.delete">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
                            <button type="submit" class="danger small" onclick="return confirm('Hapus tag ini?')">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
