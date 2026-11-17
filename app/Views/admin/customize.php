<?php
declare(strict_types=1);

/**
 * Theme customizer: grouped fields generated from theme.json schema,
 * with a live preview iframe on large screens.
 *
 * @var \App\Core\Theme $theme
 * @var array  $groups
 * @var array  $values
 * @var array  $schema
 * @var string $activeSlug
 * @var string $previewUrl
 */

use App\Http\AdminUi;

$groups = $groups ?? [];
$values = $values ?? [];
$schema = $schema ?? [];
$activeSlug = $activeSlug ?? '';
$previewUrl = $previewUrl ?? url();
$adminBase = url('admin/index.php');
$themeSlug = $theme->slug();
$dirty = $themeSlug !== $activeSlug;
?>
<div class="toolbar">
    <h1>Kustomisasi</h1>
    <div class="admin-actions">
        <form method="get" action="<?= e($adminBase) ?>" class="inline">
            <input type="hidden" name="view" value="customize">
            <select name="theme" onchange="this.form.submit()" style="min-width:190px">
                <?php foreach (\App\Core\Theme::catalog() as $candidate): ?>
                    <option value="<?= e((string)$candidate['slug']) ?>"<?= $candidate['slug'] === $themeSlug ? ' selected' : '' ?>>
                        <?= e((string)$candidate['name']) ?><?= $candidate['slug'] === $activeSlug ? ' (aktif)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a class="btn secondary" href="<?= e($adminBase . '?view=themes') ?>">Galeri Tema</a>
    </div>
</div>

<?= \App\Core\View::render('admin/_appearance-tabs', ['appearanceActive' => 'customize']) ?>

<?php if ($dirty): ?>
    <div class="notice">
        Anda sedang mengedit <strong><?= e($theme->name()) ?></strong>, bukan tema aktif.
        Simpan pengaturan lalu aktifkan tema ini dari galeri agar perubahan terlihat di situs.
    </div>
<?php endif; ?>

<div class="customizer-shell">
    <form method="post" action="<?= e($adminBase . '?view=customize&theme=' . rawurlencode($themeSlug)) ?>" class="customizer-form" id="customizer-form">
        <input type="hidden" name="action" value="theme.customize">
        <input type="hidden" name="theme" value="<?= e($themeSlug) ?>">
        <?= \App\Core\Csrf::field() ?>

        <?php foreach ($groups as $group => $fields): ?>
            <section class="card">
                <h2><?= e((string)$group) ?></h2>
                <?php foreach ($fields as $field): ?>
                    <?php
                    $key = (string)($field['key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $type = (string)($field['type'] ?? 'text');
                    $value = (string)($values[$key] ?? ($field['default'] ?? ''));
                    $label = (string)($field['label'] ?? $key);
                    $inputId = 'setting-' . preg_replace('/[^a-z0-9_-]/i', '-', $key);
                    ?>
                    <?php if ($type === 'checkbox'): ?>
                        <label class="checkbox" for="<?= e($inputId) ?>">
                            <input id="<?= e($inputId) ?>" type="checkbox" name="settings[<?= e($key) ?>]" value="1"
                                data-live-setting="<?= e($key) ?>"<?= $value === '1' || $value === 'on' ? ' checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php elseif ($type === 'select'): ?>
                        <?php
                        $fieldOptions = (array)($field['options'] ?? []);
                        if (($field['source'] ?? '') === 'font_pairs') {
                            $fieldOptions = \App\Core\Theme::fontPairs();
                        }
                        ?>
                        <label for="<?= e($inputId) ?>"><?= e($label) ?></label>
                        <select id="<?= e($inputId) ?>" name="settings[<?= e($key) ?>]" data-live-setting="<?= e($key) ?>"<?= ($field['source'] ?? '') === 'font_pairs' ? ' data-font-pair' : '' ?>>
                            <?php foreach ($fieldOptions as $option => $optionLabel): ?>
                                <?php $optionValue = is_array($optionLabel) ? (string)($optionLabel['value'] ?? '') : (string)$option ?>
                                <option value="<?= e($optionValue) ?>"<?= $value === $optionValue ? ' selected' : '' ?>>
                                    <?= e(is_array($optionLabel) ? (string)($optionLabel['label'] ?? $optionValue) : (string)$optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($field['description'])): ?>
                            <p class="help"><?= e((string)$field['description']) ?></p>
                        <?php endif; ?>
                    <?php elseif ($type === 'color'): ?>
                        <label for="<?= e($inputId) ?>"><?= e($label) ?></label>
                        <div class="color-field">
                            <input id="<?= e($inputId) ?>" type="color" name="settings[<?= e($key) ?>]" value="<?= e($value ?: '#000000') ?>"
                                   data-live-setting="<?= e($key) ?>">
                            <input type="text" value="<?= e($value) ?>" data-color-echo aria-label="<?= e($label) ?> hex">
                        </div>
                        <?php if (!empty($field['optional'])): ?>
                            <p class="help">
                                Kosongkan untuk memakai nilai otomatis.
                                <a href="#" data-clear-setting="<?= e($key) ?>">Pakai otomatis</a>
                            </p>
                        <?php elseif (!empty($field['description'])): ?>
                            <p class="help"><?= e((string)$field['description']) ?></p>
                        <?php endif; ?>
                    <?php elseif ($type === 'textarea'): ?>
                        <label for="<?= e($inputId) ?>"><?= e($label) ?></label>
                        <textarea id="<?= e($inputId) ?>" name="settings[<?= e($key) ?>]" rows="6"
                                  data-live-setting="<?= e($key) ?>"><?= e($value) ?></textarea>
                    <?php elseif ($type === 'number'): ?>
                        <label for="<?= e($inputId) ?>"><?= e($label) ?></label>
                        <input id="<?= e($inputId) ?>" type="number" name="settings[<?= e($key) ?>]" value="<?= e($value) ?>"
                               min="<?= (int)($field['min'] ?? 0) ?>" max="<?= (int)($field['max'] ?? 999) ?>"
                               data-live-setting="<?= e($key) ?>">
                    <?php else: ?>
                        <label for="<?= e($inputId) ?>"><?= e($label) ?></label>
                        <input id="<?= e($inputId) ?>" type="text" name="settings[<?= e($key) ?>]" value="<?= e($value) ?>"
                               data-live-setting="<?= e($key) ?>">
                        <?php if (!empty($field['description'])): ?>
                            <p class="help"><?= e((string)$field['description']) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <div class="customizer-actions">
            <button type="submit">Simpan Kustomisasi</button>
            <a class="btn secondary" href="<?= e($adminBase . '?view=customize&theme=' . rawurlencode($themeSlug)) ?>">Batal</a>
            <span class="muted" data-save-state></span>
        </div>
    </form>

    <form method="post" action="<?= e($adminBase . '?view=customize&theme=' . rawurlencode($themeSlug)) ?>" style="margin-top:12px"
          onsubmit="return confirm('Kembalikan seluruh kustomisasi tema ini ke nilai bawaan?');">
        <input type="hidden" name="action" value="theme.reset">
        <input type="hidden" name="theme" value="<?= e($themeSlug) ?>">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn secondary small">Kembalikan ke bawaan</button>
        <span class="muted">Mengembalikan warna, font, dan tata letak ke pengaturan awal tema.</span>
    </form>

    <div class="customizer-preview">
        <div class="preview-bar">
            <span class="muted">Pratinjau langsung</span>
            <div class="admin-actions">
                <button type="button" class="secondary small" data-preview-width="390">Mobile</button>
                <button type="button" class="secondary small" data-preview-width="820">Tablet</button>
                <button type="button" class="secondary small" data-preview-width="100%">Desktop</button>
            </div>
        </div>
        <iframe src="<?= e($previewUrl) ?>" title="Pratinjau tema" data-preview-frame></iframe>
    </div>
</div>
