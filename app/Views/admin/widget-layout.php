<?php
declare(strict_types=1);

/**
 * Layout & widgets: per-area widget lists with reorder, toggle, edit forms.
 *
 * @var array $sections
 * @var array $types
 * @var array $themeAreas
 * @var int   $editId
 */

use App\Core\Widget;
use App\Http\AdminUi;

$sections = $sections ?? [];
$types = $types ?? Widget::types();
$themeAreas = $themeAreas ?? [];
$editId = (int)($editId ?? 0);
$previewTargets = $previewTargets ?? ['home' => ['label' => 'Beranda', 'url' => url()]];
$firstTarget = reset($previewTargets) ?: ['label' => 'Beranda', 'url' => url()];
$adminBase = url('admin/index.php');
$editingWidget = $editId > 0 ? Widget::find($editId) : null;
$editingType = $editingWidget ? (string)$editingWidget['type'] : 'Text';
$editingConfig = $editingWidget ? Widget::config($editingWidget) : [];
$editingFields = (array)($types[$editingType]['fields'] ?? []);
?>
<div class="toolbar">
    <h1>Tata Letak</h1>
    <span class="muted"><?= count($sections) ?> area widget · tema <?= e(\App\Core\Theme::instance()->name()) ?></span>
</div>

<div class="notice">
    Seret kartu widget untuk mengubah urutan. Perubahan urutan disimpan otomatis.
</div>

<details class="layout-preview" open>
    <summary>
        <span class="layout-preview-title">Pratinjau situs</span>
        <span class="muted">Lihat hasil tata letak &amp; widget pada tema aktif</span>
    </summary>
    <div class="layout-preview-body">
        <div class="preview-bar">
            <label class="preview-target">
                <span>Halaman</span>
                <select data-preview-target>
                    <?php foreach ($previewTargets as $key => $target): ?>
                        <option value="<?= e((string)$target['url']) ?>"<?= $key === array_key_first($previewTargets) ? ' selected' : '' ?>>
                            <?= e((string)$target['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="admin-actions">
                <button type="button" class="secondary small" data-preview-width="390">Mobile</button>
                <button type="button" class="secondary small" data-preview-width="820">Tablet</button>
                <button type="button" class="secondary small" data-preview-width="100%">Desktop</button>
                <button type="button" class="secondary small" data-preview-refresh>Segarkan</button>
                <a class="btn secondary small" href="<?= e((string)$firstTarget['url']) ?>" data-preview-open target="_blank" rel="noopener">Buka tab baru</a>
            </div>
        </div>
        <div class="layout-preview-stage">
            <iframe src="<?= e((string)$firstTarget['url']) ?>" data-preview-frame title="Pratinjau tema"></iframe>
        </div>
        <p class="help">Pratinjau memuat halaman asli memakai tema aktif. Setelah menyimpan widget, segarkan pratinjau untuk melihat hasilnya.</p>
    </div>
</details>

<div class="layout-shell">
    <div class="layout-areas" id="widget-areas">
        <?php foreach ($sections as $sectionId => $section): ?>
            <section class="card area-card" data-area="<?= e((string)$sectionId) ?>">
                <div class="area-head">
                    <div>
                        <h2 style="margin:0"><?= e((string)$section['label']) ?></h2>
                        <span class="muted"><?= e((string)$sectionId) ?> · <?= count($section['widgets']) ?> widget</span>
                    </div>
                    <?php if (in_array($sectionId, (array)$themeAreas, true) || $themeAreas === []): ?>
                        <a class="btn secondary small" href="<?= e($adminBase . '?view=layout&edit=0&section=' . rawurlencode((string)$sectionId)) ?>">
                            <?= AdminUi::icon('plus', 14) ?> Tambah
                        </a>
                    <?php endif; ?>
                </div>

                <ul class="widget-sortable" data-section="<?= e((string)$sectionId) ?>">
                    <?php if (!$section['widgets']): ?>
                        <li class="widget-empty">Belum ada widget di area ini.</li>
                    <?php endif; ?>
                    <?php foreach ($section['widgets'] as $widget): ?>
                        <li class="widget-item" draggable="true" data-widget-id="<?= (int)$widget['id'] ?>">
                            <span class="drag-handle" title="Seret untuk memindahkan">⋮⋮</span>
                            <div class="widget-main">
                                <strong><?= e((string)($widget['title'] ?: $types[(string)$widget['type']]['label'] ?? $widget['type'])) ?></strong>
                                <span class="muted"><?= e((string)$widget['type']) ?><?= empty($widget['enabled']) ? ' · nonaktif' : '' ?></span>
                            </div>
                            <div class="widget-tools">
                                <form method="post" action="<?= e($adminBase . '?view=layout') ?>" class="inline">
                                    <input type="hidden" name="action" value="widget.toggle">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$widget['id'] ?>">
                                    <button type="submit" class="secondary small"><?= empty($widget['enabled']) ? 'Aktifkan' : 'Matikan' ?></button>
                                </form>
                                <a class="btn secondary small" href="<?= e($adminBase . '?view=layout&edit=' . (int)$widget['id']) ?>">Edit</a>
                                <form method="post" action="<?= e($adminBase . '?view=layout') ?>" class="inline">
                                    <input type="hidden" name="action" value="widget.duplicate">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$widget['id'] ?>">
                                    <button type="submit" class="secondary small">Duplikat</button>
                                </form>
                                <form method="post" action="<?= e($adminBase . '?view=layout') ?>" class="inline">
                                    <input type="hidden" name="action" value="widget.delete">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$widget['id'] ?>">
                                    <button type="submit" class="danger small" onclick="return confirm('Hapus widget ini?')">Hapus</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>

    <aside class="layout-editor">
        <div class="card">
            <h2><?= $editingWidget ? 'Edit Widget' : 'Widget Baru' ?></h2>
            <form method="post" action="<?= e($adminBase . '?view=layout' . ($editingWidget ? '&edit=' . (int)$editingWidget['id'] : '')) ?>" id="widget-form">
                <input type="hidden" name="action" value="widget.save">
                <input type="hidden" name="id" value="<?= (int)($editingWidget['id'] ?? 0) ?>">
                <?= \App\Core\Csrf::field() ?>

                <label for="type">Jenis widget</label>
                <select id="type" name="type" data-widget-type>
                    <?php foreach ($types as $typeKey => $definition): ?>
                        <option value="<?= e((string)$typeKey) ?>"<?= $editingType === $typeKey ? ' selected' : '' ?>>
                            <?= e((string)($definition['label'] ?? $typeKey)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="title">Judul</label>
                <input id="title" type="text" name="title" value="<?= e((string)($editingWidget['title'] ?? '')) ?>">

                <label for="section_id">Area</label>
                <select id="section_id" name="section_id">
                    <?php foreach ($sections as $sectionId => $section): ?>
                        <option value="<?= e((string)$sectionId) ?>"
                            <?= (string)($editingWidget['section_id'] ?? ($_GET['section'] ?? 'sidebar')) === (string)$sectionId ? ' selected' : '' ?>>
                            <?= e((string)$section['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="content">Konten (HTML/teks)</label>
                <textarea id="content" name="content" rows="5"><?= e((string)($editingWidget['content'] ?? '')) ?></textarea>

                <div id="dynamic-fields" class="dynamic-fields">
                    <?php foreach ($editingFields as $fieldKey => $field): ?>
                        <?php
                        $fieldType = (string)($field['type'] ?? 'text');
                        $fieldValue = $editingConfig[$fieldKey] ?? ($field['default'] ?? '');
                        $fieldId = 'wf-' . preg_replace('/[^a-z0-9_-]/i', '-', (string)$fieldKey);
                        ?>
                        <?php if ($fieldType === 'checkbox'): ?>
                            <label class="checkbox"><input type="checkbox" name="config[<?= e((string)$fieldKey) ?>]" value="1"
                                <?= !empty($fieldValue) ? ' checked' : '' ?>> <?= e((string)($field['label'] ?? $fieldKey)) ?></label>
                        <?php elseif ($fieldType === 'select'): ?>
                            <label for="<?= e($fieldId) ?>"><?= e((string)($field['label'] ?? $fieldKey)) ?></label>
                            <select id="<?= e($fieldId) ?>" name="config[<?= e((string)$fieldKey) ?>]">
                                <?php foreach ((array)($field['options'] ?? []) as $optionKey => $optionLabel): ?>
                                    <option value="<?= e((string)$optionKey) ?>"<?= (string)$fieldValue === (string)$optionKey ? ' selected' : '' ?>>
                                        <?= e((string)$optionLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($fieldType === 'number'): ?>
                            <label for="<?= e($fieldId) ?>"><?= e((string)($field['label'] ?? $fieldKey)) ?></label>
                            <input id="<?= e($fieldId) ?>" type="number" name="config[<?= e((string)$fieldKey) ?>]" value="<?= e((string)$fieldValue) ?>"
                                   min="<?= (int)($field['min'] ?? 0) ?>" max="<?= (int)($field['max'] ?? 999) ?>">
                        <?php else: ?>
                            <label for="<?= e($fieldId) ?>"><?= e((string)($field['label'] ?? $fieldKey)) ?></label>
                            <input id="<?= e($fieldId) ?>" type="text" name="config[<?= e((string)$fieldKey) ?>]" value="<?= e((string)$fieldValue) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="config_json" value="<?= e(json_encode($editingConfig ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>" data-config-json>

                <label for="visibility">Visibilitas</label>
                <select id="visibility" name="visibility">
                    <?php foreach (['all' => 'Semua halaman', 'home' => 'Beranda saja', 'post' => 'Artikel saja', 'page' => 'Halaman saja', 'archive' => 'Arsip/kategori', '404' => 'Halaman 404', 'none' => 'Disembunyikan'] as $value => $label): ?>
                        <option value="<?= e((string)$value) ?>"<?= (string)($editingWidget['visibility'] ?? 'all') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="devices">Perangkat</label>
                <select id="devices" name="devices">
                    <?php foreach (['all' => 'Semua perangkat', 'desktop' => 'Desktop', 'mobile' => 'Mobile'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string)($editingWidget['devices'] ?? 'all') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="pages">Batasi ke halaman tertentu</label>
                <input id="pages" type="text" name="pages" value="<?= e((string)($editingWidget['pages'] ?? '')) ?>" placeholder="slug1, slug2">

                <label class="checkbox">
                    <input type="checkbox" name="enabled" value="1"<?= !empty($editingWidget['enabled']) || !$editingWidget ? ' checked' : '' ?>> Widget aktif
                </label>

                <div class="admin-actions" style="margin-top:16px">
                    <button type="submit"><?= $editingWidget ? 'Simpan Widget' : 'Tambah Widget' ?></button>
                    <?php if ($editingWidget): ?>
                        <a class="btn secondary" href="<?= e($adminBase . '?view=layout') ?>">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Area Tema Ini</h2>
            <div class="pill-row">
                <?php foreach ((array)$themeAreas as $area): ?>
                    <span class="pill info"><?= e((string)$area) ?></span>
                <?php endforeach; ?>
            </div>
            <p class="help">Area ini dirender oleh layout tema aktif. Widget di area lain tetap tersimpan.</p>
        </div>
    </aside>
</div>
