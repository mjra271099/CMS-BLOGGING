<?php
declare(strict_types=1);

/**
 * Theme gallery: activate, preview, duplicate, delete and import ZIP themes.
 *
 * @var array  $catalog
 * @var string $activeTheme
 */

use App\Core\Theme;
use App\Http\AdminUi;

$catalog = $catalog ?? [];
$activeTheme = $activeTheme ?? Theme::instance()->slug();
$adminBase = url('admin/index.php');
$groups = [];
foreach ($catalog as $theme) {
    $groups[(string)($theme['category'] ?? 'personal')][] = $theme;
}
?>
<div class="toolbar">
    <h1>Tema</h1>
    <div class="admin-actions">
        <span class="muted">Aktif: <strong><?= e(Theme::instance()->name()) ?></strong></span>
        <a class="btn secondary" href="<?= e($adminBase . '?view=customize') ?>"><?= AdminUi::icon('sliders', 16) ?> Kustomisasi</a>
    </div>
</div>

<div class="card">
    <h2>Impor Tema</h2>
    <form method="post" action="<?= e($adminBase . '?view=themes') ?>" enctype="multipart/form-data" class="row">
        <input type="hidden" name="action" value="theme.import">
        <?= \App\Core\Csrf::field() ?>
        <input type="file" name="zip" accept=".zip" required>
        <button type="submit">Pasang Tema</button>
    </form>
    <p class="help">Berkas ZIP harus berisi <code>theme.json</code>, <code>layout.php</code> dan <code>style.css</code>.</p>
</div>

<?php if (!$catalog): ?>
    <div class="card"><div class="empty-state"><p>Tidak ada tema terpasang.</p></div></div>
<?php endif; ?>

<?php foreach ($groups as $category => $themes): ?>
<div class="card">
    <h2><?= e(ucfirst($category)) ?> <span class="muted">· <?= count($themes) ?> tema</span></h2>
    <div class="theme-grid">
        <?php foreach ($themes as $theme): ?>
            <article class="theme-card<?= $theme['slug'] === $activeTheme ? ' active' : '' ?>">
                <div class="theme-shot">
                    <img src="<?= e((string)$theme['screenshot_url']) ?>" loading="lazy" alt="Tangkapan layar tema <?= e((string)$theme['name']) ?>">
                    <?php if ($theme['slug'] === $activeTheme): ?>
                        <span class="theme-badge">Aktif</span>
                    <?php endif; ?>
                </div>
                <div class="theme-info">
                    <strong><?= e((string)$theme['name']) ?></strong>
                    <span class="muted">v<?= e((string)($theme['version'] ?? '1.0.0')) ?> · <?= e((string)($theme['author'] ?? '')) ?></span>
                    <p class="muted"><?= e(AdminUi::excerpt((string)($theme['description'] ?? ''), 90)) ?></p>
                    <div class="theme-tags">
                        <?php foreach (array_slice((array)($theme['tags'] ?? []), 0, 4) as $tag): ?>
                            <span class="pill"><?= e((string)$tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="admin-actions">
                    <?php if ($theme['slug'] === $activeTheme): ?>
                        <a class="btn secondary small" href="<?= e($adminBase . '?view=customize&theme=' . rawurlencode((string)$theme['slug'])) ?>">Kustomisasi</a>
                        <a class="btn secondary small" href="<?= e(url()) ?>" target="_blank" rel="noopener">Lihat</a>
                    <?php else: ?>
                        <form method="post" action="<?= e($adminBase . '?view=themes') ?>" class="inline">
                            <input type="hidden" name="action" value="theme.activate">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="slug" value="<?= e((string)$theme['slug']) ?>">
                            <button type="submit" class="small">Aktifkan</button>
                        </form>
                        <a class="btn secondary small" href="<?= e($adminBase . '?view=customize&theme=' . rawurlencode((string)$theme['slug'])) ?>">Kustomisasi</a>
                        <a class="btn secondary small" href="<?= e(url('?theme_preview=' . rawurlencode((string)$theme['slug']))) ?>" target="_blank" rel="noopener">Pratinjau</a>
                    <?php endif; ?>
                    <?php if (($theme['source'] ?? '') !== 'bundled'): ?>
                        <form method="post" action="<?= e($adminBase . '?view=themes') ?>" class="inline">
                            <input type="hidden" name="action" value="theme.delete">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="slug" value="<?= e((string)$theme['slug']) ?>">
                            <button type="submit" class="danger small" onclick="return confirm('Hapus tema ini?')">Hapus</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
