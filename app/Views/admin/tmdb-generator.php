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
/* Scoped to the generator so the shared admin stylesheet stays untouched. */
.tmdb-wrap { display: grid; gap: 18px; }
.tmdb-results { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-top: 14px; }
.tmdb-card { display: flex; flex-direction: column; gap: 8px; padding: 8px; border: 1px solid var(--admin-border, #e2e8f0); border-radius: 8px; background: var(--admin-surface, #fff); text-decoration: none; color: inherit; }
.tmdb-card:hover { border-color: var(--admin-primary); }
.tmdb-card img { width: 100%; aspect-ratio: 2 / 3; object-fit: cover; border-radius: 5px; background: #0f172a1a; }
.tmdb-card strong { font-size: 13px; line-height: 1.35; }
.tmdb-card small { color: var(--admin-muted, #64748b); font-size: 12px; }
.tmdb-card .pick { font-size: 12px; font-weight: 650; color: var(--admin-primary); }
.tmdb-detail { display: grid; grid-template-columns: 220px 1fr; gap: 20px; align-items: start; }
.tmdb-detail img.poster { width: 100%; border-radius: 8px; }
.tmdb-meta { display: flex; flex-wrap: wrap; gap: 8px 16px; color: var(--admin-muted, #64748b); font-size: 13px; margin: 6px 0 12px; }
.tmdb-sections { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px 18px; margin: 10px 0; }
.tmdb-sections .checkbox { margin: 4px 0; }
.tmdb-preview { border: 1px dashed var(--admin-border, #cbd5e1); border-radius: 8px; padding: 14px; background: #f8fafc; max-height: 420px; overflow: auto; }
[data-theme="dark"] .tmdb-preview { background: #0f172a; }
.tmdb-preview h1, .tmdb-preview h2 { margin: 14px 0 6px; }
.tmdb-preview img { max-width: 220px; border-radius: 6px; }
.tmdb-preview table { width: auto; }
.tmdb-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0; }
.tmdb-tabs a { padding: 6px 12px; border: 1px solid var(--admin-border, #e2e8f0); border-radius: 999px; font-size: 13px; text-decoration: none; color: inherit; }
.tmdb-tabs a.on { background: var(--admin-primary); border-color: var(--admin-primary); color: #fff; }
.tmdb-tabs-sep { width: 1px; align-self: stretch; margin: 2px 4px; background: var(--admin-border, #e2e8f0); }
.tmdb-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 650; }
@media (max-width: 780px) { .tmdb-detail { grid-template-columns: 1fr; } .tmdb-detail img.poster { max-width: 200px; } }
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
            <div class="row">
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
            <div class="customizer-actions">
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

        <form method="get" action="<?= e($self) ?>" class="admin-actions" style="gap:8px;align-items:flex-end">
            <input type="hidden" name="mode" value="search">
            <div style="flex:1 1 260px">
                <label for="q">Cari film / serial</label>
                <input id="q" type="search" name="q" value="<?= e($query) ?>" placeholder="Mis. Fight Club, Breaking Bad…" autocomplete="off">
            </div>
            <div style="flex:0 0 150px">
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
                    $selectUrl = $self . '?' . http_build_query([
                        'mode' => $mode, 'type' => $type, 'q' => $query, 'list' => $list,
                        'window' => $window, 'id' => $itemId, 'media' => $itemType,
                    ]);
                    ?>
                    <a class="tmdb-card" href="<?= e($selectUrl) ?>"<?= $isSelected ? ' style="border-color:var(--admin-primary)"' : '' ?>>
                        <?php if ($poster !== ''): ?>
                            <img src="<?= e($poster) ?>" alt="Poster <?= e($itemTitle) ?>" loading="lazy" decoding="async">
                        <?php else: ?>
                            <div style="aspect-ratio:2/3;border-radius:5px;background:#e2e8f0"></div>
                        <?php endif; ?>
                        <strong><?= e($itemTitle) ?></strong>
                        <small><?= e(($itemType === 'tv' ? 'Serial' : 'Film') . ($itemYear !== '' ? ' · ' . $itemYear : '')) ?></small>
                        <span class="pick"><?= $isSelected ? '✓ Dipilih' : 'Pilih →' ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 3. Generator -->
    <?php if ($details && $generated && $preview): ?>
        <div class="card">
            <h2><?= AdminUi::icon('clapper', 16) ?> Pratinjau Metadata</h2>
            <div class="tmdb-detail">
                <div>
                    <?php if ($preview['poster'] !== ''): ?>
                        <img class="poster" src="<?= e($preview['poster']) ?>" alt="Poster <?= e((string)$preview['title']) ?>" loading="lazy">
                    <?php endif; ?>
                </div>
                <div>
                    <h3 style="margin-top:0"><?= e((string)$preview['title']) ?>
                        <?php if ((string)$preview['year'] !== ''): ?><span class="muted">(<?= e((string)$preview['year']) ?>)</span><?php endif; ?>
                    </h3>
                    <div>
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
                    <p><?= e((string)$preview['overview'] ?: 'Ringkasan belum tersedia di TMDB.') ?></p>
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

                <div class="row">
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

                <details style="margin-top:10px">
                    <summary class="muted" style="cursor:pointer">Sesuaikan judul, slug, ringkasan &amp; meta (opsional)</summary>
                    <div style="margin-top:10px">
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
                        <textarea id="gen-body" name="body" rows="12" style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e((string)$generated['body']) ?></textarea>
                    </div>
                </details>

                <div class="customizer-actions">
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
            <p class="help" style="margin-top:10px">
                <?= AdminUi::icon('info', 14) ?> Metadata &amp; gambar bersumber dari TMDB.
                <img src="<?= e(Tmdb::attribution()) ?>" alt="TMDB" width="30" style="vertical-align:middle">
            </p>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
