<?php
declare(strict_types=1);

/**
 * Post editor: large title, rich text body (Tiptap), taxonomy, publishing
 * controls, SEO fields, featured image and revision history.
 *
 * @var array|null $post
 * @var array  $categoryNames
 * @var array  $tagNames
 * @var array  $revisions
 * @var string $previewUrl
 * @var array  $formats
 */

use App\Http\AdminUi;

$post = $post ?? null;
$categoryNames = $categoryNames ?? [];
$tagNames = $tagNames ?? [];
$revisions = $revisions ?? [];
$formats = $formats ?? [];
$previewUrl = $previewUrl ?? '';
$adminBase = url('admin/index.php');
$isEdit = $post !== null;
$body = (string)($post['body'] ?? '');
$categoriesFlat = array_merge($categoryNames);
?>
<div class="toolbar">
    <h1><?= $isEdit ? 'Edit Postingan' : 'Postingan Baru' ?></h1>
    <div class="admin-actions">
        <a class="btn secondary" href="<?= e($adminBase . '?view=posts') ?>"><?= AdminUi::icon('chevron-left', 16) ?> Kembali</a>
        <?php if ($isEdit && $previewUrl !== ''): ?>
            <a class="btn secondary" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener"><?= AdminUi::icon('eye', 16) ?> Pratinjau</a>
        <?php endif; ?>
        <button type="submit" form="post-form" class="btn"><?= AdminUi::icon('save', 16) ?> <?= $isEdit ? 'Simpan' : 'Simpan Postingan' ?></button>
    </div>
</div>

<?php if ($isEdit): ?>
<div class="post-meta-bar">
    <span>Status: <?= AdminUi::pill((string)$post['status']) ?></span>
    <span class="muted">Dibuat <?= e(AdminUi::dateTime((string)$post['created_at'])) ?></span>
    <span class="muted">Diperbarui <?= e(AdminUi::dateTime((string)$post['updated_at'])) ?></span>
    <span class="muted">Dilihat <?= number_format((int)$post['views'], 0, ',', '.') ?> kali</span>
    <?php if (!empty($post['reading_time'])): ?><span class="muted"><?= (int)$post['reading_time'] ?> menit baca</span><?php endif; ?>
</div>
<?php endif; ?>

<form method="post" action="<?= e($adminBase . '?view=post-edit' . ($isEdit ? '&id=' . (int)$post['id'] : '')) ?>"
      id="post-form" data-editor autocomplete="off">
    <input type="hidden" name="action" value="post.save">
    <input type="hidden" name="id" value="<?= (int)($post['id'] ?? 0) ?>">
    <?= \App\Core\Csrf::field() ?>

    <div class="editor-grid">
        <div class="editor-main">
            <div class="card editor-card">
                <input id="title" type="text" name="title" value="<?= e((string)($post['title'] ?? '')) ?>"
                       placeholder="Judul artikel yang menarik…" required class="title-input" autofocus
                       aria-label="Judul artikel">

                <div class="slug-row">
                    <label for="slug" style="margin:0"><?= AdminUi::icon('link', 14) ?> Slug URL</label>
                    <input id="slug" type="text" name="slug" value="<?= e((string)($post['slug'] ?? '')) ?>"
                           placeholder="otomatis-dari-judul" class="slug-input" data-slug-check>
                    <span class="slug-status" data-slug-status></span>
                </div>
                <p class="help">Pratinjau tautan: <span id="permalink-preview"><?= e(base_url()) ?>…</span></p>

                <?= AdminUi::editorToolbar() ?>

                <div class="rt-editor-wrap">
                    <div data-editor-mount hidden
                         data-placeholder="Mulai menulis artikel Anda…"
                         data-aria-label="Isi artikel"></div>
                </div>
                <textarea name="body" rows="16" class="body-editor" aria-label="HTML artikel"><?= e($body) ?></textarea>
                <div class="editor-status">
                    <span class="editor-count" data-editor-count></span>
                    <?php if ($isEdit): ?>
                        <span data-autosave-status>Autosave aktif — perubahan disimpan sebagai draf setiap 20 detik.</span>
                    <?php endif; ?>
                </div>

                <label for="excerpt" style="margin-top:18px">Ringkasan / excerpt</label>
                <textarea id="excerpt" name="excerpt" rows="3" placeholder="Ringkasan singkat untuk kartu artikel dan meta description."><?= e((string)($post['excerpt'] ?? '')) ?></textarea>
            </div>

            <div class="card">
                <h2>SEO &amp; Metadata</h2>
                <div class="row">
                    <div>
                        <label for="meta_title">Meta title <span class="muted">(maks 65 karakter)</span></label>
                        <input id="meta_title" type="text" name="meta_title" maxlength="255"
                               value="<?= e((string)($post['meta_title'] ?? '')) ?>" data-counter="meta_title">
                        <div class="counter" data-count-for="meta_title"></div>
                    </div>
                    <div>
                        <label for="canonical_url">Canonical URL</label>
                        <input id="canonical_url" type="url" name="canonical_url"
                               placeholder="<?= e(base_url()) ?>2026/01/judul.html"
                               value="<?= e((string)($post['canonical_url'] ?? '')) ?>">
                    </div>
                </div>

                <label for="meta_description">Meta description <span class="muted">(maks 160 karakter)</span></label>
                <textarea id="meta_description" name="meta_description" rows="3" maxlength="320"
                          data-counter="meta_description"><?= e((string)($post['meta_description'] ?? '')) ?></textarea>
                <div class="counter" data-count-for="meta_description"></div>

                <label for="robots">Robots</label>
                <select id="robots" name="robots">
                    <?php foreach (['' => 'Default situs', 'index,follow' => 'index,follow', 'noindex,follow' => 'noindex,follow', 'index,nofollow' => 'index,nofollow', 'noindex,nofollow' => 'noindex,nofollow'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string)($post['robots'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="seo-preview" aria-hidden="true">
                    <span class="seo-url"><?= e(base_url()) ?>…</span>
                    <span class="seo-title" data-seo-title><?= e((string)($post['title'] ?? 'Judul artikel')) ?></span>
                    <span class="seo-desc" data-seo-desc><?= e(AdminUi::excerpt((string)($post['excerpt'] ?? $post['meta_description'] ?? 'Ringkasan artikel akan muncul di sini.'), 160)) ?></span>
                </div>
            </div>
        </div>

        <div class="editor-side">
            <div class="card">
                <h2>Publikasi</h2>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (['draft' => 'Draf', 'published' => 'Terbit', 'trash' => 'Sampah'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string)($post['status'] ?? 'draft') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="published_at">Tanggal terbit</label>
                <input id="published_at" type="datetime-local" name="published_at"
                       value="<?= e(AdminUi::dateInput((string)($post['published_at'] ?? ''))) ?>">
                <p class="help">Kosongkan agar terbit sekarang saat status Terbit.</p>

                <label for="visibility">Visibilitas</label>
                <select id="visibility" name="visibility">
                    <?php foreach (['public' => 'Publik', 'private' => 'Privat', 'password' => 'Dilindungi sandi'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string)($post['visibility'] ?? 'public') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="password-field" data-password-field hidden>
                    <label for="access_password">Sandi akses</label>
                    <input id="access_password" type="text" name="access_password"
                           value="<?= e((string)($post['access_password'] ?? '')) ?>">
                </div>

                <label for="comment_status">Komentar</label>
                <select id="comment_status" name="comment_status">
                    <option value="open"<?= (string)($post['comment_status'] ?? 'open') === 'open' ? ' selected' : '' ?>>Terbuka</option>
                    <option value="closed"<?= (string)($post['comment_status'] ?? '') === 'closed' ? ' selected' : '' ?>>Tertutup</option>
                </select>

                <label for="format">Format</label>
                <select id="format" name="format">
                    <?php foreach ($formats ?: ['standard' => 'Standar', 'video' => 'Video', 'gallery' => 'Galeri', 'quote' => 'Kutipan', 'link' => 'Tautan', 'audio' => 'Audio'] as $value => $label): ?>
                        <option value="<?= e((string)$value) ?>"<?= (string)($post['format'] ?? 'standard') === (string)$value ? ' selected' : '' ?>><?= e((string)$label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="checkbox"><input type="checkbox" name="featured" value="1"<?= !empty($post['featured']) ? ' checked' : '' ?>> Tandai unggulan</label>
                <label class="checkbox"><input type="checkbox" name="sticky" value="1"<?= !empty($post['sticky']) ? ' checked' : '' ?>> Sematkan di atas</label>

                <div class="admin-actions" style="margin-top:18px">
                    <button type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan Postingan' ?></button>
                    <button type="submit" class="secondary" name="_preview" value="1">Simpan &amp; edit</button>
                </div>
            </div>

            <div class="card">
                <h2>Kategori &amp; Tag</h2>
                <label for="categories">Kategori</label>
                <input type="text" name="categories" id="categories" value="<?= e(implode(', ', $categoryNames)) ?>"
                       placeholder="Teknologi, Bisnis">
                <div class="chip-suggestions">
                    <?php foreach ($categoriesFlat as $name): ?>
                        <button type="button" class="chip" data-add-tag="categories" data-value="<?= e($name) ?>"><?= e($name) ?> ✕</button>
                    <?php endforeach; ?>
                </div>
                <p class="help">Pisahkan dengan koma. Kategori baru dibuat otomatis.</p>

                <label for="tags">Tag</label>
                <input type="text" name="tags" id="tags" value="<?= e(implode(', ', $tagNames)) ?>" placeholder="tutorial, produktivitas">
                <p class="help">Tag membantu pembaca menemukan artikel terkait.</p>
            </div>

            <div class="card">
                <h2>Gambar Unggulan</h2>
                <div class="featured-image" data-featured-preview>
                    <?php if (!empty($post['thumbnail'])): ?>
                        <img src="<?= e(media_url((string)$post['thumbnail'])) ?>" alt="">
                    <?php else: ?>
                        <span class="muted">Belum ada gambar</span>
                    <?php endif; ?>
                </div>
                <input type="text" name="thumbnail" value="<?= e((string)($post['thumbnail'] ?? '')) ?>"
                       placeholder="URL gambar atau pilih dari media" data-featured-input>
                <div class="admin-actions" style="margin-top:10px">
                    <button type="button" class="secondary" data-media-open data-media-target="featured"><?= AdminUi::icon('image', 16) ?> Pilih dari Media</button>
                </div>
                <label for="og_image">Gambar Open Graph</label>
                <input id="og_image" type="text" name="og_image" value="<?= e((string)($post['og_image'] ?? '')) ?>" placeholder="Default: gambar unggulan">
            </div>

            <?php if ($isEdit && $revisions): ?>
            <div class="card">
                <h2>Riwayat Revisi</h2>
                <ul class="revision-list">
                    <?php foreach ($revisions as $revision): ?>
                        <li>
                            <strong><?= e(AdminUi::dateTime((string)$revision['created_at'])) ?></strong>
                            <span class="muted"><?= e((string)($revision['title'] ?? '')) ?></span>
                            <?php
                            // The restore form lives after the main edit form: HTML
                            // forbids nested forms, so the button binds to it by id.
                            $restoreFormId = 'revision-restore-' . (int)$revision['id'];
                            ?>
                            <button type="submit" form="<?= e($restoreFormId) ?>" class="secondary small">Pulihkan</button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php elseif ($isEdit): ?>
            <div class="card">
                <h2>Riwayat Revisi</h2>
                <p class="muted">Belum ada revisi tersimpan.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if ($isEdit && $revisions): ?>
    <?php foreach ($revisions as $revision): ?>
        <form method="post" id="revision-restore-<?= (int)$revision['id'] ?>"
              action="<?= e($adminBase . '?view=post-edit&id=' . (int)$post['id']) ?>" hidden>
            <input type="hidden" name="action" value="post.restore-revision">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
            <input type="hidden" name="revision_id" value="<?= (int)$revision['id'] ?>">
            <input type="hidden" name="_redirect" value="admin/index.php?view=post-edit&id=<?= (int)$post['id'] ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<div class="media-modal" data-media-modal hidden>
    <div class="media-modal-inner">
        <div class="media-modal-head">
            <h2 style="margin:0">Pustaka Media</h2>
            <button type="button" class="secondary small" data-media-close>Tutup</button>
        </div>
        <div class="media-modal-grid" data-media-grid>
            <p class="muted">Memuat media…</p>
        </div>
        <form method="post" action="<?= e($adminBase . '?view=media') ?>" enctype="multipart/form-data" class="media-upload-inline">
            <input type="hidden" name="action" value="media.upload">
            <?= \App\Core\Csrf::field() ?>
            <input type="file" name="file" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.zip">
            <button type="submit">Unggah</button>
        </form>
    </div>
</div>
