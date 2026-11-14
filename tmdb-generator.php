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
use App\Core\Flash;
use App\Core\Response;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Core\View;
use App\Http\AdminController;
use App\Models\Blog;
use App\Models\Comment;
use App\Models\Post;
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

        $postId = Post::save([
            'title' => $overrideTitle !== '' ? $overrideTitle : $article['title'],
            'slug' => $overrideSlug !== '' ? $overrideSlug : $article['slug'],
            'body' => ($_POST['use_edited_body'] ?? '') === '1' && $overrideBody !== '' ? $overrideBody : $article['body'],
            'excerpt' => $overrideExcerpt !== '' ? $overrideExcerpt : $article['excerpt'],
            'meta_title' => $article['seo_title'],
            'meta_description' => $overrideMeta !== '' ? $overrideMeta : $article['meta_description'],
            'thumbnail' => $article['thumbnail'],
            'og_image' => $article['og_image'],
            'status' => $status,
            'author_id' => (int)$user['id'],
        ], $article['categories'], $article['tags']);

        log_activity('post.tmdb.create', 'TMDB ' . $type . ' #' . $tmdbId . ' → post #' . $postId);
        $flash->success(
            'Postingan dibuat (' . $status . '): #' . $postId . ' — ' . $article['title']
            . '. Kategori: ' . $category . '.'
        );
    }

    // ------------------------------------------------------------------
    // GET state
    // ------------------------------------------------------------------

    private function state(): array
    {
        $token = Tmdb::storedToken();
        $hasCredentials = $token !== '';
        $mode = (string)($_GET['mode'] ?? 'search');
        $type = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
        $query = trim((string)($_GET['q'] ?? ''));
        $list = (string)($_GET['list'] ?? 'popular');
        $window = ($_GET['window'] ?? 'week') === 'day' ? 'day' : 'week';
        $selectedId = (int)($_GET['id'] ?? 0);
        $selectedType = ($_GET['media'] ?? $type) === 'tv' ? 'tv' : 'movie';
        $error = '';

        $results = [];
        $details = null;
        $client = new Tmdb();

        if ($hasCredentials) {
            try {
                if ($mode === 'list') {
                    // Trending follows the media picker so a "movies" blog gets
                    // movie-only trends while "tv" still works.
                    $results = $list === 'trending'
                        ? $client->trending($window, 1, $type)
                        : $client->list($list);
                } elseif ($query !== '') {
                    $results = $client->search($query, $type);
                } elseif ($selectedId === 0) {
                    // Landing state: show trending so the page is never empty.
                    $results = $client->trending($window, 1, $type);
                    $mode = 'list';
                    $list = 'trending';
                }
                if ($selectedId > 0) {
                    $details = $client->details($selectedId, $selectedType);
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
            'results' => $results,
            'details' => $details,
            'selectedId' => $selectedId,
            'selectedType' => $selectedType,
            'error' => $error,
            'sections' => TmdbArticle::SECTIONS,
            'posterSizes' => Tmdb::POSTER_SIZES,
            'defaultCategory' => TmdbArticle::DEFAULT_CATEGORY,
            'preview' => $details ? TmdbArticle::preview($details) : null,
            'generated' => $details ? TmdbArticle::build($details, array_keys(TmdbArticle::SECTIONS)) : null,
        ];
    }

    /** Keep the state (search, selection) across the POST redirect. */
    private function stateQuery(): string
    {
        $keep = [];
        foreach (['mode', 'type', 'q', 'list', 'window', 'id', 'media'] as $key) {
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
