<?php
declare(strict_types=1);

/**
 * AVDB content generator screen.
 *
 * Two stages on one page:
 *   1. Settings — output language and a connection test (AVDB needs no key).
 *   2. Source picker — browse the latest releases or search, then generate.
 *
 * Deliberately mirrors app/Views/admin/tmdb-generator.php so both generators
 * feel like one module. Styles are scoped under .avdb-wrap so the shared admin
 * stylesheet is never touched.
 *
 * @var array  $results
 * @var array|null $details
 * @var array|null $preview
 * @var array|null $generated
 * @var array  $sections
 * @var array  $languages
 * @var array  $types
 * @var array  $fields
 * @var array  $sorts
 * @var array  $postedMap
 * @var string $language
 * @var string $mode
 * @var int    $type
 * @var string $field
 * @var string $sort
 * @var string $query
 * @var string $year
 * @var int    $page
 * @var int    $totalPages
 * @var int    $totalResults
 * @var int    $selectedId
 * @var bool   $alreadyPosted
 * @var string $defaultCategory
 * @var string $error
 */

use App\Core\Csrf;
use App\Http\AdminUi;
use App\Services\Avdb;
use App\Services\AvdbArticle;

$self = url('avdb-generator.php');
$error = (string)($error ?? '');
$language = (string)($language ?? 'id');
$languages = is_array($languages ?? null) ? $languages : Avdb::LANGUAGES;
$mode = (string)($mode ?? 'list');
$type = (int)($type ?? 0);
$types = is_array($types ?? null) ? $types : Avdb::TYPES;
$field = (string)($field ?? 'wd');
$fields = is_array($fields ?? null) ? $fields : Avdb::SEARCH_FIELDS;
$sort = (string)($sort ?? 'desc');
$sorts = is_array($sorts ?? null) ? $sorts : Avdb::SORTS;
$query = (string)($query ?? '');
$year = (string)($year ?? '');
$page = max(1, (int)($page ?? 1));
$totalPages = max(1, (int)($totalPages ?? 1));
$totalResults = max(0, (int)($totalResults ?? 0));
$results = is_array($results ?? null) ? $results : [];
$details = $details ?? null;
$preview = $preview ?? null;
$generated = $generated ?? null;
$sections = is_array($sections ?? null) ? $sections : [];
$postedMap = is_array($postedMap ?? null) ? $postedMap : [];
$alreadyPosted = (bool)($alreadyPosted ?? false);
$selectedId = (int)($selectedId ?? 0);
$defaultCategory = (string)($defaultCategory ?? AvdbArticle::DEFAULT_CATEGORY);

/**
 * Link back to this page with the listing state preserved. Any key passed in
 * overrides the current value and empty values drop out of the URL, so every
 * tab, filter and page link stays consistent without repeating the query.
 */
$stateUrl = static function (array $overrides = []) use ($self, $mode, $type, $field, $query, $sort, $year, $page, $selectedId): string {
    $params = array_merge([
        'mode' => $mode,
        'type' => $type,
        'field' => $field,
        'q' => $query,
        'sort' => $sort,
        'year' => $year,
        'page' => $page,
        'id' => $selectedId,
    ], $overrides);
    $params = array_filter($params, static fn($value): bool => $value !== '' && $value !== 0 && $value !== '0' && $value !== null);
    return $self . '?' . http_build_query($params);
};
$pager = static function (int $target) use ($stateUrl): string {
    return $stateUrl(['page' => $target]);
};

/**
 * Listing state carried through the bulk and create forms so a POST returns
 * the admin to exactly the page, filter and selection they left.
 */
$carry = ['mode' => $mode, 'type' => $type, 'field' => $field, 'q' => $query,
    'sort' => $sort, 'year' => $year, 'page' => $page, 'id' => $selectedId];
?>

<style>
/* --------------------------------------------------------------------------
   AVDB generator styles. Scoped to .avdb-wrap; built on the existing admin
   design tokens so light and dark themes both resolve correctly.
   -------------------------------------------------------------------------- */

/* --- Layout ------------------------------------------------------------- */
.avdb-wrap { display: grid; gap: 18px; }

.avdb-wrap .card > h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 16px;
}
.avdb-wrap .card > h2 svg { flex: 0 0 auto; color: var(--admin-text-secondary); }

/* --- Forms -------------------------------------------------------------- */
.avdb-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 14px 16px;
    align-items: start;
}
.avdb-fields > * { min-width: 0; }
.avdb-fields label { margin: 0 0 6px; }
.avdb-fields .help { margin: 6px 0 0; }

.avdb-search {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 10px;
}
.avdb-search__query { flex: 1 1 240px; min-width: 0; }
.avdb-search__field { flex: 0 0 168px; }
.avdb-search__type { flex: 0 0 168px; }
.avdb-search__year { flex: 0 0 140px; }
.avdb-search__sort { flex: 0 0 150px; }
.avdb-search__query input,
.avdb-search select { width: 100%; min-height: 43px; }
.avdb-search label { margin: 0 0 6px; }
.avdb-search input[type="search"],
.avdb-search input[type="text"] {
    display: block;
    width: 100%;
    min-height: 43px;
    padding: 10px 12px;
    color: var(--admin-text);
    background: var(--admin-input-bg, #fff);
    border: 1px solid var(--admin-border);
    border-radius: var(--admin-radius-sm);
    outline: none;
    font-size: 13px;
    line-height: 1.5;
    transition: border-color var(--admin-transition), box-shadow var(--admin-transition);
}
.avdb-search input[type="search"]:focus,
.avdb-search input[type="text"]:focus {
    border-color: var(--admin-primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .10);
}
.avdb-search input::placeholder { color: var(--admin-text-muted); }

.avdb-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 14px;
    margin: 18px 0 0;
}
.avdb-actions .muted { margin: 0; }

/* --- Source picker ------------------------------------------------------ */
.avdb-tabs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px;
    margin: 16px 0 0;
}
.avdb-tabs a {
    display: inline-flex;
    align-items: center;
    min-height: 32px;
    padding: 5px 12px;
    border: 1px solid var(--admin-border);
    border-radius: 999px;
    font-size: 12.5px;
    font-weight: 550;
    text-decoration: none;
    color: var(--admin-text-secondary);
    background: var(--admin-surface);
    transition: background var(--admin-transition), border-color var(--admin-transition), color var(--admin-transition);
}
.avdb-tabs a:hover { border-color: var(--admin-primary); color: var(--admin-primary); }
.avdb-tabs a.on {
    background: var(--admin-primary);
    border-color: var(--admin-primary);
    color: var(--admin-primary-contrast, #fff);
}
.avdb-tabs a.on:hover { color: var(--admin-primary-contrast, #fff); }

/* Mode switcher: Cari / Daftar. A segmented control, visually distinct from the
   type pills below it. */
.avdb-mode {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 3px;
    padding: 3px;
    margin: 0 0 16px;
    border: 1px solid var(--admin-border);
    border-radius: var(--admin-radius-sm);
    background: var(--admin-surface-hover);
}
.avdb-mode__item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 5px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--admin-text-secondary);
    text-decoration: none;
    transition: background var(--admin-transition), color var(--admin-transition);
}
.avdb-mode__item svg { flex: 0 0 auto; }
.avdb-mode__item:hover { color: var(--admin-text); background: var(--admin-surface); }
.avdb-mode__item.on {
    background: var(--admin-surface);
    color: var(--admin-primary);
    box-shadow: var(--admin-shadow-sm);
}

/* Pagination */
.avdb-pager {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 16px;
    margin: 18px 0 0;
    padding: 12px 0 0;
    border-top: 1px solid var(--admin-border-light);
}
.avdb-pager__info { font-size: 12.5px; color: var(--admin-text-secondary); }
.avdb-pager__info strong { color: var(--admin-text); }
.avdb-pager__links { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.avdb-pager__num,
.avdb-pager__step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    min-height: 32px;
    padding: 4px 9px;
    border: 1px solid var(--admin-border);
    border-radius: 5px;
    background: var(--admin-surface);
    font-size: 12.5px;
    font-weight: 550;
    color: var(--admin-text-secondary);
    text-decoration: none;
    transition: border-color var(--admin-transition), color var(--admin-transition), background var(--admin-transition);
}
.avdb-pager__num:hover,
.avdb-pager__step:hover { border-color: var(--admin-primary); color: var(--admin-primary); }
.avdb-pager__num.is-current {
    background: var(--admin-primary);
    border-color: var(--admin-primary);
    color: var(--admin-primary-contrast, #fff);
    cursor: default;
}
.avdb-pager__step { padding: 4px 12px; }
.avdb-pager__step.is-disabled { opacity: .45; cursor: not-allowed; }
.avdb-pager__step.is-disabled:hover { border-color: var(--admin-border); color: var(--admin-text-secondary); }
.avdb-pager__gap { padding: 0 2px; color: var(--admin-text-muted); font-size: 12.5px; }
.avdb-pager__jump {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-left: auto;
    font-size: 12.5px;
    color: var(--admin-text-secondary);
}
.avdb-pager__jump input {
    width: 78px;
    min-height: 32px;
    padding: 4px 8px;
    text-align: center;
}
.avdb-pager__jump button { min-height: 32px; padding: 4px 12px; font-size: 12.5px; }
@media (max-width: 620px) {
    .avdb-pager__jump { margin-left: 0; }
}

.avdb-results {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(138px, 1fr));
    gap: 12px;
    margin-top: 15px;
}
.avdb-result {
    display: flex;
    flex-direction: column;
    gap: 7px;
    padding: 8px;
    border: 1px solid var(--admin-border);
    border-radius: var(--admin-radius-sm);
    background: var(--admin-surface);
    color: inherit;
    text-decoration: none;
    transition: border-color var(--admin-transition), box-shadow var(--admin-transition), transform var(--admin-transition);
}
.avdb-result:hover {
    border-color: var(--admin-primary);
    box-shadow: var(--admin-shadow-sm);
    transform: translateY(-1px);
}
.avdb-result.is-selected { border-color: var(--admin-primary); box-shadow: 0 0 0 2px var(--admin-primary-soft); }
.avdb-result__poster {
    width: 100%;
    aspect-ratio: 2 / 3;
    object-fit: cover;
    border-radius: 5px;
    background: var(--admin-surface-hover);
}
.avdb-result__poster--empty { display: block; }
.avdb-result__title {
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.avdb-result__meta { font-size: 11.5px; color: var(--admin-text-muted); }
.avdb-result__pick { margin-top: auto; font-size: 11.5px; font-weight: 650; color: var(--admin-primary); }

/* Bulk selection: the card itself is a normal link, and the checkbox floats
   above it. Using a <label> wrapper would make every click toggle the checkbox
   and block navigation, so the two actions stay separate: click the card to
   open a title, click the checkbox to queue it for bulk posting. */
.avdb-result--bulk {
    position: relative;
    padding: 8px;
}
.avdb-result--bulk .avdb-result__check { pointer-events: auto; }
.avdb-result--bulk:hover { transform: none; }
.avdb-result--bulk:not(.is-checked):hover { border-color: var(--admin-primary); box-shadow: var(--admin-shadow-sm); }
.avdb-result--bulk.is-checked {
    border-color: var(--admin-primary);
    box-shadow: 0 0 0 2px var(--admin-primary-soft);
}
.avdb-result--done { opacity: .72; }
.avdb-result--done .avdb-result__poster { filter: grayscale(.55); }
.avdb-result__check {
    position: absolute;
    top: 12px;
    left: 12px;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 5px;
    background: var(--admin-surface);
    box-shadow: var(--admin-shadow-sm);
}
.avdb-result__check input {
    width: 15px;
    height: 15px;
    margin: 0;
    cursor: pointer;
}
.avdb-result__link {
    display: flex;
    flex-direction: column;
    gap: 7px;
    color: inherit;
    text-decoration: none;
}
.avdb-result--bulk:not(.is-checked):hover { border-color: var(--admin-primary); box-shadow: var(--admin-shadow-sm); }
.avdb-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 700;
    line-height: 1.6;
    letter-spacing: .01em;
}
.avdb-status--done { background: var(--admin-success-soft, #dcfce7); color: var(--admin-success, #16a34a); }
.avdb-status--new { background: var(--admin-warning-soft, #fef3c7); color: var(--admin-warning, #d97706); }

/* Bulk action bar pinned under the results grid. */
.avdb-bulk {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 14px;
    margin: 16px 0 0;
    padding: 12px 14px;
    border: 1px solid var(--admin-border);
    border-radius: var(--admin-radius-sm);
    background: var(--admin-surface-hover);
}
.avdb-bulk__count { font-size: 13px; color: var(--admin-text-secondary); }
.avdb-bulk__count strong { color: var(--admin-text); }
.avdb-bulk__legend { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; margin-left: auto; font-size: 12px; color: var(--admin-text-muted); }
.avdb-bulk[hidden] { display: none; }

/* --- Metadata preview --------------------------------------------------- */
.avdb-detail {
    display: grid;
    grid-template-columns: 200px minmax(0, 1fr);
    gap: 22px;
    align-items: start;
}
.avdb-detail__poster { width: 100%; border-radius: var(--admin-radius-sm); display: block; }
.avdb-detail__title { margin: 0 0 10px; font-size: 19px; line-height: 1.3; }
.avdb-detail__title .muted { font-weight: 400; }
.avdb-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 12px; }
.avdb-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: 999px;
    background: var(--admin-primary-soft);
    color: var(--admin-primary);
    font-size: 11.5px;
    font-weight: 650;
    line-height: 1.5;
    white-space: nowrap;
}
.avdb-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
    margin: 12px 0;
    color: var(--admin-text-secondary);
    font-size: 12.5px;
}
.avdb-overview { margin: 0; line-height: 1.65; }

/* --- Section toggles ---------------------------------------------------- */
.avdb-sections {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 4px 20px;
    margin: 8px 0 0;
}
.avdb-sections .checkbox { margin: 0; font-size: 13px; }

/* --- Advanced (collapsed) panel ---------------------------------------- */
.avdb-advanced { margin: 18px 0 0; border-top: 1px solid var(--admin-border-light); padding-top: 14px; }
.avdb-advanced > summary {
    display: flex;
    align-items: center;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: var(--admin-text-secondary);
    list-style: none;
}
.avdb-advanced > summary::-webkit-details-marker { display: none; }
.avdb-advanced > summary::marker { content: ""; }
.avdb-advanced > summary::before {
    content: "";
    flex: 0 0 auto;
    width: 7px;
    height: 7px;
    margin-right: 8px;
    border-right: 2px solid currentColor;
    border-bottom: 2px solid currentColor;
    transform: rotate(-45deg) translateY(-1px);
    transition: transform var(--admin-transition);
}
.avdb-advanced[open] > summary::before { transform: rotate(45deg) translateX(-1px); }
.avdb-advanced > summary:hover { color: var(--admin-primary); }
.avdb-advanced__body { display: grid; gap: 0; margin-top: 12px; }
.avdb-advanced__body label { margin: 14px 0 6px; }
.avdb-advanced__body label:first-child { margin-top: 0; }
.avdb-advanced__body .checkbox { margin: 14px 0 0; }
.avdb-advanced__body textarea { min-height: 0; }
.avdb-advanced__body .help { margin: 6px 0 0; }

/* --- Article preview ---------------------------------------------------- */
.avdb-attribution {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin: 12px 0 0;
    color: var(--admin-text-muted);
    font-size: 12px;
}
.avdb-preview {
    margin-top: 12px;
    padding: 16px 18px;
    border: 1px dashed var(--admin-border);
    border-radius: var(--admin-radius-sm);
    background: var(--admin-surface-hover);
    max-height: 460px;
    overflow: auto;
    line-height: 1.65;
}
.avdb-preview > :first-child { margin-top: 0; }
.avdb-preview > :last-child { margin-bottom: 0; }
.avdb-preview h2 { font-size: 16px; margin: 18px 0 8px; }
.avdb-preview p { margin: 0 0 10px; }
.avdb-preview figure { margin: 0 0 14px; }
.avdb-preview figcaption { font-size: 12px; color: var(--admin-text-muted); margin-top: 6px; }
.avdb-preview img { max-width: 200px; height: auto; border-radius: 5px; }
.avdb-preview table { width: auto; min-width: 60%; margin: 10px 0; font-size: 13px; }
.avdb-preview th { text-align: left; font-weight: 600; color: var(--admin-text-secondary); white-space: nowrap; }
.avdb-preview td, .avdb-preview th { padding: 6px 12px 6px 0; vertical-align: top; }
.avdb-preview ul { margin: 8px 0 12px; padding-left: 20px; }
.avdb-preview li { margin: 3px 0; }

/* --- Responsive --------------------------------------------------------- */
@media (max-width: 860px) {
    .avdb-detail { grid-template-columns: 1fr; gap: 16px; }
    .avdb-detail__poster { max-width: 190px; }
}
@media (max-width: 620px) {
    .avdb-search__query { flex-basis: 100%; }
    .avdb-search__field,
    .avdb-search__type,
    .avdb-search__year,
    .avdb-search__sort { flex: 1 1 140px; }
    .avdb-search button { flex: 1 1 100%; }
    .avdb-results { grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); }
    .avdb-sections { grid-template-columns: 1fr; }
}
</style>

<div class="toolbar">
    <h1>Generator AVDB</h1>
    <div class="admin-actions">
        <span class="muted">Buat postingan SEO dari metadata AVDB (avdbapi.com)</span>
    </div>
</div>

<div class="avdb-wrap">

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= AdminUi::icon('alert', 16) ?> <?= e($error) ?></div>
    <?php endif; ?>

    <!-- 1. Settings (no API key required) -->
    <div class="card">
        <h2><?= AdminUi::icon('settings', 16) ?> Pengaturan AVDB</h2>
        <p class="help">
            AVDB API gratis dan tidak memerlukan kunci. Pilih bahasa keluaran metadata di bawah —
            pratinjau dan artikel yang dibuat mengikuti bahasa ini.
        </p>
        <form method="post" action="<?= e($self) ?>">
            <input type="hidden" name="action" value="avdb.settings">
            <?= Csrf::field() ?>
            <div class="avdb-fields">
                <div>
                    <label for="language">Bahasa metadata</label>
                    <select id="language" name="language">
                        <?php foreach ($languages as $code => $label): ?>
                            <option value="<?= e((string)$code) ?>"<?= $language === (string)$code ? ' selected' : '' ?>><?= e((string)$label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help">Mengubah bahasa mengosongkan cache AVDB agar tidak menampilkan bahasa lama.</p>
                </div>
            </div>
            <div class="avdb-actions">
                <button type="submit"><?= AdminUi::icon('save', 16) ?> Simpan bahasa</button>
                <button type="submit" name="action" value="avdb.test" class="secondary"><?= AdminUi::icon('zap', 16) ?> Uji koneksi</button>
                <span class="muted">Dokumentasi: <a href="https://avdbapi.com/index.php/label/help.html" target="_blank" rel="noopener noreferrer">avdbapi.com</a></span>
            </div>
        </form>
    </div>

    <!-- 2. Source picker -->
    <div class="card">
        <h2><?= AdminUi::icon('search', 16) ?> Sumber Data</h2>

        <div class="avdb-mode" role="tablist" aria-label="Mode pencarian">
            <?php
            $modes = [
                'list' => ['Daftar terbaru', 'layers'],
                'search' => ['Cari', 'search'],
            ];
            foreach ($modes as $modeKey => [$modeLabel, $modeIcon]):
                $params = ['mode' => $modeKey, 'type' => $type, 'sort' => $sort];
                if ($modeKey === 'search' && $query !== '') {
                    $params['q'] = $query;
                    $params['field'] = $field;
                }
                if ($modeKey === 'list' && $year !== '') {
                    $params['year'] = $year;
                }
                ?>
                <a href="<?= e($self . '?' . http_build_query(array_filter($params, static fn($v): bool => $v !== '' && $v !== 0 && $v !== '0'))) ?>" class="avdb-mode__item<?= $mode === $modeKey ? ' on' : '' ?>" role="tab" aria-selected="<?= $mode === $modeKey ? 'true' : 'false' ?>">
                    <?= AdminUi::icon($modeIcon, 15) ?> <?= e($modeLabel) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($mode === 'search'): ?>
            <form method="get" action="<?= e($self) ?>" class="avdb-search">
                <input type="hidden" name="mode" value="search">
                <div class="avdb-search__query">
                    <label for="q">Kata kunci</label>
                    <input id="q" type="search" name="q" value="<?= e($query) ?>" placeholder="Mis. CAWB-044, Kiyono Saki…" autocomplete="off">
                </div>
                <div class="avdb-search__field">
                    <label for="field">Cari berdasarkan</label>
                    <select id="field" name="field">
                        <?php foreach ($fields as $fieldKey => $fieldLabel): ?>
                            <option value="<?= e((string)$fieldKey) ?>"<?= $field === (string)$fieldKey ? ' selected' : '' ?>><?= e((string)$fieldLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="avdb-search__type">
                    <label for="type">Jenis</label>
                    <select id="type" name="type">
                        <?php foreach ($types as $typeId => $typeLabel): ?>
                            <option value="<?= (int)$typeId ?>"<?= $type === (int)$typeId ? ' selected' : '' ?>><?= e((string)$typeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit"><?= AdminUi::icon('search', 16) ?> Cari</button>
            </form>
        <?php else: ?>
            <form method="get" action="<?= e($self) ?>" class="avdb-search">
                <input type="hidden" name="mode" value="list">
                <div class="avdb-search__type">
                    <label for="type">Jenis</label>
                    <select id="type" name="type">
                        <?php foreach ($types as $typeId => $typeLabel): ?>
                            <option value="<?= (int)$typeId ?>"<?= $type === (int)$typeId ? ' selected' : '' ?>><?= e((string)$typeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="avdb-search__year">
                    <label for="year">Tahun</label>
                    <input id="year" type="text" name="year" value="<?= e($year) ?>" placeholder="2026 atau 2020-2026" inputmode="numeric" autocomplete="off">
                </div>
                <div class="avdb-search__sort">
                    <label for="sort">Urutkan</label>
                    <select id="sort" name="sort">
                        <?php foreach ($sorts as $sortKey => $sortLabel): ?>
                            <option value="<?= e((string)$sortKey) ?>"<?= $sort === (string)$sortKey ? ' selected' : '' ?>><?= e((string)$sortLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit"><?= AdminUi::icon('refresh', 16) ?> Tampilkan</button>
            </form>
        <?php endif; ?>

        <?php // Quick type pills — mirror the API`s `t` categories. ?>
        <div class="avdb-tabs" role="tablist" aria-label="Filter jenis">
            <?php foreach ($types as $typeId => $typeLabel): ?>
                <a href="<?= e($stateUrl(['type' => (int)$typeId, 'id' => 0, 'page' => 1])) ?>" class="<?= $type === (int)$typeId ? 'on' : '' ?>"><?= e((string)$typeLabel) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$results): ?>
            <div class="empty-state">
                <div class="es-icon"><?= AdminUi::icon('film', 22) ?></div>
                <?php if ($mode === 'search'): ?>
                    <p>Tidak ada hasil untuk pencarian ini. Coba kata kunci atau jenis lain.</p>
                <?php else: ?>
                    <p>Belum ada judul pada filter ini. Ubah jenis, tahun, atau urutan di atas.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php
            $postedCount = 0;
            foreach ($results as $item) {
                if (isset($postedMap[(int)($item['id'] ?? 0)])) {
                    $postedCount++;
                }
            }
            ?>
            <form method="post" action="<?= e($self) ?>" id="avdb-bulk-form">
                <input type="hidden" name="action" value="avdb.bulk">
                <?= Csrf::field() ?>
                <?php foreach ($carry as $keepKey => $keepValue): ?>
                    <input type="hidden" name="<?= e($keepKey) ?>" value="<?= e((string)$keepValue) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="category" value="<?= e($defaultCategory) ?>">
                <input type="hidden" name="status" value="draft">
                <?php foreach (array_keys($sections) as $sectionKey): ?>
                    <input type="hidden" name="sections[]" value="<?= e((string)$sectionKey) ?>">
                <?php endforeach; ?>

                <div class="avdb-results">
                    <?php foreach ($results as $item): ?>
                        <?php
                        $itemId = (int)($item['id'] ?? 0);
                        $itemTitle = AvdbArticle::title($item);
                        $itemCode = AvdbArticle::code($item);
                        $itemYear = AvdbArticle::year($item);
                        $poster = Avdb::image((string)($item['poster_url'] ?? ''));
                        if ($poster === '') {
                            $poster = Avdb::image((string)($item['thumb_url'] ?? ''));
                        }
                        $isSelected = $itemId === $selectedId;
                        $postedPostId = $postedMap[$itemId] ?? null;
                        $selectUrl = $stateUrl(['id' => $itemId]);
                        ?>
                        <div class="avdb-result avdb-result--bulk<?= $isSelected ? ' is-selected' : '' ?><?= $postedPostId !== null ? ' avdb-result--done' : '' ?>">
                            <span class="avdb-result__check">
                                <input type="checkbox" name="ids[]" value="<?= $itemId ?>" data-avdb-check aria-label="Pilih <?= e($itemTitle) ?> untuk posting massal">
                            </span>
                            <a class="avdb-result__link" href="<?= e($selectUrl) ?>">
                                <?php if ($poster !== ''): ?>
                                    <img class="avdb-result__poster" src="<?= e($poster) ?>" alt="Poster <?= e($itemTitle) ?>" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <span class="avdb-result__poster avdb-result__poster--empty" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="avdb-result__title"><?= e($itemTitle) ?></span>
                                <span class="avdb-result__meta"><?= e($itemCode !== '' ? $itemCode : 'Tanpa kode') ?><?= $itemYear !== '' ? ' · ' . e($itemYear) : '' ?></span>
                                <?php if ($postedPostId !== null): ?>
                                    <span class="avdb-status avdb-status--done"><?= AdminUi::icon('check', 11) ?> Sudah diposting</span>
                                <?php else: ?>
                                    <span class="avdb-status avdb-status--new">Belum pernah</span>
                                <?php endif; ?>
                                <span class="avdb-result__pick"><?= $isSelected ? '✓ Dipilih' : 'Buka detail →' ?></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="avdb-bulk" id="avdb-bulk-bar" hidden>
                    <span class="avdb-bulk__count" id="avdb-bulk-count" aria-live="polite"><strong>0</strong> judul dipilih</span>
                    <button type="submit" data-confirm="Buat postingan untuk semua judul yang dicentang?">
                        <?= AdminUi::icon('layers', 16) ?> Posting semua yang dicentang
                    </button>
                    <button type="button" class="secondary" data-avdb-clear>Bersihkan pilihan</button>
                    <span class="avdb-bulk__legend">
                        <span class="avdb-status avdb-status--new">Belum pernah</span>
                        <span class="avdb-status avdb-status--done"><?= AdminUi::icon('check', 11) ?> Sudah diposting</span>
                        <span><?= (int)$postedCount ?> dari <?= count($results) ?> sudah diposting</span>
                    </span>
                </div>
            </form>

            <?php if ($totalPages > 1): ?>
                <?php
                // Window of page links around the current page.
                $windowSize = 2;
                $first = max(1, $page - $windowSize);
                $last = min($totalPages, $page + $windowSize);
                ?>
                <nav class="avdb-pager" aria-label="Navigasi halaman hasil">
                    <span class="avdb-pager__info">
                        Halaman <strong><?= (int)$page ?></strong> dari <strong><?= (int)$totalPages ?></strong>
                        <?php if ($totalResults > 0): ?>
                            · <?= e(number_format($totalResults)) ?> judul
                        <?php endif; ?>
                    </span>
                    <div class="avdb-pager__links">
                        <?php if ($page > 1): ?>
                            <a class="avdb-pager__step" href="<?= e($pager($page - 1)) ?>" rel="prev" aria-label="Halaman sebelumnya">‹ Sebelumnya</a>
                        <?php else: ?>
                            <span class="avdb-pager__step is-disabled" aria-hidden="true">‹ Sebelumnya</span>
                        <?php endif; ?>

                        <?php if ($first > 1): ?>
                            <a class="avdb-pager__num" href="<?= e($pager(1)) ?>">1</a>
                            <?php if ($first > 2): ?><span class="avdb-pager__gap">…</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $first; $p <= $last; $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="avdb-pager__num is-current" aria-current="page"><?= (int)$p ?></span>
                            <?php else: ?>
                                <a class="avdb-pager__num" href="<?= e($pager($p)) ?>"><?= (int)$p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($last < $totalPages): ?>
                            <?php if ($last < $totalPages - 1): ?><span class="avdb-pager__gap">…</span><?php endif; ?>
                            <a class="avdb-pager__num" href="<?= e($pager($totalPages)) ?>"><?= (int)$totalPages ?></a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a class="avdb-pager__step" href="<?= e($pager($page + 1)) ?>" rel="next" aria-label="Halaman berikutnya">Berikutnya ›</a>
                        <?php else: ?>
                            <span class="avdb-pager__step is-disabled" aria-hidden="true">Berikutnya ›</span>
                        <?php endif; ?>
                    </div>
                    <form method="get" action="<?= e($self) ?>" class="avdb-pager__jump">
                        <input type="hidden" name="mode" value="<?= e($mode) ?>">
                        <?php foreach (['type' => $type, 'field' => $field, 'q' => $query, 'sort' => $sort, 'year' => $year] as $jumpKey => $jumpValue): ?>
                            <?php if ($jumpValue !== '' && $jumpValue !== 0 && $jumpValue !== '0'): ?>
                                <input type="hidden" name="<?= e($jumpKey) ?>" value="<?= e((string)$jumpValue) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <label for="page-jump">Ke halaman</label>
                        <input id="page-jump" type="number" name="page" min="1" max="<?= (int)$totalPages ?>" value="<?= (int)$page ?>" inputmode="numeric">
                        <button type="submit" class="secondary">Buka</button>
                    </form>
                </nav>
            <?php else: ?>
                <p class="help">Menampilkan <?= count($results) ?> dari <?= e(number_format($totalResults)) ?> judul.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- 3. Generator -->
    <?php if ($details && $generated && $preview): ?>
        <div class="card">
            <h2><?= AdminUi::icon('film', 16) ?> Pratinjau Metadata</h2>
            <?php if ($alreadyPosted): ?>
                <div class="notice warning">
                    <?= AdminUi::icon('alert', 16) ?>
                    Judul ini sudah pernah dibuat sebelumnya. Membuat ulang akan menambah postingan baru dengan judul serupa.
                </div>
            <?php endif; ?>
            <div class="avdb-detail">
                <div>
                    <?php if ((string)$preview['poster'] !== ''): ?>
                        <img class="avdb-detail__poster" src="<?= e((string)$preview['poster']) ?>" alt="Poster <?= e((string)$preview['title']) ?>" loading="lazy">
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="avdb-detail__title"><?= e((string)$preview['title']) ?>
                        <?php if ((string)$preview['year'] !== ''): ?><span class="muted">(<?= e((string)$preview['year']) ?>)</span><?php endif; ?>
                    </h3>
                    <div class="avdb-badges">
                        <?php if ((string)$preview['type_name'] !== ''): ?><span class="avdb-badge"><?= e((string)$preview['type_name']) ?></span><?php endif; ?>
                        <?php if ((string)$preview['code'] !== ''): ?><span class="avdb-badge"><?= e((string)$preview['code']) ?></span><?php endif; ?>
                        <?php if ((int)$preview['id'] > 0): ?><span class="avdb-badge">AVDB #<?= (int)$preview['id'] ?></span><?php endif; ?>
                    </div>
                    <div class="avdb-meta">
                        <?php if ((string)$preview['duration'] !== ''): ?><span>Durasi: <?= e((string)$preview['duration']) ?></span><?php endif; ?>
                        <?php if ((string)$preview['quality'] !== ''): ?><span>Kualitas: <?= e((string)$preview['quality']) ?></span><?php endif; ?>
                        <?php if ((string)$preview['status'] !== ''): ?><span>Status: <?= e((string)$preview['status']) ?></span><?php endif; ?>
                    </div>
                    <div class="avdb-meta">
                        <?php if ($preview['categories']): ?><span>Kategori: <?= e(implode(', ', $preview['categories'])) ?></span><?php endif; ?>
                    </div>
                    <div class="avdb-meta">
                        <?php if ($preview['actors']): ?><span>Pemain: <?= e(implode(', ', $preview['actors'])) ?></span><?php endif; ?>
                        <?php if ($preview['directors']): ?><span>Sutradara: <?= e(implode(', ', $preview['directors'])) ?></span><?php endif; ?>
                    </div>
                    <p class="avdb-overview"><?= e((string)$preview['description'] !== '' ? (string)$preview['description'] : 'Ringkasan belum tersedia di AVDB.') ?></p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2><?= AdminUi::icon('save', 16) ?> Buat Postingan</h2>
            <form method="post" action="<?= e($self) ?>" id="avdb-create-form">
                <input type="hidden" name="action" value="avdb.create">
                <input type="hidden" name="avdb_id" value="<?= (int)$preview['id'] ?>">
                <?= Csrf::field() ?>
                <?php foreach ($carry as $keepKey => $keepValue): ?>
                    <input type="hidden" name="<?= e($keepKey) ?>" value="<?= e((string)$keepValue) ?>">
                <?php endforeach; ?>

                <label>Bagian yang dimasukkan ke artikel</label>
                <div class="avdb-sections">
                    <?php foreach ($sections as $key => $label): ?>
                        <label class="checkbox">
                            <input type="checkbox" name="sections[]" value="<?= e((string)$key) ?>" checked>
                            <?= e((string)$label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="avdb-fields">
                    <div>
                        <label for="category">Kategori</label>
                        <input id="category" type="text" name="category" value="<?= e($defaultCategory) ?>" required>
                        <p class="help">Default <code><?= e($defaultCategory) ?></code>. Dipisah koma bila lebih dari satu.</p>
                    </div>
                    <div>
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft">Draft</option>
                            <option value="published">Terbitkan langsung</option>
                        </select>
                    </div>
                </div>

                <details class="avdb-advanced">
                    <summary>Sesuaikan judul, slug, ringkasan &amp; meta (opsional)</summary>
                    <div class="avdb-advanced__body">
                        <label for="gen-title">Judul</label>
                        <input id="gen-title" type="text" name="title" value="<?= e((string)$generated['title']) ?>">
                        <label for="gen-slug">Slug</label>
                        <input id="gen-slug" type="text" name="slug" value="<?= e((string)$generated['slug']) ?>">
                        <label for="gen-excerpt">Ringkasan</label>
                        <textarea id="gen-excerpt" name="excerpt" rows="2"><?= e((string)$generated['excerpt']) ?></textarea>
                        <label for="gen-meta">Meta description</label>
                        <textarea id="gen-meta" name="meta_description" rows="2"><?= e((string)$generated['meta_description']) ?></textarea>
                        <label class="checkbox">
                            <input type="checkbox" name="use_edited_body" value="1">
                            Gunakan isi artikel yang saya sunting di bawah (abaikan hasil generator)
                        </label>
                        <label for="gen-body">Isi artikel (HTML)</label>
                        <textarea id="gen-body" name="body" rows="12" spellcheck="false"><?= e((string)$generated['body']) ?></textarea>
                    </div>
                </details>

                <div class="avdb-actions">
                    <button type="submit"><?= AdminUi::icon('save', 16) ?> Buat postingan</button>
                    <span class="muted">Disimpan dengan kategori <strong><?= e($defaultCategory) ?></strong>. HTML tetap dapat disunting setelah dibuat.</span>
                </div>
            </form>
        </div>

        <div class="card">
            <h2><?= AdminUi::icon('eye', 16) ?> Pratinjau Artikel</h2>
            <p class="help">
                Struktur HTML yang akan disimpan. Judul: <strong><?= e((string)$generated['seo_title']) ?></strong> ·
                Meta: <?= e((string)$generated['meta_description']) ?>
            </p>
            <?php if (($generated['tags'] ?? []) !== []): ?>
                <div class="avdb-badges">
                    <?php foreach ((array)$generated['tags'] as $tag): ?>
                        <span class="avdb-badge"><?= e((string)$tag) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="avdb-preview">
                <?= $generated['body'] ?>
            </div>
            <p class="avdb-attribution">
                <?= AdminUi::icon('info', 14) ?> Metadata &amp; gambar bersumber dari
                <a href="https://avdbapi.com/" target="_blank" rel="noopener noreferrer">AVDBAPI</a>.
                Tautan pemutar dibuka di tab baru dan tidak disematkan (tidak ada iframe).
            </p>
        </div>
    <?php endif; ?>
</div>

<script>
/* Selection feedback for the bulk posting bar. Progressive: without JS the
   checkboxes still submit, only the live counter and clearing are lost. */
(function () {
    var form = document.getElementById('avdb-bulk-form');
    if (!form) { return; }
    var bar = form.querySelector('#avdb-bulk-bar');
    var count = form.querySelector('#avdb-bulk-count');
    var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-avdb-check]'));

    function sync() {
        var checked = boxes.filter(function (box) { return box.checked; });
        boxes.forEach(function (box) {
            var card = box.closest('.avdb-result--bulk');
            if (card) { card.classList.toggle('is-checked', box.checked); }
        });
        if (count) {
            count.innerHTML = '<strong>' + checked.length + '</strong> judul dipilih';
        }
        if (bar) { bar.hidden = checked.length === 0; }
    }

    boxes.forEach(function (box) { box.addEventListener('change', sync); });

    var clear = form.querySelector('[data-avdb-clear]');
    if (clear) {
        clear.addEventListener('click', function () {
            boxes.forEach(function (box) { box.checked = false; });
            sync();
        });
    }

    var submit = bar ? bar.querySelector('button[type="submit"]') : null;
    if (submit && submit.dataset.confirm) {
        submit.addEventListener('click', function (event) {
            var picked = boxes.filter(function (box) { return box.checked; }).length;
            if (picked === 0 || !window.confirm(submit.dataset.confirm + ' (' + picked + ')')) {
                event.preventDefault();
            }
        });
    }

    sync();
})();
</script>
