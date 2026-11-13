<?php
declare(strict_types=1);

/**
 * Page builder: block palette, canvas (JSON edited live via JS),
 * live HTML preview rendered server-side.
 *
 * @var array|null $page
 * @var array $pages
 * @var array $document
 * @var array $blockTypes
 * @var string $previewDocument
 */

use App\Http\AdminUi;

$page = $page ?? null;
$pages = $pages ?? [];
$document = $document ?? ['version' => 1, 'sections' => []];
$blockTypes = $blockTypes ?? [];
$previewDocument = $previewDocument ?? '';
$adminBase = url('admin/index.php');
$docJson = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{"version":1,"sections":[]}';
$sections = (array)($document['sections'] ?? []);
?>
<div class="toolbar">
    <h1>Page Builder</h1>
    <div class="admin-actions">
        <form method="get" action="<?= e($adminBase) ?>" class="inline">
            <input type="hidden" name="view" value="builder">
            <select name="page" onchange="this.form.submit()" style="min-width:210px">
                <option value="0">— Halaman baru —</option>
                <?php foreach ($pages as $candidate): ?>
                    <option value="<?= (int)$candidate['id'] ?>"<?= (int)($page['id'] ?? 0) === (int)$candidate['id'] ? ' selected' : '' ?>>
                        <?= e((string)$candidate['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($page): ?>
            <a class="btn secondary" href="<?= e(page_url($page)) ?>" target="_blank" rel="noopener">Lihat Halaman</a>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="<?= e($adminBase . '?view=builder' . ($page ? '&page=' . (int)$page['id'] : '')) ?>" id="builder-form"
      data-builder-theme="<?= e(\App\Core\Theme::instance()->slug()) ?>">
    <input type="hidden" name="action" value="builder.save">
    <input type="hidden" name="page_id" value="<?= (int)($page['id'] ?? 0) ?>">
    <?= \App\Core\Csrf::field() ?>
    <textarea name="document" data-builder-document hidden><?= e($docJson) ?></textarea>

    <div class="builder-shell">
        <aside class="builder-palette">
            <h2>Blok</h2>
            <div class="block-palette">
                <?php foreach ($blockTypes as $type => $definition): ?>
                    <button type="button" class="block-chip" data-add-block="<?= e((string)$type) ?>">
                        <?= AdminUi::icon((string)($definition['icon'] ?? 'blocks'), 16) ?>
                        <?= e((string)($definition['label'] ?? $type)) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="help">Klik blok untuk menambahkannya ke bagian terakhir.</p>
        </aside>

        <div class="builder-canvas">
            <?php if (!$page): ?>
                <div class="card">
                    <label for="builder-title">Judul halaman baru</label>
                    <input id="builder-title" type="text" name="title" placeholder="Halaman Baru" required>
                </div>
            <?php endif; ?>

            <div class="builder-sections" data-builder-sections>
                <?php if (!$sections): ?>
                    <div class="empty-state">
                        <div class="es-icon"><?= AdminUi::icon('blocks', 22) ?></div>
                        <p>Kanvas kosong. Tambahkan blok dari panel sebelah kiri.</p>
                    </div>
                <?php endif; ?>
                <?php foreach ($sections as $sectionIndex => $section): ?>
                    <?php
                    $sectionSettings = (array)($section['settings'] ?? []);
                    $sectionBackground = (string)($sectionSettings['background'] ?? '');
                    $sectionPadding = (string)($sectionSettings['padding'] ?? 'md');
                    $sectionWidth = (string)($sectionSettings['width'] ?? 'normal');
                    $sectionClass = (string)($sectionSettings['class'] ?? '');
                    ?>
                    <section class="builder-section-edit" data-section-index="<?= (int)$sectionIndex ?>"
                             data-section-id="<?= e((string)($section['id'] ?? '')) ?>">
                        <div class="builder-section-head">
                            <strong>Bagian <?= (int)$sectionIndex + 1 ?></strong>
                            <button type="button" class="secondary small" data-remove-section>Hapus bagian</button>
                        </div>
                        <div class="builder-section-settings">
                            <label>Latar bagian
                                <span class="section-color">
                                    <input type="color" data-section-color="background"
                                           value="<?= e($sectionBackground !== '' ? (str_starts_with($sectionBackground, '#') ? $sectionBackground : '#' . $sectionBackground) : '#ffffff') ?>">
                                    <input type="text" data-section-setting="background"
                                           value="<?= e($sectionBackground) ?>" placeholder="(bawaan)">
                                </span>
                            </label>
                            <label>Jarak isi
                                <select data-section-setting="padding">
                                    <?php foreach (['none' => 'Rapat', 'sm' => 'Kecil', 'md' => 'Sedang', 'lg' => 'Besar'] as $value => $label): ?>
                                        <option value="<?= e($value) ?>"<?= $sectionPadding === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Lebar isi
                                <select data-section-setting="width">
                                    <?php foreach (['normal' => 'Normal', 'wide' => 'Lebar', 'full' => 'Penuh', 'narrow' => 'Sempit'] as $value => $label): ?>
                                        <option value="<?= e($value) ?>"<?= $sectionWidth === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Kelas CSS
                                <input type="text" data-section-setting="class" value="<?= e($sectionClass) ?>" placeholder="mis. sorotan">
                            </label>
                        </div>
                        <div class="builder-blocks" data-section-blocks>
                            <?php foreach ((array)($section['blocks'] ?? []) as $blockIndex => $block): ?>
                                <div class="builder-block-edit" data-block-index="<?= (int)$blockIndex ?>" data-block-type="<?= e((string)($block['type'] ?? '')) ?>"
                                     data-block-id="<?= e((string)($block['id'] ?? '')) ?>">
                                    <div class="block-head">
                                        <span class="pill info"><?= e((string)($block['type'] ?? '')) ?></span>
                                        <div class="admin-actions">
                                            <button type="button" class="secondary small" data-move-block="up" title="Naik">↑</button>
                                            <button type="button" class="secondary small" data-move-block="down" title="Turun">↓</button>
                                            <button type="button" class="secondary small" data-duplicate-block>Duplikat</button>
                                            <button type="button" class="danger small" data-remove-block>Hapus</button>
                                        </div>
                                    </div>
                                    <div class="block-fields">
                                        <?php foreach ((array)($blockTypes[(string)($block['type'] ?? '')]['fields'] ?? []) as $field): ?>
                                            <?php $settings = (array)($block['settings'] ?? []); ?>
                                            <?php $rawValue = $settings[$field] ?? ''; ?>
                                            <?php $isJsonField = is_array($rawValue) || in_array($field, ['items', 'images'], true); ?>
                                            <?php if ($isJsonField): ?>
                                                <?php $rawValue = is_array($rawValue) ? json_encode($rawValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$rawValue; ?>
                                            <?php endif; ?>
                                            <label><?= e(ucfirst((string)$field)) ?></label>
                                            <?php if ($isJsonField): ?>
                                                <textarea data-block-setting="<?= e((string)$field) ?>" data-block-setting-json="1" rows="4" spellcheck="false" placeholder='[{"title":"…"}]'><?= e((string)$rawValue) ?></textarea>
                                            <?php elseif (in_array($field, ['text', 'subtitle', 'content', 'html', 'caption'], true)): ?>
                                                <textarea data-block-setting="<?= e((string)$field) ?>" rows="3"><?= e((string)$rawValue) ?></textarea>
                                            <?php elseif (in_array($field, ['overlay', 'height', 'columns', 'level', 'gap', 'limit'], true)): ?>
                                                <input type="number" data-block-setting="<?= e((string)$field) ?>" value="<?= e((string)($settings[$field] ?? '')) ?>">
                                            <?php else: ?>
                                                <input type="text" data-block-setting="<?= e((string)$field) ?>" value="<?= e((string)($settings[$field] ?? '')) ?>">
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="secondary small" data-add-section-block>+ Blok</button>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="builder-actions">
                <button type="submit">Simpan Dokumen</button>
                <button type="button" class="secondary" data-builder-add-section>+ Bagian Baru</button>
                <span class="muted" data-builder-state></span>
            </div>
        </div>

        <aside class="builder-preview">
            <div class="builder-preview-head">
                <h2>Pratinjau</h2>
                <div class="admin-actions">
                    <button type="button" class="secondary small" data-preview-width="390" title="Tampilan ponsel">Mobile</button>
                    <button type="button" class="secondary small" data-preview-width="820" title="Tampilan tablet">Tablet</button>
                    <button type="button" class="secondary small" data-preview-width="100%" title="Tampilan desktop">Desktop</button>
                </div>
            </div>
            <div class="builder-preview-stage">
                <iframe class="builder-preview-frame" data-builder-preview title="Pratinjau halaman"
                        srcdoc="<?= e($previewDocument) ?>"></iframe>
            </div>
            <div class="builder-preview-foot">
                <button type="button" class="secondary small" data-builder-refresh>Segarkan pratinjau</button>
                <button type="button" class="secondary small" data-preview-expand>Perbesar</button>
                <span class="muted" data-builder-preview-state aria-live="polite"></span>
            </div>
            <p class="help">Pratinjau memakai tema aktif (<strong><?= e(\App\Core\Theme::instance()->name()) ?></strong>). Perubahan bidang langsung tercermin di sini.</p>
        </aside>
    </div>
</form>

<div class="preview-modal" data-preview-modal hidden>
    <div class="preview-modal-inner">
        <div class="preview-modal-head">
            <h2>Pratinjau halaman</h2>
            <div class="admin-actions">
                <button type="button" class="secondary small" data-modal-width="390">Mobile</button>
                <button type="button" class="secondary small" data-modal-width="820">Tablet</button>
                <button type="button" class="secondary small" data-modal-width="100%">Desktop</button>
                <button type="button" class="danger small" data-preview-close>Tutup</button>
            </div>
        </div>
        <div class="preview-modal-stage">
            <iframe class="preview-modal-frame" data-preview-modal-frame title="Pratinjau halaman besar"></iframe>
        </div>
        <p class="help">Tekan Esc untuk menutup. Perubahan pada formulir tetap tercermin selama pratinjau terbuka.</p>
    </div>
</div>
