<?php
declare(strict_types=1);

/**
 * TMDB content generator screen.
 *
 * Three stages on one page:
 *   1. Credentials card (collapses once a token is saved).
 *   2. Source picker — search, trending or curated lists.
 *   3. Generator — metadata preview, section toggles and the save form.
 *
 * @var array $results
 * @var array|null $details
 * @var array|null $preview
 * @var array|null $generated
 * @var array $sections
 * @var array $posterSizes
 * @var bool  $hasCredentials
 * @var string $tokenMasked
 * @var string $mode
 * @var string $type
 * @var string $query
 * @var string $list
 * @var string $window
 * @var int   $selectedId
 * @var string $selectedType
 * @var string $error
 * @var string $defaultCategory
 */

use App\Http\AdminUi;
use App\Services\Tmdb;

$self = url('tmdb-generator.php');
$hasCredentials = (bool)($hasCredentials ?? false);
$error = (string)($error ?? '');
$type = (string)($type ?? 'movie');
$mode = (string)($mode ?? 'search');
$query = (string)($query ?? '');
$list = (string)($list ?? 'popular');
$window = (string)($window ?? 'week');
$selectedId = (int)($selectedId ?? 0);
$selectedType = (string)($selectedType ?? 'movie');
$results = is_array($results ?? null) ? $results : [];
$details = $details ?? null;
$preview = $preview ?? null;
$generated = $generated ?? null;
$sections = is_array($sections ?? null) ? $sections : [];
$posterSizes = is_array($posterSizes ?? null) ? $posterSizes : ['w500'];
$defaultCategory = (string)($defaultCategory ?? 'movies');
$postedMap = is_array($postedMap ?? null) ? $postedMap : [];
$alreadyPosted = (bool)($alreadyPosted ?? false);
$language = (string)($language ?? 'id-ID');
$region = (string)($region ?? 'ID');

$lists = [
    'popular' => 'Populer',
    'now_playing' => 'Sedang tayang',
    'upcoming' => 'Akan datang',
    'top_rated' => 'Rating tertinggi',
    'trending' => 'Trending',
];
?>

<style>
/* --------------------------------------------------------------------------
   TMDB generator styles. Scoped to .tmdb-wrap so the shared admin
   stylesheet is never touched, and built on the existing design tokens so
   light and dark themes both resolve correctly.
   -------------------------------------------------------------------------- */

/* --- Layout ------------------------------------------------------------- */
.tmdb-wrap { display: grid; gap: 18px; }

/* Card headings hold an inline icon, so lay them out as a flex unit instead
   of letting the SVG sit on its own baseline. */
.tmdb-wrap .card > h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 16px;
}
.tmdb-wrap .card > h2 svg { flex: 0 0 auto; color: var(--admin-text-secondary); }

/* --- Forms -------------------------------------------------------------- */

/* Field group used by the credentials and post-settings rows. */
.tmdb-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 14px 16px;
    align-items: start;
}
.tmdb-fields > * { min-width: 0; }
.tmdb-fields label { margin: 0 0 6px; }
.tmdb-fields .help { margin: 6px 0 0; }

/* Search bar: input grows, type picker stays compact, button aligns with the
   input row rather than floating at the bottom of a tall wrapper. */
.tmdb-search {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 10px;
}
.tmdb-search__query { flex: 1 1 240px; min-width: 0; }
.tmdb-search__type { flex: 0 0 148px; }
.tmdb-search__query input,
.tmdb-search__type select { width: 100%; min-height: 43px; }
.tmdb-search label { margin: 0 0 6px; }
/* input[type=search] is not covered by the shared input rules. */
.tmdb-search input[type="search"] {
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
.tmdb-search input[type="search"]:focus {
    border-color: var(--admin-primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .10);
}
.tmdb-search input[type="search"]::placeholder { color: var(--admin-text-muted); }

/* Action rows: buttons plus optional note, tight to the content above. */
.tmdb-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 14px;
    margin: 18px 0 0;
}
.tmdb-actions .muted { margin: 0; }

/* --- Source picker ------------------------------------------------------ */
.tmdb-tabs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px;
    margin: 16px 0 0;
}
.tmdb-tabs a {
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
.tmdb-tabs a:hover { border-color: var(--admin-primary); color: var(--admin-primary); }
.tmdb-tabs a.on {
    background: var(--admin-primary);
    border-color: var(--admin-primary);
    color: var(--admin-primary-contrast, #fff);
}
.tmdb-tabs a.on:hover { color: var(--admin-primary-contrast, #fff); }
.tmdb-tabs-sep {
    width: 1px;
    height: 20px;
    flex: 0 0 auto;
    margin: 0 3px;
    background: var(--admin-border);
}

.tmdb-results {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(138px, 1fr));
    gap: 12px;
    margin-top: 15px;
}
.tmdb-result {
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
.tmdb-result:hover {
    border-color: var(--admin-primary);
    box-shadow: var(--admin-shadow-sm);
    transform: translateY(-1px);
}
.tmdb-result.is-selected { border-color: var(--admin-primary); box-shadow: 0 0 0 2px var(--admin-primary-soft); }
.tmdb-result__poster {
    width: 100%;
    aspect-ratio: 2 / 3;
    object-fit: cover;
    border-radius: 5px;
    background: var(--admin-surface-hover);
}
.tmdb-result__poster--empty { display: block; }
.tmdb-result__title {
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.tmdb-result__meta { font-size: 11.5px; color: var(--admin-text-muted); }
.tmdb-result__pick { margin-top: auto; font-size: 11.5px; font-weight: 650; color: var(--admin-primary); }

/* Bulk selection: <label> styling lives on the card, but the clickable area
   must exclude the checkbox, otherwise the label toggles it twice and cancels
   the click out. Clicking anywhere else (including the link text) selects. */
.tmdb-result--bulk {
    position: relative;
    padding: 8px;
    cursor: pointer;
}
.tmdb-result--bulk .tmdb-result__check { pointer-events: auto; }
.tmdb-result--bulk .tmdb-result__link { pointer-events: none; }
.tmdb-result--bulk .tmdb-result__link > * { pointer-events: none; }
.tmdb-result--bulk:hover { transform: none; }
.tmdb-result--bulk.is-checked {
    border-color: var(--admin-primary);
    box-shadow: 0 0 0 2px var(--admin-primary-soft);
}
.tmdb-result--done { opacity: .72; }
.tmdb-result--done .tmdb-result__poster { filter: grayscale(.55); }
.tmdb-result__check {
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
.tmdb-result__check input {
    width: 15px;
    height: 15px;
    margin: 0;
    cursor: pointer;
}
.tmdb-result__link {
    display: flex;
    flex-direction: column;
    gap: 7px;
    color: inherit;
    text-decoration: none;
}
.tmdb-result--bulk:not(.is-checked):hover { border-color: var(--admin-primary); box-shadow: var(--admin-shadow-sm); }
.tmdb-status {
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
.tmdb-status--done { background: var(--admin-success-soft, #dcfce7); color: var(--admin-success, #16a34a); }
.tmdb-status--new { background: var(--admin-warning-soft, #fef3c7); color: var(--admin-warning, #d97706); }

/* Bulk action bar pinned under the results grid. */
.tmdb-bulk {
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
.tmdb-bulk__count { font-size: 13px; color: var(--admin-text-secondary); }
.tmdb-bulk__count strong { color: var(--admin-text); }
.tmdb-bulk__legend { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; margin-left: auto; font-size: 12px; color: var(--admin-text-muted); }
.tmdb-bulk[hidden] { display: none; }

/* --- Metadata preview --------------------------------------------------- */
.tmdb-detail {
    display: grid;
    grid-template-columns: 200px minmax(0, 1fr);
    gap: 22px;
    align-items: start;
}
.tmdb-detail__poster { width: 100%; border-radius: var(--admin-radius-sm); display: block; }
.tmdb-detail__title { margin: 0 0 10px; font-size: 19px; line-height: 1.3; }
.tmdb-detail__title .muted { font-weight: 400; }
.tmdb-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.tmdb-badge {
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
.tmdb-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
    margin: 12px 0;
    color: var(--admin-text-secondary);
    font-size: 12.5px;
}
.tmdb-overview { margin: 0; line-height: 1.65; }

/* --- Section toggles ---------------------------------------------------- */
.tmdb-sections {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 4px 20px;
    margin: 8px 0 0;
}
.tmdb-sections .checkbox { margin: 0; font-size: 13px; }

/* --- Advanced (collapsed) panel ---------------------------------------- */
.tmdb-advanced { margin: 18px 0 0; border-top: 1px solid var(--admin-border-light); padding-top: 14px; }
.tmdb-advanced > summary {
    display: flex;
    align-items: center;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: var(--admin-text-secondary);
    list-style: none;
}
.tmdb-advanced > summary::-webkit-details-marker { display: none; }
.tmdb-advanced > summary::marker { content: ""; }
.tmdb-advanced > summary::before {
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
.tmdb-advanced[open] > summary::before { transform: rotate(45deg) translateX(-1px); }
.tmdb-advanced > summary:hover { color: var(--admin-primary); }
.tmdb-advanced__body { display: grid; gap: 0; margin-top: 12px; }
.tmdb-advanced__body label { margin: 14px 0 6px; }
.tmdb-advanced__body label:first-child { margin-top: 0; }
.tmdb-advanced__body .checkbox { margin: 14px 0 0; }
.tmdb-advanced__body textarea { min-height: 0; }
.tmdb-advanced__body .help { margin: 6px 0 0; }

/* --- Article preview ---------------------------------------------------- */
.tmdb-preview {
    margin-top: 12px;
    padding: 16px 18px;
    border: 1px dashed var(--admin-border);
    border-radius: var(--admin-radius-sm);
    background: var(--admin-surface-hover);
    max-height: 460px;
    overflow: auto;
    line-height: 1.65;
}
.tmdb-preview > :first-child { margin-top: 0; }
.tmdb-preview > :last-child { margin-bottom: 0; }
.tmdb-preview h1 { font-size: 20px; margin: 18px 0 8px; }
.tmdb-preview h2 { font-size: 16px; margin: 18px 0 8px; }
.tmdb-preview h3 { font-size: 14px; margin: 14px 0 6px; }
.tmdb-preview p { margin: 0 0 10px; }
.tmdb-preview figure { margin: 0 0 14px; }
.tmdb-preview figcaption { font-size: 12px; color: var(--admin-text-muted); margin-top: 6px; }
.tmdb-preview img { max-width: 200px; height: auto; border-radius: 5px; }
.tmdb-preview table { width: auto; min-width: 60%; margin: 10px 0; font-size: 13px; }
.tmdb-preview th { text-align: left; font-weight: 600; color: var(--admin-text-secondary); white-space: nowrap; }
.tmdb-preview td, .tmdb-preview th { padding: 6px 12px 6px 0; vertical-align: top; }
.tmdb-preview ul { margin: 8px 0 12px; padding-left: 20px; }
.tmdb-preview li { margin: 3px 0; }
.tmdb-preview iframe { max-width: 100%; border-radius: 5px; }

.tmdb-attribution {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin: 12px 0 0;
    color: var(--admin-text-muted);
    font-size: 12px;
}
.tmdb-attribution img { vertical-align: middle; }

/* --- Responsive --------------------------------------------------------- */
@media (max-width: 860px) {
    .tmdb-detail { grid-template-columns: 1fr; gap: 16px; }
    .tmdb-detail__poster { max-width: 190px; }
}
@media (max-width: 620px) {
    .tmdb-search__query { flex-basis: 100%; }
    .tmdb-search__type { flex: 1 1 auto; }
    .tmdb-search button { flex: 1 1 100%; }
    .tmdb-results { grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); }
    .tmdb-sections { grid-template-columns: 1fr; }
}
</style>

<div class="toolbar">
    <h1>Generator TMDB</h1>
    <div class="admin-actions">
        <span class="muted">Buat postingan SEO dari metadata The Movie Database</span>
    </div>
</div>

<div class="tmdb-wrap">

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= AdminUi::icon('alert', 16) ?> <?= e($error) ?></div>
    <?php endif; ?>

    <!-- 1. Credentials -->
    <div class="card">
        <h2><?= AdminUi::icon('lock', 16) ?> Kredensial TMDB</h2>
        <?php if ($hasCredentials): ?>
            <p class="help">
                Token tersimpan: <code><?= e($tokenMasked) ?></code>. Bahasa: <strong><?= e($language) ?></strong>,
                region: <strong><?= e($region) ?></strong>.
                Ambil bearer token di
                <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener">pengaturan API TMDB</a>.
            </p>
        <?php else: ?>
            <p class="help">
                Belum ada token. Buat akun di
                <a href="https://developer.themoviedb.org/docs/getting-started" target="_blank" rel="noopener">developer.themoviedb.org</a>,
                lalu salin <strong>API Read Access Token</strong> (bearer) dan tempel di bawah.
            </p>
        <?php endif; ?>
        <form method="post" action="<?= e($self) ?>">
            <input type="hidden" name="action" value="tmdb.credentials">
            <?= \App\Core\Csrf::field() ?>
            <div class="tmdb-fields">
                <div>
                    <label for="token">Bearer token</label>
                    <input id="token" type="password" name="token" placeholder="<?= $hasCredentials ? 'Ganti token (biarkan kosong untuk mempertahankan)' : 'eyJhbGciOi…' ?>" autocomplete="off">
                </div>
                <div>
                    <label for="tmdb_language">Bahasa metadata</label>
                    <input id="tmdb_language" type="text" name="language" value="<?= e($language) ?>" placeholder="id-ID">
                </div>
                <div>
                    <label for="tmdb_region">Region streaming</label>
                    <input id="tmdb_region" type="text" name="region" value="<?= e($region) ?>" placeholder="ID" maxlength="2">
                </div>
            </div>
            <div class="tmdb-actions">
                <button type="submit">Simpan kredensial</button>
                <?php if ($hasCredentials): ?>
                    <button type="submit" name="action" value="tmdb.test" class="secondary">Uji koneksi</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!$hasCredentials): ?>
        <div class="card">
            <div class="empty-state">
                <div class="es-icon"><?= AdminUi::icon('lock', 22) ?></div>
                <p>Simpan token TMDB terlebih dahulu untuk mulai membuat postingan.</p>
            </div>
        </div>
    <?php else: ?>

    <!-- 2. Source picker -->
    <div class="card">
        <h2><?= AdminUi::icon('search', 16) ?> Sumber Data</h2>

        <form method="get" action="<?= e($self) ?>" class="tmdb-search">
            <input type="hidden" name="mode" value="search">
            <div class="tmdb-search__query">
                <label for="q">Cari film / serial</label>
                <input id="q" type="search" name="q" value="<?= e($query) ?>" placeholder="Mis. Fight Club, Breaking Bad…" autocomplete="off">
            </div>
            <div class="tmdb-search__type">
                <label for="type">Tipe</label>
                <select id="type" name="type">
                    <option value="movie"<?= $type === 'movie' ? ' selected' : '' ?>>Film</option>
                    <option value="tv"<?= $type === 'tv' ? ' selected' : '' ?>>Serial TV</option>
                </select>
            </div>
            <button type="submit"><?= AdminUi::icon('search', 16) ?> Cari</button>
        </form>

        <div class="tmdb-tabs">
            <?php foreach ($lists as $key => $label): ?>
                <?php
                $params = ['mode' => 'list', 'list' => $key, 'type' => $type];
                if ($key === 'trending') {
                    $params['window'] = $window;
                }
                $isOn = $mode === 'list' && $list === $key;
                ?>
                <a href="<?= e($self . '?' . http_build_query($params)) ?>" class="<?= $isOn ? 'on' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if ($mode === 'list' && $list === 'trending'): ?>
                <span class="tmdb-tabs-sep" aria-hidden="true"></span>
                <?php foreach (['day' => 'Hari ini', 'week' => 'Minggu ini'] as $key => $label): ?>
                    <a href="<?= e($self . '?' . http_build_query(['mode' => 'list', 'list' => 'trending', 'type' => $type, 'window' => $key])) ?>" class="<?= $window === $key ? 'on' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!$results): ?>
            <div class="empty-state">
                <div class="es-icon"><?= AdminUi::icon('film', 22) ?></div>
                <p>Cari judul di atas atau pilih salah satu daftar cepat (populer, trending, dst.).</p>
            </div>
        <?php else: ?>
            <?php
            $postedCount = 0;
            foreach ($results as $item) {
                $probeKey = (($item['media_type'] ?? $type) === 'tv' ? 'tv' : 'movie') . ':' . (int)($item['id'] ?? 0);
                if (isset($postedMap[$probeKey])) {
                    $postedCount++;
                }
            }
            ?>
            <form method="post" action="<?= e($self) ?>" id="tmdb-bulk-form">
                <input type="hidden" name="action" value="tmdb.bulk">
                <?= \App\Core\Csrf::field() ?>
                <?php
                $carry = ['mode' => $mode, 'type' => $type, 'q' => $query, 'list' => $list,
                    'window' => $window, 'id' => $selectedId, 'media' => $selectedType];
                foreach ($carry as $keepKey => $keepValue):
                    ?>
                    <input type="hidden" name="<?= e($keepKey) ?>" value="<?= e((string)$keepValue) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="category" value="<?= e($defaultCategory) ?>">
                <input type="hidden" name="poster_size" value="w500">
                <input type="hidden" name="status" value="draft">
                <?php foreach (array_keys($sections) as $sectionKey): ?>
                    <input type="hidden" name="sections[]" value="<?= e((string)$sectionKey) ?>">
                <?php endforeach; ?>

                <div class="tmdb-results">
                    <?php foreach ($results as $item): ?>
                        <?php
                        $itemType = (string)($item['media_type'] ?? $type);
                        $itemType = $itemType === 'tv' ? 'tv' : 'movie';
                        if ($itemType === 'person') {
                            continue;
                        }
                        $itemTitle = (string)($item['title'] ?? $item['name'] ?? 'Tanpa judul');
                        $itemYear = substr((string)($item['release_date'] ?? $item['first_air_date'] ?? ''), 0, 4);
                        $poster = Tmdb::image((string)($item['poster_path'] ?? ''), 'w185');
                        $itemId = (int)($item['id'] ?? 0);
                        $isSelected = $itemId === $selectedId && $itemType === $selectedType;
                        $key = $itemType . ':' . $itemId;
                        $postedPostId = $postedMap[$key] ?? null;
                        $selectUrl = $self . '?' . http_build_query([
                            'mode' => $mode, 'type' => $type, 'q' => $query, 'list' => $list,
                            'window' => $window, 'id' => $itemId, 'media' => $itemType,
                        ]);
                        ?>
                        <label class="tmdb-result tmdb-result--bulk<?= $isSelected ? ' is-selected' : '' ?><?= $postedPostId !== null ? ' tmdb-result--done' : '' ?>">
                            <span class="tmdb-result__check">
                                <input type="checkbox" name="ids[]" value="<?= e($key) ?>" data-tmdb-check>
                            </span>
                            <a class="tmdb-result__link" href="<?= e($selectUrl) ?>">
                                <?php if ($poster !== ''): ?>
                                    <img class="tmdb-result__poster" src="<?= e($poster) ?>" alt="Poster <?= e($itemTitle) ?>" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <span class="tmdb-result__poster tmdb-result__poster--empty" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="tmdb-result__title"><?= e($itemTitle) ?></span>
                                <span class="tmdb-result__meta"><?= e(($itemType === 'tv' ? 'Serial' : 'Film') . ($itemYear !== '' ? ' · ' . $itemYear : '')) ?></span>
                                <?php if ($postedPostId !== null): ?>
                                    <span class="tmdb-status tmdb-status--done"><?= AdminUi::icon('check', 11) ?> Sudah diposting</span>
                                <?php else: ?>
                                    <span class="tmdb-status tmdb-status--new">Belum pernah</span>
                                <?php endif; ?>
                                <span class="tmdb-result__pick"><?= $isSelected ? '✓ Dipilih' : 'Pilih →' ?></span>
                            </a>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="tmdb-bulk" id="tmdb-bulk-bar" data-empty-label="Centang judul untuk posting massal" hidden>
                    <span class="tmdb-bulk__count" id="tmdb-bulk-count" aria-live="polite"><strong>0</strong> judul dipilih</span>
                    <button type="submit" data-confirm="Buat postingan untuk semua judul yang dicentang?">
                        <?= AdminUi::icon('layers', 16) ?> Posting semua yang dicentang
                    </button>
                    <button type="button" class="secondary" data-tmdb-clear>Bersihkan pilihan</button>
                    <span class="tmdb-bulk__legend">
                        <span class="tmdb-status tmdb-status--new">Belum pernah</span>
                        <span class="tmdb-status tmdb-status--done"><?= AdminUi::icon('check', 11) ?> Sudah diposting</span>
                        <span><?= (int)$postedCount ?> dari <?= count($results) ?> sudah diposting</span>
                    </span>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- 3. Generator -->
    <?php if ($details && $generated && $preview): ?>
        <div class="card">
            <h2><?= AdminUi::icon('clapper', 16) ?> Pratinjau Metadata</h2>
            <?php if ($alreadyPosted): ?>
                <div class="notice warning">
                    <?= AdminUi::icon('alert', 16) ?>
                    Judul ini sudah pernah dibuat sebelumnya. Membuat ulang akan menambah postingan baru dengan judul serupa.
                </div>
            <?php endif; ?>
            <div class="tmdb-detail">
                <div>
                    <?php if ($preview['poster'] !== ''): ?>
                        <img class="tmdb-detail__poster" src="<?= e($preview['poster']) ?>" alt="Poster <?= e((string)$preview['title']) ?>" loading="lazy">
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="tmdb-detail__title"><?= e((string)$preview['title']) ?>
                        <?php if ((string)$preview['year'] !== ''): ?><span class="muted">(<?= e((string)$preview['year']) ?>)</span><?php endif; ?>
                    </h3>
                    <div class="tmdb-badges">
                        <span class="tmdb-badge"><?= $preview['type'] === 'tv' ? 'Serial TV' : 'Film' ?></span>
                        <?php if ((float)$preview['vote'] > 0): ?>
                            <span class="tmdb-badge">★ <?= e(number_format((float)$preview['vote'], 1)) ?>/10</span>
                        <?php endif; ?>
                        <?php if ((int)$preview['tmdb_id'] > 0): ?>
                            <span class="tmdb-badge">TMDB #<?= (int)$preview['tmdb_id'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="tmdb-meta">
                        <?php if ($preview['genres']): ?><span>Genre: <?= e(implode(', ', $preview['genres'])) ?></span><?php endif; ?>
                        <?php if ((int)$preview['votes'] > 0): ?><span><?= e(number_format((int)$preview['votes'])) ?> suara</span><?php endif; ?>
                    </div>
                    <p class="tmdb-overview"><?= e((string)$preview['overview'] ?: 'Ringkasan belum tersedia di TMDB.') ?></p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2><?= AdminUi::icon('save', 16) ?> Buat Postingan</h2>
            <form method="post" action="<?= e($self) ?>" id="tmdb-create-form">
                <input type="hidden" name="action" value="tmdb.create">
                <input type="hidden" name="tmdb_id" value="<?= (int)$preview['tmdb_id'] ?>">
                <input type="hidden" name="media_type" value="<?= e((string)$preview['type']) ?>">
                <input type="hidden" name="mode" value="<?= e($mode) ?>">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="q" value="<?= e($query) ?>">
                <input type="hidden" name="list" value="<?= e($list) ?>">
                <input type="hidden" name="window" value="<?= e($window) ?>">
                <input type="hidden" name="id" value="<?= $selectedId ?>">
                <input type="hidden" name="media" value="<?= e($selectedType) ?>">
                <?= \App\Core\Csrf::field() ?>

                <label>Bagian yang dimasukkan ke artikel</label>
                <div class="tmdb-sections">
                    <?php foreach ($sections as $key => $label): ?>
                        <label class="checkbox">
                            <input type="checkbox" name="sections[]" value="<?= e($key) ?>" checked>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="tmdb-fields">
                    <div>
                        <label for="category">Kategori</label>
                        <input id="category" type="text" name="category" value="<?= e($defaultCategory) ?>" required>
                        <p class="help">Default <code>movies</code>. Dipisah koma bila lebih dari satu.</p>
                    </div>
                    <div>
                        <label for="poster_size">Ukuran poster</label>
                        <select id="poster_size" name="poster_size">
                            <?php foreach ($posterSizes as $size): ?>
                                <option value="<?= e($size) ?>"<?= $size === 'w500' ? ' selected' : '' ?>><?= e($size) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft">Draft</option>
                            <option value="published">Terbitkan langsung</option>
                        </select>
                    </div>
                </div>

                <details class="tmdb-advanced">
                    <summary>Sesuaikan judul, slug, ringkasan &amp; meta (opsional)</summary>
                    <div class="tmdb-advanced__body">
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

                <div class="tmdb-actions">
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
            <div class="tmdb-preview">
                <?= $generated['body'] ?>
            </div>
            <p class="tmdb-attribution">
                <?= AdminUi::icon('info', 14) ?> Metadata &amp; gambar bersumber dari TMDB.
                <img src="<?= e(Tmdb::attribution()) ?>" alt="TMDB" width="30" height="30">
            </p>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
/* Selection feedback for the bulk posting bar. Progressive: without JS the
   checkboxes still submit, only the live counter and clearing are lost. */
(function () {
    var form = document.getElementById('tmdb-bulk-form');
    if (!form) { return; }
    var bar = form.querySelector('#tmdb-bulk-bar');
    var count = form.querySelector('#tmdb-bulk-count');
    var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-tmdb-check]'));

    function sync() {
        var checked = boxes.filter(function (box) { return box.checked; });
        boxes.forEach(function (box) {
            var card = box.closest('.tmdb-result--bulk');
            if (card) { card.classList.toggle('is-checked', box.checked); }
        });
        if (count) {
            count.innerHTML = '<strong>' + checked.length + '</strong> judul dipilih';
        }
        if (bar) { bar.hidden = checked.length === 0; }
    }

    boxes.forEach(function (box) { box.addEventListener('change', sync); });

    var clear = form.querySelector('[data-tmdb-clear]');
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
