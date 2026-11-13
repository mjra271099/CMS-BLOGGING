<?php
declare(strict_types=1);

/**
 * Page editor with template picker, parent hierarchy and builder shortcut.
 *
 * @var array|null $page
 * @var array  $templates
 * @var array  $parentPages
 * @var array  $builderDoc
 */

use App\Http\AdminUi;

$page = $page ?? null;
$templates = $templates ?? [];
$parentPages = $parentPages ?? [];
$builderDoc = $builderDoc ?? ['version' => 1, 'sections' => []];
$adminBase = url('admin/index.php');
$isEdit = $page !== null;
$hasBuilder = !empty($builderDoc['sections']);
?>
<div class="toolbar">
    <h1><?= $isEdit ? 'Edit Halaman' : 'Halaman Baru' ?></h1>
    <div class="admin-actions">
        <a class="btn secondary" href="<?= e($adminBase . '?view=pages') ?>"><?= AdminUi::icon('chevron-left', 16) ?> Semua halaman</a>
        <?php if ($isEdit): ?>
            <a class="btn secondary" href="<?= e($adminBase . '?view=builder&page=' . (int)$page['id']) ?>"><?= AdminUi::icon('blocks', 16) ?> Buka di Builder</a>
            <a class="btn secondary" href="<?= e(page_url($page)) ?>" target="_blank" rel="noopener"><?= AdminUi::icon('eye', 16) ?> Lihat</a>
        <?php endif; ?>
        <button type="submit" form="page-form" class="btn"><?= AdminUi::icon('save', 16) ?> <?= $isEdit ? 'Simpan' : 'Buat Halaman' ?></button>
    </div>
</div>

<form method="post" id="page-form" action="<?= e($adminBase . '?view=page-edit' . ($isEdit ? '&id=' . (int)$page['id'] : '')) ?>" data-editor>
    <input type="hidden" name="action" value="page.save">
    <input type="hidden" name="id" value="<?= (int)($page['id'] ?? 0) ?>">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="content_json" value="<?= e(json_encode($builderDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') ?>">

    <div class="editor-grid">
        <div class="editor-main">
            <div class="card editor-card">
                <input id="title" type="text" name="title" value="<?= e((string)($page['title'] ?? '')) ?>" required
                       class="title-input" placeholder="Judul halaman…" aria-label="Judul halaman">

                <div class="slug-row">
                    <label for="slug" style="margin:0"><?= AdminUi::icon('link', 14) ?> Slug</label>
                    <input id="slug" type="text" name="slug" value="<?= e((string)($page['slug'] ?? '')) ?>" placeholder="tentang-kami">
                </div>
                <p class="help">Tautan: <?= e(base_url()) ?>p/<span data-slug-echo><?= e((string)($page['slug'] ?? 'slug')) ?></span>.html</p>

                <?php if ($hasBuilder): ?>
                    <div class="notice" style="margin-top:14px">
                        Halaman ini memiliki konten Page Builder. Konten builder dirender sebelum isi teks di bawah.
                    </div>
                <?php endif; ?>

                <?= AdminUi::editorToolbar() ?>

                <div class="rt-editor-wrap">
                    <div data-editor-mount hidden
                         data-placeholder="Tulis isi halaman…"
                         data-aria-label="Isi halaman"></div>
                </div>
                <textarea name="body" rows="14" class="body-editor" aria-label="HTML halaman"><?= e((string)($page['body'] ?? '')) ?></textarea>
                <div class="editor-status">
                    <span class="editor-count" data-editor-count></span>
                </div>

                <label for="excerpt" style="margin-top:18px">Ringkasan</label>
                <textarea id="excerpt" name="excerpt" rows="2"><?= e((string)($page['excerpt'] ?? '')) ?></textarea>
            </div>

            <div class="card">
                <h2>SEO</h2>
                <label for="meta_title">Meta title</label>
                <input id="meta_title" type="text" name="meta_title" value="<?= e((string)($page['meta_title'] ?? '')) ?>">
                <label for="meta_description">Meta description</label>
                <textarea id="meta_description" name="meta_description" rows="3"><?= e((string)($page['meta_description'] ?? '')) ?></textarea>
                <div class="row">
                    <div>
                        <label for="canonical_url">Canonical URL</label>
                        <input id="canonical_url" type="url" name="canonical_url" value="<?= e((string)($page['canonical_url'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="robots">Robots</label>
                        <select id="robots" name="robots">
                            <?php foreach (['' => 'Default', 'index,follow' => 'index,follow', 'noindex,follow' => 'noindex,follow', 'noindex,nofollow' => 'noindex,nofollow'] as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= (string)($page['robots'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label for="og_image">Gambar Open Graph</label>
                <input id="og_image" type="url" name="og_image" value="<?= e((string)($page['og_image'] ?? '')) ?>">
            </div>

            <div class="card">
                <h2>CSS &amp; Skrip Kustom</h2>
                <label for="custom_css">CSS kustom halaman</label>
                <textarea id="custom_css" name="custom_css" rows="5" placeholder=".hero { … }"><?= e((string)($page['custom_css'] ?? '')) ?></textarea>
                <label for="custom_script">Skrip kustom</label>
                <textarea id="custom_script" name="custom_script" rows="5" placeholder="Hanya untuk administrator."><?= e((string)($page['custom_script'] ?? '')) ?></textarea>
            </div>
        </div>

        <div class="editor-side">
            <div class="card">
                <h2>Publikasi</h2>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (['draft' => 'Draf', 'published' => 'Terbit', 'trash' => 'Sampah'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string)($page['status'] ?? 'draft') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="template">Template</label>
                <select id="template" name="template">
                    <?php foreach ($templates as $value => $label): ?>
                        <option value="<?= e((string)$value) ?>"<?= (string)($page['template'] ?? 'default') === (string)$value ? ' selected' : '' ?>><?= e((string)$label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="published_at">Tanggal terbit</label>
                <input id="published_at" type="datetime-local" name="published_at"
                       value="<?= e(AdminUi::dateInput((string)($page['published_at'] ?? ''))) ?>">

                <label for="visibility">Visibilitas</label>
                <select id="visibility" name="visibility">
                    <?php foreach (['public' => 'Publik', 'private' => 'Privat'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string)($page['visibility'] ?? 'public') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="parent_id">Halaman induk</label>
                <select id="parent_id" name="parent_id">
                    <option value="0">— Tidak ada —</option>
                    <?php foreach ($parentPages as $parent): ?>
                        <option value="<?= (int)$parent['id'] ?>"<?= (int)($page['parent_id'] ?? 0) === (int)$parent['id'] ? ' selected' : '' ?>>
                            <?= e((string)$parent['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="sort_order">Urutan</label>
                <input id="sort_order" type="number" name="sort_order" value="<?= (int)($page['sort_order'] ?? 0) ?>">

                <label for="thumbnail">Gambar unggulan</label>
                <input id="thumbnail" type="text" name="thumbnail" value="<?= e((string)($page['thumbnail'] ?? '')) ?>">

                <div class="admin-actions" style="margin-top:18px">
                    <button type="submit"><?= $isEdit ? 'Simpan Halaman' : 'Buat Halaman' ?></button>
                </div>
            </div>
        </div>
    </div>
</form>
