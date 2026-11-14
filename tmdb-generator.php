<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TMDB CONTENT GENERATOR — standalone admin page
 *  -----------------------------------------------------------------------------
 *  Generates SEO-ready posts from The Movie Database (TMDB) metadata.
 *
 *  This page is intentionally self-contained: it boots the CMS, reuses the
 *  existing admin login/permissions, the admin shell and the design system,
 *  but adds no code to AdminController or any other existing file. Removing
 *  this single file (and app/Services/Tmdb*.php) leaves the CMS untouched.
 *
 *  FLOW
 *    1. Save a TMDB bearer token (stored per blog in `settings`).
 *    2. Search, browse trending/popular lists, pick a title.
 *    3. Review metadata, choose which sections go into the article.
 *    4. Generate title/slug/excerpt/meta + body, preview, then save as
 *       draft or publish. Category defaults to "movies".
 *
 *  SECURITY
 *    - Requires an authenticated admin session (Auth::require).
 *    - Every POST is CSRF-checked.
 *    - Generated HTML only contains entities we build; image/video URLs are
 *      validated before being rendered.
 *
 *  ATTRIBUTION
 *    TMDB requires credit. The generator appends an attribution block and the
 *    rendered posts link back to themoviedb.org.
 * ========================================================================== */

use App\Core\Auth;
use App\Core\Container;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Response;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Core\View;
use App\Http\AdminController;
use App\Models\Blog;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Services\Tmdb;
use App\Services\TmdbArticle;

require dirname(__FILE__) . '/app/bootstrap.php';

Response::securityHeaders();

$page = new TmdbGeneratorPage();
echo $page->run();

/**
 * Controller for the standalone generator. Keeping it in this file (rather
 * than app/Http) is deliberate: no existing file needs to know it exists.
 */
final class TmdbGeneratorPage
{
    private string $self = 'tmdb-generator.php';

    public function run(): string
    {
        if (!Auth::check()) {
            Response::redirect(url('admin/index.php?view=login&next=' . urlencode(url($this->self))));
        }
        $user = Auth::require();
        if (!empty($_SESSION['active_blog_id'])) {
            Context::forceBlog((int)$_SESSION['active_blog_id']);
        }

        $flash = Container::get(Flash::class);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                Csrf::verify();
                $this->handlePost($user, $flash);
            } catch (\Throwable $exception) {
                $flash->error($exception->getMessage());
            }
            Response::redirect(url($this->self . $this->stateQuery()));
        }

        $data = $this->state();
        $data['flash'] = $flash->pull();
        $data['user'] = $user;
        $data['nav'] = $this->navigation();
        $data['blogs'] = Blog::forUser((int)$user['id']) ?: [Context::blog()];
        $data['activeBlog'] = Context::blog();
        $data['commentsPending'] = Comment::counts()['pending'] ?? 0;
        $data['siteName'] = Settings::get('site_name', 'CMS');
        $data['title'] = 'Generator TMDB';
        $data['activeKey'] = 'tmdb';
        $data['selfUrl'] = url($this->self);

        $content = View::render('admin/tmdb-generator', $data);
        return View::render('admin/tmdb-shell', $data + ['content' => $content]);
    }

    // ------------------------------------------------------------------
    // POST actions
    // ------------------------------------------------------------------

    private function handlePost(array $user, Flash $flash): void
    {
        $action = (string)($_POST['action'] ?? '');
        switch ($action) {
            case 'tmdb.credentials':
                Auth::requirePermission('settings.manage');
                Tmdb::saveToken(
                    (string)($_POST['token'] ?? ''),
                    (string)($_POST['language'] ?? ''),
                    (string)($_POST['region'] ?? '')
                );
                $flash->success('Kredensial TMDB disimpan.');
                return;

            case 'tmdb.test':
                Auth::requirePermission('settings.manage');
                $config = (new Tmdb())->ping();
                $image = (string)($config['images']['secure_base_url'] ?? '');
                $flash->success('Koneksi TMDB berhasil' . ($image !== '' ? ' — image base: ' . $image : '') . '.');
                return;

            case 'tmdb.create':
                Auth::requirePermission('post.create');
                $this->createPost($user, $flash);
                return;

            case 'tmdb.bulk':
                Auth::requirePermission('post.create');
                $this->bulkCreate($user, $flash);
                return;

            default:
                throw new ValidationException('Aksi tidak dikenal.');
        }
    }

    private function createPost(array $user, Flash $flash): void
    {
        $tmdbId = (int)($_POST['tmdb_id'] ?? 0);
        $type = ($_POST['media_type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
        if ($tmdbId <= 0) {
            throw new ValidationException('Pilih film atau serial terlebih dahulu.');
        }

        $details = (new Tmdb())->details($tmdbId, $type);
        if ($this->alreadyPosted($tmdbId, $type)) {
            throw new ValidationException('Judul ini sudah pernah dibuat (TMDB #' . $tmdbId . ').');
        }

        [$article, $status] = $this->buildArticle($details, $type);

        $postId = Post::save([
            'title' => $article['title'],
            'slug' => $article['slug'],
            'body' => $article['body'],
            'excerpt' => $article['excerpt'],
            'meta_title' => $article['seo_title'],
            'meta_description' => $article['meta_description'],
            'thumbnail' => $article['thumbnail'],
            'og_image' => $article['og_image'],
            'status' => $status,
            'author_id' => (int)$user['id'],
            'content_json' => $this->provenance($tmdbId, $type),
        ], $article['categories'], $article['tags']);

        log_activity('post.tmdb.create', 'TMDB ' . $type . ' #' . $tmdbId . ' → post #' . $postId);
        $flash->success(
            'Postingan dibuat (' . $status . '): #' . $postId . ' — ' . $article['title']
            . '. Kategori: ' . TmdbArticle::DEFAULT_CATEGORY . '.'
        );
    }

    /**
     * Post every checked title in one submission. Partial failures never abort
     * the run: each item is reported and the rest continue.
     */
    private function bulkCreate(array $user, Flash $flash): void
    {
        $checked = (array)($_POST['ids'] ?? []);
        $selection = [];
        foreach ($checked as $raw) {
            $raw = (string)$raw;
            if (preg_match('/^(movie|tv):(\d+)$/', $raw, $match) !== 1) {
                continue;
            }
            $id = (int)$match[2];
            if ($id > 0) {
                $selection[$match[1] . ':' . $id] = ['type' => $match[1], 'id' => $id];
            }
        }
        if ($selection === []) {
            throw new ValidationException('Centang minimal satu judul untuk posting massal.');
        }

        $client = new Tmdb();
        $created = 0;
        $skipped = 0;
        $failed = [];

        foreach ($selection as $item) {
            $type = $item['type'];
            $tmdbId = $item['id'];
            try {
                if ($this->alreadyPosted($tmdbId, $type)) {
                    $skipped++;
                    continue;
                }
                $details = $client->details($tmdbId, $type);
                [$article, $status] = $this->buildArticle($details, $type);
                $postId = Post::save([
                    'title' => $article['title'],
                    'slug' => $article['slug'],
                    'body' => $article['body'],
                    'excerpt' => $article['excerpt'],
                    'meta_title' => $article['seo_title'],
                    'meta_description' => $article['meta_description'],
                    'thumbnail' => $article['thumbnail'],
                    'og_image' => $article['og_image'],
                    'status' => $status,
                    'author_id' => (int)$user['id'],
                    'content_json' => $this->provenance($tmdbId, $type),
                ], $article['categories'], $article['tags']);
                log_activity('post.tmdb.create', 'TMDB ' . $type . ' #' . $tmdbId . ' → post #' . $postId . ' (massal)');
                $created++;
            } catch (\Throwable $exception) {
                $failed[] = $type . ' #' . $tmdbId . ' (' . $exception->getMessage() . ')';
            }
        }

        $parts = [];
        if ($created > 0) {
            $parts[] = $created . ' postingan dibuat';
        }
        if ($skipped > 0) {
            $parts[] = $skipped . ' dilewati (sudah pernah diposting)';
        }
        if ($failed !== []) {
            $parts[] = count($failed) . ' gagal: ' . implode('; ', array_slice($failed, 0, 3));
        }
        $summary = 'Posting massal selesai — ' . ($parts !== [] ? implode(', ', $parts) : 'tidak ada yang diproses') . '.';

        if ($created > 0) {
            $flash->success($summary);
        } elseif ($failed !== []) {
            $flash->error($summary);
        } else {
            $flash->info($summary);
        }
    }

    /**
     * Provenance stamped into posts.content_json. It is stored, never rendered,
     * and lets the generator recognise which titles it already published.
     */
    private function provenance(int $tmdbId, string $type): string
    {
        return json_encode([
            'tmdb_id' => $tmdbId,
            'tmdb_type' => $type,
            'generator' => 'tmdb',
            'generated_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Build the article from TMDB metadata, applying the per-form overrides.
     *
     * @return array{0: array, 1: string} the article payload and resolved status
     */
    private function buildArticle(array $details, string $type): array
    {
        $sections = array_values(array_filter(
            array_map('strval', (array)($_POST['sections'] ?? [])),
            static fn(string $key): bool => isset(TmdbArticle::SECTIONS[$key])
        ));
        $category = trim((string)($_POST['category'] ?? TmdbArticle::DEFAULT_CATEGORY)) ?: TmdbArticle::DEFAULT_CATEGORY;
        $posterSize = (string)($_POST['poster_size'] ?? 'w500');
        $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        if ($status === 'published' && !Auth::can('post.publish')) {
            $status = 'draft';
        }

        $article = TmdbArticle::build($details, $sections, $category, $posterSize);

        $overrideTitle = trim((string)($_POST['title'] ?? ''));
        $overrideSlug = trim((string)($_POST['slug'] ?? ''));
        $overrideExcerpt = trim((string)($_POST['excerpt'] ?? ''));
        $overrideMeta = trim((string)($_POST['meta_description'] ?? ''));
        $overrideBody = trim((string)($_POST['body'] ?? ''));

        if ($overrideTitle !== '') {
            $article['title'] = $overrideTitle;
        }
        if ($overrideSlug !== '') {
            $article['slug'] = $overrideSlug;
        }
        if ($overrideExcerpt !== '') {
            $article['excerpt'] = $overrideExcerpt;
        }
        if ($overrideMeta !== '') {
            $article['meta_description'] = $overrideMeta;
        }
        if (($_POST['use_edited_body'] ?? '') === '1' && $overrideBody !== '') {
            $article['body'] = $overrideBody;
        }

        return [$article, $status];
    }

    /**
     * Has this title already been turned into a post? The generator stamps a
     * small provenance blob into posts.content_json (never rendered), so the
     * check survives slug/title edits and needs no schema change.
     */
    private function alreadyPosted(int $tmdbId, string $type): bool
    {
        if ($tmdbId <= 0) {
            return false;
        }
        try {
            $found = Database::fetch(
                'SELECT id FROM posts WHERE blog_id = ? AND deleted_at IS NULL AND content_json LIKE ? LIMIT 1',
                [Context::blogId(), '%"tmdb_id":' . $tmdbId . ',%']
            );
        } catch (\Throwable) {
            return false;
        }
        return $found !== null;
    }

    /**
     * Map of "type:id" → post id for the titles on screen that already exist.
     *
     * @param array<int, array> $results
     * @return array<string, int>
     */
    private function postedMap(array $results): array
    {
        $ids = [];
        foreach ($results as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = ($item['media_type'] ?? '') === 'tv' ? 'tv' : 'movie';
            }
        }
        if ($ids === []) {
            return [];
        }

        $map = [];
        try {
            foreach (array_chunk($ids, 40, true) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $rows = Database::run(
                    'SELECT id, content_json FROM posts WHERE blog_id = ? AND deleted_at IS NULL'
                    . ' AND content_json LIKE \'%"tmdb_id":%\' AND content_json REGEXP ?',
                    array_merge([Context::blogId()], ['"tmdb_id":(' . implode('|', array_keys($chunk)) . '),'])
                )->fetchAll();
                foreach ($rows as $row) {
                    $meta = json_decode((string)$row['content_json'], true);
                    if (!is_array($meta)) {
                        continue;
                    }
                    $id = (int)($meta['tmdb_id'] ?? 0);
                    $type = ($meta['tmdb_type'] ?? '') === 'tv' ? 'tv' : 'movie';
                    if ($id > 0 && isset($ids[$id])) {
                        $map[$type . ':' . $id] = (int)$row['id'];
                    }
                }
            }
        } catch (\Throwable) {
            // Badges are cosmetic; a query failure must never break the page.
            return [];
        }
        return $map;
    }


    // ------------------------------------------------------------------
    // GET state
    // ------------------------------------------------------------------

    /** List kinds grouped by media type, so TV never appears under "movie". */
    private const MOVIE_LISTS = [
        'popular' => 'Populer',
        'now_playing' => 'Sedang tayang',
        'upcoming' => 'Akan datang',
        'top_rated' => 'Rating tertinggi',
    ];
    private const TV_LISTS = [
        'tv_popular' => 'Serial populer',
        'tv_airing_today' => 'Tayang hari ini',
        'tv_on_the_air' => 'Sedang tayang',
        'tv_top_rated' => 'Serial rating tertinggi',
    ];

    private function state(): array
    {
        $token = Tmdb::storedToken();
        $hasCredentials = $token !== '';
        $mode = (string)($_GET['mode'] ?? 'search');
        if (!in_array($mode, ['search', 'list', 'discover'], true)) {
            $mode = 'search';
        }
        $type = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
        $query = trim((string)($_GET['q'] ?? ''));
        $list = (string)($_GET['list'] ?? 'popular');
        $window = ($_GET['window'] ?? 'week') === 'day' ? 'day' : 'week';
        $page = max(1, min(Tmdb::MAX_PAGE, (int)($_GET['page'] ?? 1)));
        $genre = max(0, (int)($_GET['genre'] ?? 0));
        $sort = (string)($_GET['sort'] ?? 'popularity.desc');
        $selectedId = (int)($_GET['id'] ?? 0);
        $selectedType = ($_GET['media'] ?? $type) === 'tv' ? 'tv' : 'movie';
        $error = '';

        $listing = ['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []];
        $genres = [];
        $details = null;
        $client = new Tmdb();

        if ($hasCredentials) {
            try {
                if ($selectedId === 0 && $mode === 'search' && $query === '') {
                    // Landing state: show a movie list so the page is never empty.
                    $mode = 'list';
                    $list = 'popular';
                }
                if ($mode === 'list') {
                    if ($list === 'trending') {
                        $listing = $client->trending($window, $page, $type);
                    } else {
                        // A list kind belongs to one media type; a stale kind
                        // after switching type falls back to the default list.
                        $known = $type === 'tv' ? self::TV_LISTS : self::MOVIE_LISTS;
                        if (!isset($known[$list])) {
                            $list = $type === 'tv' ? 'tv_popular' : 'popular';
                        }
                        $listing = $client->browse($list, $page);
                    }
                } elseif ($mode === 'discover') {
                    $genres = $client->genres($type);
                    $listing = $client->discover($type, $page, $genre, $sort);
                } elseif ($query !== '') {
                    $listing = $client->search($query, $type, $page);
                }
                if ($selectedId > 0) {
                    $details = $client->details($selectedId, $selectedType);
                    if ($genres === []) {
                        $genres = $client->genres($type);
                    }
                }
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return [
            'hasCredentials' => $hasCredentials,
            'tokenMasked' => $this->maskToken($token),
            'language' => Tmdb::language(),
            'region' => Tmdb::region(),
            'mode' => $mode,
            'type' => $type,
            'query' => $query,
            'list' => $list,
            'window' => $window,
            'page' => $listing['page'],
            'totalPages' => $listing['total_pages'],
            'totalResults' => $listing['total_results'],
            'genre' => $genre,
            'sort' => Tmdb::sortFor($sort, $type),
            'genres' => $genres,
            // Only offer the date sort that matches the chosen media type.
            'sorts' => array_filter(
                Tmdb::SORTS,
                static fn(string $key): bool => $key !== 'primary_release_date.desc' && $key !== 'first_air_date.desc'
                    || ($type === 'tv' ? $key === 'first_air_date.desc' : $key === 'primary_release_date.desc'),
                ARRAY_FILTER_USE_KEY
            ),
            'movieLists' => self::MOVIE_LISTS,
            'tvLists' => self::TV_LISTS,
            'maxPage' => Tmdb::MAX_PAGE,
            'results' => $listing['results'],
            'details' => $details,
            'selectedId' => $selectedId,
            'selectedType' => $selectedType,
            'error' => $error,
            'sections' => TmdbArticle::SECTIONS,
            'posterSizes' => Tmdb::POSTER_SIZES,
            'defaultCategory' => TmdbArticle::DEFAULT_CATEGORY,
            'preview' => $details ? TmdbArticle::preview($details) : null,
            'generated' => $details ? TmdbArticle::build($details, array_keys(TmdbArticle::SECTIONS)) : null,
            'postedMap' => $this->postedMap($listing['results']),
            'alreadyPosted' => $selectedId > 0 ? $this->alreadyPosted($selectedId, $selectedType) : false,
        ];
    }

    /** Keep the state (search, selection) across the POST redirect. */
    private function stateQuery(): string
    {
        $keep = [];
        foreach (['mode', 'type', 'q', 'list', 'window', 'page', 'genre', 'sort', 'id', 'media'] as $key) {
            $value = (string)($_POST[$key] ?? '');
            if ($value !== '') {
                $keep[$key] = $value;
            }
        }
        return $keep ? '?' . http_build_query($keep) : '';
    }

    private function maskToken(string $token): string
    {
        if ($token === '') {
            return '';
        }
        if (strlen($token) <= 10) {
            return str_repeat('•', strlen($token));
        }
        return substr($token, 0, 4) . str_repeat('•', 12) . substr($token, -4);
    }

    /**
     * The shared admin navigation plus this page. Passing our own nav keeps
     * the shell's breadcrumb/sidebar intact without editing AdminController.
     */
    private function navigation(): array
    {
        $nav = AdminController::navigation();
        // "clapper" is not in AdminUi's built-in set; the view renders a film
        // glyph for this entry via its own icon helper.
        $nav['Pertumbuhan']['tmdb'] = ['Generator TMDB', 'clapper'];
        return $nav;
    }
}
