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
 * @var string $rendered
 */

use App\Http\AdminUi;

$page = $page ?? null;
$pages = $pages ?? [];
$document = $document ?? ['version' => 1, 'sections' => []];
$blockTypes = $blockTypes ?? [];
$rendered = $rendered ?? '';
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

<form method="post" action="<?= e($adminBase . '?view=builder' . ($page ? '&page=' . (int)$page['id'] : '')) ?>" id="builder-form">
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
                    <section class="builder-section-edit" data-section-index="<?= (int)$sectionIndex ?>">
                        <div class="builder-section-head">
                            <strong>Bagian <?= (int)$sectionIndex + 1 ?></strong>
                            <button type="button" class="secondary small" data-remove-section>Hapus bagian</button>
                        </div>
                        <div class="builder-blocks" data-section-blocks>
                            <?php foreach ((array)($section['blocks'] ?? []) as $blockIndex => $block): ?>
                                <div class="builder-block-edit" data-block-index="<?= (int)$blockIndex ?>" data-block-type="<?= e((string)($block['type'] ?? '')) ?>">
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
            <h2>Pratinjau</h2>
            <div class="builder-preview-frame" data-builder-preview>
                <?= $rendered !== '' ? $rendered : '<p class="muted">Pratinjau akan tampil setelah blok ditambahkan.</p>' ?>
            </div>
            <button type="button" class="secondary small" data-builder-refresh>Segarkan pratinjau</button>
        </aside>
    </div>
</form>
