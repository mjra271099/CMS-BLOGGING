<?php
declare(strict_types=1);

/**
 * Media library: drag-and-drop upload, grid browser, inline metadata editor.
 *
 * @var array $items
 * @var array $stats
 * @var array $folders
 */

use App\Http\AdminUi;

$items = $items ?? [];
$stats = $stats ?? [];
$folders = $folders ?? [];
$adminBase = url('admin/index.php');
$type = (string)($_GET['type'] ?? 'all');
$search = (string)($_GET['q'] ?? '');
?>
<div class="toolbar">
    <h1>Media</h1>
    <span class="muted"><?= (int)($stats['files'] ?? 0) ?> berkas · <?= AdminUi::bytes((int)($stats['bytes'] ?? 0)) ?></span>
</div>

<div class="media-cols">
    <div class="card">
        <h2>Unggah Berkas</h2>
        <form method="post" action="<?= e($adminBase . '?view=media') ?>" enctype="multipart/form-data" class="upload-zone" data-dropzone>
            <input type="hidden" name="action" value="media.upload">
            <?= \App\Core\Csrf::field() ?>
            <div class="dropzone-inner">
                <?= AdminUi::icon('upload', 30) ?>
                <p><strong>Letakkan berkas di sini</strong> atau klik untuk memilih.</p>
                <span class="muted">Gambar, video, audio, PDF, DOC, ZIP · maks <?= (int)\App\Core\Settings::int('media_max_mb', 5) ?> MB</span>
                <input type="file" name="files[]" multiple data-file-input>
            </div>
            <div class="dropzone-filelist" data-dropzone-list></div>
            <button type="submit" style="margin-top:14px">Unggah Semua</button>
        </form>

        <?php if (!empty($stats['types'])): ?>
            <h3 style="margin-top:22px">Distribusi Jenis</h3>
            <ul class="type-distribution">
                <?php foreach ($stats['types'] as $row): ?>
                    <li>
                        <span><?= e((string)$row['mime_type']) ?></span>
                        <strong><?= (int)$row['total'] ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <form method="get" action="<?= e($adminBase) ?>" class="toolbar" style="margin-bottom:16px">
            <input type="hidden" name="view" value="media">
            <div class="row" style="flex:1">
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama berkas…">
                <select name="type" style="max-width:200px">
                    <?php foreach (['all' => 'Semua jenis', 'image' => 'Gambar', 'video' => 'Video', 'audio' => 'Audio', 'application' => 'Dokumen'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $type === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="secondary"><?= AdminUi::icon('search', 16) ?> Cari</button>
        </form>

        <?php if (!$items): ?>
            <div class="empty-state">
                <div class="es-icon">🖼️</div>
                <p>Belum ada media. Unggah berkas pertama Anda.</p>
            </div>
        <?php else: ?>
            <div class="media-grid">
                <?php foreach ($items as $item): ?>
                    <figure class="media-card" data-media-id="<?= (int)$item['id'] ?>">
                        <div class="media-thumb">
                            <?php $mime = (string)$item['mime_type']; ?>
                            <?php if (str_starts_with($mime, 'image/')): ?>
                                <img src="<?= e(media_url((string)($item['thumb_path'] ?: $item['file_path']))) ?>" loading="lazy" alt="<?= e((string)($item['alt'] ?? $item['file_name'])) ?>">
                            <?php else: ?>
                                <span class="media-filetype"><?= e(strtoupper(explode('/', $mime)[1] ?? 'FILE')) ?></span>
                            <?php endif; ?>
                        </div>
                        <figcaption>
                            <strong title="<?= e((string)$item['file_name']) ?>"><?= e(AdminUi::excerpt((string)$item['file_name'], 28)) ?></strong>
                            <span class="muted"><?= AdminUi::bytes((int)$item['file_size']) ?><?= !empty($item['width']) ? ' · ' . (int)$item['width'] . '×' . (int)$item['height'] : '' ?></span>
                            <div class="media-actions">
                                <a class="btn secondary small" href="<?= e(\App\Models\Media::publicUrl($item, false)) ?>" target="_blank" rel="noopener">Buka</a>
                                <button type="button" class="secondary small" data-media-edit="<?= (int)$item['id'] ?>">Detail</button>
                            </div>
                            <form method="post" action="<?= e($adminBase . '?view=media') ?>" class="media-edit-form" data-media-form="<?= (int)$item['id'] ?>" hidden>
                                <input type="hidden" name="action" value="media.update">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <label>Alt text</label>
                                <input type="text" name="alt" value="<?= e((string)($item['alt'] ?? '')) ?>">
                                <label>Keterangan</label>
                                <textarea name="caption" rows="2"><?= e((string)($item['caption'] ?? '')) ?></textarea>
                                <label>Folder</label>
                                <input type="text" name="folder" value="<?= e((string)($item['folder'] ?? '')) ?>" list="media-folders">
                                <div class="admin-actions">
                                    <button type="submit" class="small">Simpan</button>
                                </div>
                            </form>
                            <form method="post" action="<?= e($adminBase . '?view=media') ?>" class="inline">
                                <input type="hidden" name="action" value="media.delete">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button type="submit" class="danger small" onclick="return confirm('Hapus berkas ini?')">Hapus</button>
                            </form>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <datalist id="media-folders">
            <?php foreach ($folders as $folder): ?>
                <option value="<?= e((string)$folder) ?>">
            <?php endforeach; ?>
        </datalist>
    </div>
</div>
