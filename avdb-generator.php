<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  AVDB CONTENT GENERATOR — standalone admin page
 *  -----------------------------------------------------------------------------
 *  Generates SEO-ready posts from AVDB (avdbapi.com) metadata.
 *
 *  Mirrors tmdb-generator.php: this page is self-contained. It boots the CMS,
 *  reuses the existing admin login/permissions, the admin shell and the design
 *  system, and adds no code to AdminController beyond a nav entry. Removing this
 *  file (and app/Services/Avdb*.php) leaves the CMS untouched.
 *
 *  FLOW
 *    1. Pick the output language (no API key is required).
 *    2. Browse the latest releases or search by title/code/actor/category.
 *    3. Review metadata, choose which sections go into the article.
 *    4. Generate title/slug/excerpt/meta + body, preview, then save as draft or
 *       publish. Category defaults to "av".
 *
 *  SECURITY
 *    - Requires an authenticated admin session (Auth::check).
 *    - Every POST is CSRF-checked.
 *    - Generated HTML only contains entities we build; image and stream URLs
 *      are validated before being rendered, and the stream is a link (never an
 *      iframe) so third-party players cannot script the page.
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
use App\Services\Avdb;
use App\Services\AvdbArticle;

require dirname(__FILE__) . '/app/bootstrap.php';

Response::securityHeaders();

$page = new AvdbGeneratorPage();
echo $page->run();

/**
 * Controller for the standalone generator. Kept in this file (rather than
 * app/Http) on purpose: no existing file needs to know it exists.
 */
final class AvdbGeneratorPage
{
    private string $self = 'avdb-generator.php';

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
        $data['title'] = 'Generator AVDB';
        $data['activeKey'] = 'avdb';
        $data['selfUrl'] = url($this->self);

        $content = View::render('admin/avdb-generator', $data);
        return View::render('admin/tmdb-shell', $data + ['content' => $content]);
    }

    // ------------------------------------------------------------------
    // POST actions
    // ------------------------------------------------------------------

    private function handlePost(array $user, Flash $flash): void
    {
        $action = (string)($_POST['action'] ?? '');
        switch ($action) {
            case 'avdb.settings':
                Auth::requirePermission('settings.manage');
                Avdb::saveLanguage((string)($_POST['language'] ?? ''));
                $flash->success('Bahasa AVDB disimpan.');
                return;

            case 'avdb.test':
                Auth::requirePermission('settings.manage');
                $status = (new Avdb())->ping();
                $flash->success('Koneksi AVDB berhasil — ' . number_format($status['total']) . ' judul tersedia (bahasa: ' . $status['language'] . ').');
                return;

            case 'avdb.create':
                Auth::requirePermission('post.create');
                $this->createPost($user, $flash);
                return;

            case 'avdb.bulk':
                Auth::requirePermission('post.create');
                $this->bulkCreate($user, $flash);
                return;

            default:
                throw new ValidationException('Aksi tidak dikenal.');
        }
    }

    private function createPost(array $user, Flash $flash): void
    {
        $avdbId = (int)($_POST['avdb_id'] ?? 0);
        if ($avdbId <= 0) {
            throw new ValidationException('Pilih judul terlebih dahulu.');
        }
        if ($this->alreadyPosted($avdbId)) {
            throw new ValidationException('Judul ini sudah pernah dibuat (AVDB #' . $avdbId . ').');
        }

        $item = (new Avdb())->details($avdbId);
        [$article, $status] = $this->buildArticle($item);

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
            'content_json' => $this->provenance($avdbId),
        ], $article['categories'], $article['tags']);

        log_activity('post.avdb.create', 'AVDB #' . $avdbId . ' → post #' . $postId);
        $flash->success(
            'Postingan dibuat (' . $status . '): #' . $postId . ' — ' . $article['title']
            . '. Kategori: ' . implode(', ', $article['categories']) . '.'
        );
    }

    /**
     * Post every checked title in one submission. Partial failures never abort
     * the run: each item is reported and the rest continue.
     */
    private function bulkCreate(array $user, Flash $flash): void
    {
        $ids = [];
        foreach ((array)($_POST['ids'] ?? []) as $raw) {
            $id = (int)$raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            throw new ValidationException('Centang minimal satu judul untuk posting massal.');
        }

        $client = new Avdb();
        $created = 0;
        $skipped = 0;
        $failed = [];

        foreach ($ids as $avdbId) {
            try {
                if ($this->alreadyPosted($avdbId)) {
                    $skipped++;
                    continue;
                }
                $item = $client->details($avdbId);
                [$article, $status] = $this->buildArticle($item);
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
                    'content_json' => $this->provenance($avdbId),
                ], $article['categories'], $article['tags']);
                log_activity('post.avdb.create', 'AVDB #' . $avdbId . ' → post #' . $postId . ' (massal)');
                $created++;
            } catch (\Throwable $exception) {
                $failed[] = 'AVDB #' . $avdbId . ' (' . $exception->getMessage() . ')';
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
            $flash->add('info', $summary);
        }
    }

    /**
     * Provenance stamped into posts.content_json. Stored, never rendered, and
     * used to recognise which titles were already published.
     */
    private function provenance(int $avdbId): string
    {
        return json_encode([
            'avdb_id' => $avdbId,
            'generator' => 'avdb',
            'generated_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Build the article from AVDB metadata, applying the per-form overrides.
     *
     * @return array{0: array, 1: string} the article payload and resolved status
     */
    private function buildArticle(array $item): array
    {
        $sections = array_values(array_filter(
            array_map('strval', (array)($_POST['sections'] ?? [])),
            static fn(string $key): bool => isset(AvdbArticle::SECTIONS[$key])
        ));
        $category = trim((string)($_POST['category'] ?? AvdbArticle::DEFAULT_CATEGORY)) ?: AvdbArticle::DEFAULT_CATEGORY;
        $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        if ($status === 'published' && !Auth::can('post.publish')) {
            $status = 'draft';
        }

        $article = AvdbArticle::build($item, $sections, $category);

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
     * Has this record already been turned into a post? The generator stamps a
     * small provenance blob into posts.content_json (never rendered), so the
     * check survives slug/title edits and needs no schema change.
     */
    private function alreadyPosted(int $avdbId): bool
    {
        if ($avdbId <= 0) {
            return false;
        }
        try {
            $found = Database::fetch(
                'SELECT id FROM posts WHERE blog_id = ? AND deleted_at IS NULL AND content_json LIKE ? LIMIT 1',
                [Context::blogId(), '%"avdb_id":' . $avdbId . ',%']
            );
        } catch (\Throwable) {
            return false;
        }
        return $found !== null;
    }

    /**
     * Map of avdb id → post id for the records on screen that already exist.
     *
     * @param array<int, array> $results
     * @return array<int, int>
     */
    private function postedMap(array $results): array
    {
        $ids = [];
        foreach ($results as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $map = [];
        try {
            foreach (array_chunk($ids, 40) as $chunk) {
                $rows = Database::run(
                    'SELECT id, content_json FROM posts WHERE blog_id = ? AND deleted_at IS NULL'
                    . ' AND content_json LIKE \'%"avdb_id":%\' AND content_json REGEXP ?',
                    [Context::blogId(), '"avdb_id":(' . implode('|', $chunk) . '),']
                )->fetchAll();
                foreach ($rows as $row) {
                    $meta = json_decode((string)$row['content_json'], true);
                    if (!is_array($meta)) {
                        continue;
                    }
                    $id = (int)($meta['avdb_id'] ?? 0);
                    if ($id > 0) {
                        $map[$id] = (int)$row['id'];
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

    private function state(): array
    {
        $language = Avdb::storedLanguage();
        $mode = (string)($_GET['mode'] ?? 'list');
        if (!in_array($mode, ['list', 'search'], true)) {
            $mode = 'list';
        }
        $type = (int)($_GET['type'] ?? 0);
        if (!isset(Avdb::TYPES[$type])) {
            $type = 0;
        }
        $query = trim((string)($_GET['q'] ?? ''));
        $field = (string)($_GET['field'] ?? 'wd');
        if (!isset(Avdb::SEARCH_FIELDS[$field])) {
            $field = 'wd';
        }
        $sort = ($_GET['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $year = trim((string)($_GET['year'] ?? ''));
        if (preg_match('/^\d{4}(?:-\d{4})?$/', $year) !== 1) {
            $year = '';
        }
        $selectedId = (int)($_GET['id'] ?? 0);
        $error = '';

        $listing = ['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []];
        $details = null;
        $client = new Avdb($language);

        try {
            if ($selectedId === 0 && $mode === 'search' && $query === '') {
                // A search tab with no keyword would show nothing; fall back to
                // the newest releases so the page is never empty.
                $mode = 'list';
            }
            if ($mode === 'search' && $query !== '') {
                $listing = $client->search($field, $query, max(1, (int)($_GET['page'] ?? 1)), $type, $sort);
            } else {
                $listing = $client->browse($type, max(1, (int)($_GET['page'] ?? 1)), $sort, $year);
            }
            if ($selectedId > 0) {
                $details = $client->details($selectedId);
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        return [
            'language' => $language,
            'languages' => Avdb::LANGUAGES,
            'mode' => $mode,
            'type' => $type,
            'types' => Avdb::TYPES,
            'field' => $field,
            'fields' => Avdb::SEARCH_FIELDS,
            'sorts' => Avdb::SORTS,
            'sort' => $sort,
            'query' => $query,
            'year' => $year,
            'page' => $listing['page'],
            'totalPages' => $listing['total_pages'],
            'totalResults' => $listing['total_results'],
            'maxPage' => Avdb::MAX_PAGE,
            'pageSize' => Avdb::PAGE_SIZE,
            'results' => $listing['results'],
            'details' => $details,
            'selectedId' => $selectedId,
            'error' => $error,
            'sections' => AvdbArticle::SECTIONS,
            'defaultCategory' => AvdbArticle::DEFAULT_CATEGORY,
            'preview' => $details ? AvdbArticle::preview($details) : null,
            'generated' => $details ? AvdbArticle::build($details, array_keys(AvdbArticle::SECTIONS)) : null,
            'postedMap' => $this->postedMap($listing['results']),
            'alreadyPosted' => $selectedId > 0 ? $this->alreadyPosted($selectedId) : false,
        ];
    }

    /** Keep the state (search, selection) across the POST redirect. */
    private function stateQuery(): string
    {
        $keep = [];
        foreach (['mode', 'type', 'field', 'q', 'sort', 'year', 'page', 'id'] as $key) {
            $value = (string)($_POST[$key] ?? '');
            if ($value !== '') {
                $keep[$key] = $value;
            }
        }
        return $keep ? '?' . http_build_query($keep) : '';
    }

    /**
     * The shared admin navigation. AdminController owns the sidebar entry
     * (`avdb`); the fallback only matters on an install where that entry was
     * never added, so the page is still reachable and never blank.
     */
    private function navigation(): array
    {
        $nav = AdminController::navigation();
        $group = __('admin_group_growth');
        if (!isset($nav[$group]['avdb'])) {
            $nav[$group]['avdb'] = ['Generator AVDB', 'film'];
        }
        return $nav;
    }
}
