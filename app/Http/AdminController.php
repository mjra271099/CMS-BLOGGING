<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Helpers;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\Slug;
use App\Core\Str;
use App\Core\Theme;
use App\Core\ThemeEngine;
use App\Core\View;
use App\Core\Widget;
use App\Models\Blog;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Services\Analytics;
use App\Services\Backup;
use App\Services\Builder;
use App\Services\Newsletter;
use App\Services\Seo;

/**
 * Administration controller. One entry point for every admin screen:
 * the view name selects the template, POST actions mutate data, and JSON
 * endpoints back the editor, media picker and drag-and-drop layout.
 */
final class AdminController
{
    private const VIEWS = [
        'dashboard', 'posts', 'post-edit', 'pages', 'page-edit', 'media', 'comments',
        'categories', 'tags', 'themes', 'customize', 'layout', 'menus', 'builder',
        'blogs', 'users', 'seo', 'redirects', 'newsletter', 'settings', 'backup', 'logs', 'profile',
    ];

    /** Sidebar navigation, grouped. */
    public static function navigation(): array
    {
        return [
            'Konten' => [
                'dashboard' => ['Ringkasan', 'grid'],
                'posts' => ['Postingan', 'file'],
                'pages' => ['Halaman', 'layers'],
                'media' => ['Media', 'image'],
                'comments' => ['Komentar', 'message'],
                'categories' => ['Kategori', 'tag'],
                'tags' => ['Tag', 'hash'],
            ],
            'Tampilan' => [
                'themes' => ['Tema', 'palette'],
                'customize' => ['Kustomisasi', 'sliders'],
                'layout' => ['Tata Letak', 'columns'],
                'menus' => ['Menu', 'menu'],
                'builder' => ['Page Builder', 'blocks'],
            ],
            'Pertumbuhan' => [
                'seo' => ['SEO & Audit', 'search'],
                'redirects' => ['Redirect', 'arrow'],
                'newsletter' => ['Newsletter', 'mail'],
            ],
            'Sistem' => [
                'blogs' => ['Blog', 'globe'],
                'users' => ['Pengguna', 'users'],
                'settings' => ['Pengaturan', 'settings'],
                'backup' => ['Backup', 'database'],
                'logs' => ['Log Aktivitas', 'activity'],
                'profile' => ['Profil Saya', 'user'],
            ],
        ];
    }

    public function handle(): string
    {
        $view = (string)($_GET['view'] ?? 'dashboard');
        // Legacy view aliases (pre-refactor admin URLs).
        $view = match ($view) {
            'theme' => 'themes',
            default => $view,
        };
        if ($view === 'login') {
            return $this->loginScreen();
        }
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'dashboard';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_GET['ajax'])) {
                return $this->ajax((string)$_GET['ajax']);
            }
            return $this->handlePost($view);
        }
        if (isset($_GET['ajax'])) {
            return $this->ajax((string)$_GET['ajax']);
        }

        return $this->render($view);
    }

    // ------------------------------------------------------------------
    // Authentication
    // ------------------------------------------------------------------
    private function loginScreen(): string
    {
        if (Auth::check()) {
            \App\Core\Response::redirect(url('admin/index.php?view=dashboard'));
        }
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                Csrf::verify();
                $login = trim((string)($_POST['login'] ?? ''));
                if ($login === '' || (string)($_POST['password'] ?? '') === '') {
                    throw new \App\Core\ValidationException('Username dan password wajib diisi.');
                }
                if (Auth::attempt($login, (string)$_POST['password'])) {
                    $target = (string)($_SESSION['redirect_after_login'] ?? '');
                    unset($_SESSION['redirect_after_login']);
                    $safe = $target !== '' && str_starts_with($target, base_url()) ? $target : url('admin/index.php?view=dashboard');
                    \App\Core\Response::redirect($safe);
                }
                $error = 'Username/email atau password tidak sesuai.';
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        $_SESSION['redirect_after_login'] = (string)($_GET['next'] ?? $_SESSION['redirect_after_login'] ?? '');

        return View::render('admin/login', [
            'error' => $error,
            'siteName' => Settings::get('site_name', 'CMS'),
            'logo' => Settings::get('logo_url'),
            'csrf' => Csrf::field(),
        ]);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------
    private function render(string $view): string
    {
        $user = Auth::require();
        $this->prepareContext($user);
        $data = $this->collect($view, $user);
        // `layout` is the widget/layout manager; the shell template is `shell`.
        $viewFile = 'admin/' . ($view === 'layout' ? 'widget-layout' : $view);
        if (!View::exists($viewFile)) {
            $viewFile = 'admin/404';
        }
        $content = View::render($viewFile, $data);
        $flash = \App\Core\Container::get(Flash::class)->pull();

        return View::render('admin/shell', [
            'title' => (string)($data['title'] ?? 'Admin'),
            'view' => $view,
            'content' => $content,
            'user' => $user,
            'blogs' => $this->userBlogs($user),
            'activeBlog' => Context::blog(),
            'flash' => $flash,
            'nav' => self::navigation(),
            'commentsPending' => Comment::counts()['pending'] ?? 0,
            'siteName' => Settings::get('site_name', 'CMS'),
        ]);
    }

    /** @return array<string, mixed> */
    private function collect(string $view, array $user): array
    {
        $base = ['user' => $user, 'view' => $view];
        return match ($view) {
            'dashboard' => $base + ['title' => 'Ringkasan', 'stats' => Analytics::overview(), 'activity' => Analytics::recentActivity(8), 'latest' => Post::query(['status' => 'all'], 6), 'comments' => Analytics::latestComments(5), 'topPosts' => Analytics::topPosts(5), 'seo' => Seo::audit()],
            'posts' => $base + $this->postsData(),
            'post-edit' => $base + $this->postEditData(),
            'pages' => $base + ['title' => 'Halaman', 'pages' => Page::all(['status' => 'all'])],
            'page-edit' => $base + $this->pageEditData(),
            'media' => $base + ['title' => 'Media', 'items' => Media::all(['type' => (string)($_GET['type'] ?? 'all'), 'search' => (string)($_GET['q'] ?? '')], 60), 'stats' => Media::stats(), 'folders' => $this->mediaFolders()],
            'comments' => $base + ['title' => 'Komentar', 'items' => Comment::list(['status' => (string)($_GET['status'] ?? 'pending'), 'search' => (string)($_GET['q'] ?? '')], 100), 'counts' => Comment::counts(), 'status' => (string)($_GET['status'] ?? 'pending')],
            'categories' => $base + ['title' => 'Kategori', 'categories' => Taxonomy::categories(), 'editId' => (int)($_GET['edit'] ?? 0)],
            'tags' => $base + ['title' => 'Tag', 'tags' => Taxonomy::tags()],
            'themes' => $base + ['title' => 'Tema', 'catalog' => $this->themesData(), 'activeTheme' => Theme::instance()->slug()],
            'customize' => $base + $this->customizeData($user),
            'layout' => $base + $this->layoutData(),
            'menus' => $base + $this->menusData(),
            'builder' => $base + $this->builderData($user),
            'blogs' => $base + ['title' => 'Blog', 'blogs' => $this->userBlogs($user), 'activeBlog' => Context::blog(), 'domains' => Blog::domains(Context::blogId())],
            'users' => $base + $this->usersData($user),
            'seo' => $base + ['title' => 'SEO & Audit', 'audit' => Seo::audit(), 'redirects' => Seo::redirects(), 'settings' => Settings::all()],
            'redirects' => $base + ['title' => 'Redirect', 'redirects' => Seo::redirects(), 'editId' => (int)($_GET['edit'] ?? 0)],
            'newsletter' => $base + ['title' => 'Newsletter', 'subscribers' => Newsletter::subscribers(200)],
            'settings' => $base + ['title' => 'Pengaturan', 'settings' => Settings::all(), 'defaults' => Settings::defaults()],
            'backup' => $base + ['title' => 'Backup', 'files' => Backup::listFiles()],
            'logs' => $base + ['title' => 'Log Aktivitas', 'entries' => $this->activityEntries(), 'logger' => Logger::tail(200)],
            'profile' => $base + ['title' => 'Profil Saya'],
            default => $base,
        };
    }

    private function prepareContext(array $user): void
    {
        if (!empty($_SESSION['active_blog_id'])) {
            Context::forceBlog((int)$_SESSION['active_blog_id']);
        }
    }

    // ------------------------------------------------------------------
    // POST handling
    // ------------------------------------------------------------------
    private function handlePost(string $view): string
    {
        if (!Auth::check()) {
            return $this->loginScreen();
        }
        $user = Auth::require();
        $this->prepareContext($user);
        $flash = \App\Core\Container::get(Flash::class);
        $action = (string)($_POST['action'] ?? '');
        $redirect = (string)($_POST['_redirect'] ?? '');
        $target = $redirect !== '' && preg_match('/^admin\/index\.php\?/', $redirect)
            ? url($redirect)
            : url('admin/index.php?view=' . $view);

        try {
            Csrf::verify();
            $this->dispatch($action, $user);
            if (!headers_sent()) {
                $flash->success($this->flashMessage($action));
                \App\Core\Response::redirect($target);
            }
        } catch (\Throwable $exception) {
            if (!headers_sent()) {
                $flash->error($exception->getMessage());
                \App\Core\Response::redirect($target);
            }
            throw $exception;
        }
        return '';
    }

    /**
     * Split a comma separated list of category/tag names.
     *
     * @return array<int, string>
     */
    private static function splitNames(string $value): array
    {
        $names = array_map(static fn(string $name): string => trim($name), explode(',', $value));
        $names = array_values(array_unique(array_filter($names, static fn(string $name): bool => $name !== '')));
        return array_slice($names, 0, 40);
    }

    private function flashMessage(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'post.') => 'Perubahan postingan disimpan.',
            str_starts_with($action, 'page.') => 'Perubahan halaman disimpan.',
            str_starts_with($action, 'media.') => 'Media diperbarui.',
            str_starts_with($action, 'comment.') => 'Komentar diperbarui.',
            str_starts_with($action, 'theme.') => 'Tema diperbarui.',
            str_starts_with($action, 'widget.') => 'Widget diperbarui.',
            str_starts_with($action, 'menu.') => 'Menu diperbarui.',
            str_starts_with($action, 'blog.') => 'Blog diperbarui.',
            str_starts_with($action, 'user.') => 'Pengguna diperbarui.',
            str_starts_with($action, 'redirect.') => 'Redirect disimpan.',
            str_starts_with($action, 'settings.') => 'Pengaturan disimpan.',
            str_starts_with($action, 'backup.') => 'Backup selesai.',
            str_starts_with($action, 'builder.') => 'Halaman builder disimpan.',
            default => 'Tersimpan.',
        };
    }

    private function dispatch(string $action, array $user): void
    {
        match ($action) {
            'logout' => $this->logout(),
            'post.save' => $this->savePost($user),
            'post.trash' => $this->postStatus('trash'),
            'post.restore' => $this->postStatus('draft'),
            'post.delete' => Post::purge((int)($_POST['id'] ?? 0)),
            'post.duplicate' => Post::duplicate((int)($_POST['id'] ?? 0)),
            'post.bulk' => Post::bulk((string)($_POST['operation'] ?? ''), (array)($_POST['ids'] ?? [])),
            'page.save' => $this->savePage($user),
            'page.delete' => Page::delete((int)($_POST['id'] ?? 0)),
            'page.duplicate' => $this->duplicatePage(),
            'media.upload' => $this->uploadMedia($user),
            'media.update' => Media::updateMeta((int)($_POST['id'] ?? 0), ['alt' => $_POST['alt'] ?? '', 'caption' => $_POST['caption'] ?? '', 'folder' => $_POST['folder'] ?? '']),
            'media.delete' => Media::delete((int)($_POST['id'] ?? 0)),
            'media.replace' => $this->replaceMedia(),
            'media.resize' => $this->resizeMedia(),
            'comment.moderate' => Comment::moderate((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? 'pending')),
            'comment.delete' => Comment::delete((int)($_POST['id'] ?? 0)),
            'category.save' => $this->saveCategory(),
            'category.delete' => Taxonomy::deleteCategory((int)($_POST['id'] ?? 0)),
            'tag.delete' => Taxonomy::deleteTag((int)($_POST['id'] ?? 0)),
            'theme.activate' => $this->activateTheme(),
            'theme.customize' => $this->saveCustomizer(),
            'theme.duplicate' => ThemeEngine::duplicate((string)($_POST['slug'] ?? ''), (string)($_POST['name'] ?? 'Tema Salinan')),
            'theme.delete' => ThemeEngine::remove((string)($_POST['slug'] ?? '')),
            'theme.import' => $this->installTheme(),
            'widget.save' => $this->saveWidget(),
            'widget.delete' => $this->deleteWidget(),
            'widget.toggle' => $this->toggleWidget(),
            'widget.duplicate' => Widget::duplicate((int)($_POST['id'] ?? 0)),
            'menu.create' => $this->createMenu(),
            'menu.item.save' => $this->saveMenuItem(),
            'menu.item.delete' => Menu::deleteItem((int)($_POST['id'] ?? 0)),
            'menu.delete' => Menu::delete((int)($_POST['id'] ?? 0)),
            'builder.save' => $this->saveBuilder($user),
            'blog.create' => $this->createBlog($user),
            'blog.switch' => $this->switchBlog($user),
            'blog.update' => $this->updateBlog(),
            'domain.add' => Blog::addDomain(Context::blogId(), (string)($_POST['domain'] ?? '')),
            'domain.remove' => Blog::removeDomain((int)($_POST['id'] ?? 0), Context::blogId()),
            'domain.verify' => Blog::verifyDomain((int)($_POST['id'] ?? 0), Context::blogId()),
            'user.save' => $this->saveUser($user),
            'user.delete' => $this->deleteUser($user),
            'redirect.save' => Seo::saveRedirect(['from_path' => $_POST['from_path'] ?? '', 'to_path' => $_POST['to_path'] ?? '', 'status_code' => $_POST['status_code'] ?? 301]),
            'redirect.delete' => $this->deleteRedirect(),
            'settings.save' => $this->saveSettings(),
            'backup.create' => $this->createBackup(),
            'backup.delete' => $this->deleteBackup(),
            'backup.restore' => $this->restoreBackup(),
            'newsletter.delete' => $this->deleteSubscriber(),
            'profile.save' => $this->saveProfile($user),
            default => throw new \App\Core\ValidationException('Aksi tidak dikenal: ' . $action),
        };
    }

    private function logout(): void
    {
        log_activity('auth.logout', 'Keluar dari admin');
        Auth::logout();
        \App\Core\Response::redirect(url('admin/index.php?view=login'));
    }

    // ------------------------------------------------------------------
    // Posts & pages
    // ------------------------------------------------------------------
    private function postsData(): array
    {
        $status = (string)($_GET['status'] ?? 'all');
        $search = trim((string)($_GET['q'] ?? ''));
        $category = (int)($_GET['category'] ?? 0);
        $filters = ['status' => $status, 'search' => $search, 'include_future' => true];
        if ($category > 0) {
            $filters['category_id'] = $category;
        }
        $page = max(1, (int)($_GET['paged'] ?? 1));
        $perPage = 20;
        return [
            'title' => 'Postingan',
            'posts' => Post::query($filters, $perPage, ($page - 1) * $perPage),
            'counts' => Post::statusCounts(),
            'status' => $status,
            'search' => $search,
            'category' => $category,
            'categories' => Taxonomy::categories(),
            'paged' => $page,
            'totalPages' => (int)ceil(max(1, Post::count($filters)) / $perPage),
        ];
    }

    private function postEditData(): array
    {
        $id = (int)($_GET['id'] ?? 0);
        $post = $id > 0 ? Post::find($id) : null;
        if ($id > 0 && !$post) {
            throw new \App\Core\NotFoundException('Postingan tidak ditemukan.');
        }
        $categories = $post ? array_column(Taxonomy::postCategories($id), 'name') : [];
        $tags = $post ? array_column(Taxonomy::postTags($id), 'name') : [];
        return [
            'title' => $post ? 'Edit: ' . $post['title'] : 'Postingan Baru',
            'post' => $post,
            'categoryNames' => $categories,
            'tagNames' => $tags,
            'revisions' => $post ? Post::revisions($id, 10) : [],
            'previewUrl' => $post ? post_url($post) . '?preview=1' : '',
            'formats' => Post::FORMATS,
        ];
    }

    private function savePost(array $user): void
    {
        Auth::requirePermission('post.create');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Auth::requirePermission('post.edit');
        }
        $data = [
            'id' => $id,
            'title' => (string)($_POST['title'] ?? ''),
            'slug' => (string)($_POST['slug'] ?? ''),
            'body' => (string)($_POST['body'] ?? ''),
            'excerpt' => (string)($_POST['excerpt'] ?? ''),
            'thumbnail' => (string)($_POST['thumbnail'] ?? ''),
            'format' => (string)($_POST['format'] ?? 'standard'),
            'status' => (string)($_POST['status'] ?? 'draft'),
            'visibility' => (string)($_POST['visibility'] ?? 'public'),
            'access_password' => (string)($_POST['access_password'] ?? ''),
            'comment_status' => (string)($_POST['comment_status'] ?? 'open'),
            'published_at' => (string)($_POST['published_at'] ?? ''),
            'meta_title' => (string)($_POST['meta_title'] ?? ''),
            'meta_description' => (string)($_POST['meta_description'] ?? ''),
            'canonical_url' => (string)($_POST['canonical_url'] ?? ''),
            'og_image' => (string)($_POST['og_image'] ?? ''),
            'robots' => (string)($_POST['robots'] ?? ''),
            'featured' => !empty($_POST['featured']),
            'sticky' => !empty($_POST['sticky']),
            'content_json' => (string)($_POST['content_json'] ?? '') ?: null,
            'author_id' => $id > 0 ? null : (int)$user['id'],
        ];
        if ($id > 0) {
            unset($data['author_id']);
        }
        $postId = Post::save(
            $data,
            self::splitNames((string)($_POST['categories'] ?? '')),
            self::splitNames((string)($_POST['tags'] ?? ''))
        );
        Post::flushCaches();
        log_activity($id > 0 ? 'post.update' : 'post.create', (string)$data['title']);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Postingan disimpan.'];
        $next = !empty($_POST['_preview'])
            ? url('admin/index.php?view=post-edit&id=' . $postId)
            : url('admin/index.php?view=posts');
        \App\Core\Response::redirect($next);
    }

    private function postStatus(string $status): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($status === 'trash') {
            Post::trash($id);
        } else {
            Post::restore($id);
        }
        Post::flushCaches();
        log_activity('post.' . $status, 'ID ' . $id);
    }

    private function savePage(array $user): void
    {
        Auth::requirePermission('page.create');
        $data = [
            'id' => (int)($_POST['id'] ?? 0),
            'title' => (string)($_POST['title'] ?? ''),
            'slug' => (string)($_POST['slug'] ?? ''),
            'body' => (string)($_POST['body'] ?? ''),
            'excerpt' => (string)($_POST['excerpt'] ?? ''),
            'thumbnail' => (string)($_POST['thumbnail'] ?? ''),
            'template' => (string)($_POST['template'] ?? 'default'),
            'status' => (string)($_POST['status'] ?? 'draft'),
            'visibility' => (string)($_POST['visibility'] ?? 'public'),
            'published_at' => (string)($_POST['published_at'] ?? ''),
            'meta_title' => (string)($_POST['meta_title'] ?? ''),
            'meta_description' => (string)($_POST['meta_description'] ?? ''),
            'canonical_url' => (string)($_POST['canonical_url'] ?? ''),
            'og_image' => (string)($_POST['og_image'] ?? ''),
            'robots' => (string)($_POST['robots'] ?? ''),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'parent_id' => (int)($_POST['parent_id'] ?? 0),
            'custom_css' => (string)($_POST['custom_css'] ?? ''),
            'custom_script' => (string)($_POST['custom_script'] ?? ''),
            'content_json' => (string)($_POST['content_json'] ?? '') ?: null,
            'author_id' => (int)$user['id'],
        ];
        $pageId = Page::save($data);
        log_activity('page.save', (string)$data['title']);
        \App\Core\Response::redirect(url('admin/index.php?view=page-edit&id=' . $pageId));
    }

    private function duplicatePage(): void
    {
        $page = Page::find((int)($_POST['id'] ?? 0));
        if (!$page) {
            throw new \App\Core\ValidationException('Halaman tidak ditemukan.');
        }
        Page::save([
            'title' => $page['title'] . ' (salinan)',
            'slug' => $page['slug'] . '-salinan',
            'body' => $page['body'],
            'status' => 'draft',
            'template' => $page['template'],
            'author_id' => (int)$page['author_id'],
        ]);
    }

    private function pageEditData(): array
    {
        $id = (int)($_GET['id'] ?? 0);
        $page = $id > 0 ? Page::find($id) : null;
        if ($id > 0 && !$page) {
            throw new \App\Core\NotFoundException('Halaman tidak ditemukan.');
        }
        return [
            'title' => $page ? 'Edit: ' . $page['title'] : 'Halaman Baru',
            'page' => $page,
            'templates' => Page::templates(),
            'parentPages' => array_filter(Page::all(['status' => 'all']), static fn($p): bool => (int)$p['id'] !== $id),
            'builderDoc' => $page ? Builder::decode((string)($page['content_json'] ?? '')) : Builder::decode(null),
        ];
    }

    // ------------------------------------------------------------------
    // Media
    // ------------------------------------------------------------------
    private function uploadMedia(array $user): void
    {
        Auth::requirePermission('media.upload');
        $files = $_FILES['files'] ?? $_FILES['file'] ?? null;
        if (!is_array($files)) {
            throw new \App\Core\ValidationException('Pilih file untuk diunggah.');
        }
        $uploaded = 0;
        if (isset($files['name']) && is_array($files['name'])) {
            foreach (array_keys($files['name']) as $index) {
                $uploaded += (int)(bool)Media::upload([
                    'name' => $files['name'][$index],
                    'tmp_name' => $files['tmp_name'][$index],
                    'size' => $files['size'][$index],
                    'error' => $files['error'][$index],
                ], (int)$user['id'], ['alt' => $_POST['alt'] ?? '']);
            }
        } else {
            $uploaded = (int)(bool)Media::upload($files, (int)$user['id'], ['alt' => $_POST['alt'] ?? '']);
        }
        \App\Core\Response::redirect(url('admin/index.php?view=media'));
    }

    private function replaceMedia(): void
    {
        Media::replace((int)($_POST['id'] ?? 0), $_FILES['file'] ?? []);
    }

    private function resizeMedia(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $width = (int)($_POST['width'] ?? 800);
        $height = (int)($_POST['height'] ?? 600);
        $path = Media::resize($id, $width, $height, !empty($_POST['crop']));
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Turunan dibuat: ' . $path];
    }

    private function mediaFolders(): array
    {
        return Database::column(
            'SELECT folder FROM media WHERE blog_id = ? AND folder <> "" GROUP BY folder ORDER BY folder',
            [Context::blogId()]
        );
    }

    // ------------------------------------------------------------------
    // Taxonomy
    // ------------------------------------------------------------------
    private function saveCategory(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $payload = [
            'name' => (string)($_POST['name'] ?? ''),
            'slug' => (string)($_POST['slug'] ?? ''),
            'description' => (string)($_POST['description'] ?? ''),
            'image' => (string)($_POST['image'] ?? ''),
            'color' => (string)($_POST['color'] ?? ''),
            'parent_id' => (int)($_POST['parent_id'] ?? 0),
            'meta_title' => (string)($_POST['meta_title'] ?? ''),
            'meta_description' => (string)($_POST['meta_description'] ?? ''),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            Taxonomy::updateCategory($id, $payload);
        } else {
            Taxonomy::createCategory($payload['name'], $payload);
        }
        Cache::flushGroup('posts');
    }

    // ------------------------------------------------------------------
    // Themes & customizer
    // ------------------------------------------------------------------
    private function themesData(): array
    {
        $items = [];
        foreach (Theme::catalog() as $meta) {
            $row = Database::fetch('SELECT status, source FROM themes WHERE slug = ?', [$meta['slug']]);
            $items[] = $meta + [
                'status' => (string)($row['status'] ?? 'inactive'),
                'source' => (string)($row['source'] ?? 'bundled'),
                'screenshot_url' => url('themes/' . $meta['slug'] . '/' . (string)($meta['screenshot'] ?? 'screenshot.svg')),
            ];
        }
        return $items;
    }

    private function customizeData(array $user): array
    {
        $slug = (string)($_GET['theme'] ?? Theme::instance()->slug());
        if (!Theme::exists($slug)) {
            $slug = Theme::instance()->slug();
        }
        $theme = Theme::make($slug);
        $schema = (array)($theme->metadata()['settings'] ?? []);
        $groups = [];
        foreach ($schema as $definition) {
            $groups[(string)($definition['group'] ?? 'Umum')][] = $definition;
        }
        return [
            'title' => 'Kustomisasi — ' . $theme->name(),
            'theme' => $theme,
            'schema' => $schema,
            'groups' => $groups,
            'values' => $theme->settings(),
            'activeSlug' => Theme::instance()->slug(),
            'previewUrl' => Theme::instance()->slug() === $slug ? url() : url('?theme_preview=' . rawurlencode($slug)),
        ];
    }

    private function activateTheme(): void
    {
        Auth::requirePermission('theme.activate');
        ThemeEngine::activate((string)($_POST['slug'] ?? ''));
        \App\Core\Response::redirect(url('admin/index.php?view=themes'));
    }

    private function saveCustomizer(): void
    {
        Auth::requirePermission('theme.customize');
        $slug = (string)($_POST['theme'] ?? '');
        $theme = Theme::make($slug);
        $values = [];
        foreach ((array)($_POST['settings'] ?? []) as $key => $value) {
            $values[$key] = is_array($value) ? implode(',', $value) : (string)$value;
        }
        foreach ((array)($theme->metadata()['settings'] ?? []) as $definition) {
            $key = (string)($definition['key'] ?? '');
            if ($key !== '' && ($definition['type'] ?? '') === 'checkbox' && !isset($values[$key])) {
                $values[$key] = '0';
            }
        }
        $theme->saveSettings($values);
        log_activity('theme.customize', $slug);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Kustomisasi disimpan.'];
        \App\Core\Response::redirect(url('admin/index.php?view=customize&theme=' . rawurlencode($slug)));
    }

    private function installTheme(): void
    {
        Auth::requirePermission('theme.activate');
        $result = ThemeEngine::installFromZip((string)($_FILES['zip']['tmp_name'] ?? ''));
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tema terpasang: ' . $result['name']];
    }

    // ------------------------------------------------------------------
    // Widgets & layout
    // ------------------------------------------------------------------
    private function layoutData(): array
    {
        $widgets = Widget::all();
        $sections = [];
        foreach (Widget::sections() as $id => $label) {
            $sections[$id] = ['label' => $label, 'widgets' => []];
        }
        foreach ($widgets as $widget) {
            $sections[(string)$widget['section_id']]['widgets'][] = $widget;
        }
        $active = Theme::instance();

        // Sample routes admins can preview so widget visibility rules
        // (beranda/artikel/halaman/arsip) can be checked without leaving admin.
        $targets = ['home' => ['label' => 'Beranda', 'url' => url()]];
        $latest = Post::query(['status' => 'published'], 1);
        if ($latest) {
            $targets['post'] = ['label' => 'Artikel terbaru', 'url' => post_url($latest[0])];
        }
        $pages = Page::all(['status' => 'published']);
        if ($pages) {
            $targets['page'] = ['label' => 'Halaman: ' . (string)$pages[0]['title'], 'url' => page_url($pages[0])];
        }
        $categories = Taxonomy::categories(1);
        if ($categories) {
            $targets['archive'] = ['label' => 'Arsip: ' . (string)$categories[0]['name'], 'url' => category_url($categories[0])];
        }

        return [
            'title' => 'Tata Letak',
            'sections' => $sections,
            'types' => Widget::types(),
            'themeAreas' => $active->areas(),
            'editId' => (int)($_GET['edit'] ?? 0),
            'previewTargets' => $targets,
        ];
    }

    private function saveWidget(): void
    {
        Auth::requirePermission('widget.manage');
        $id = (int)($_POST['id'] ?? 0);
        $type = (string)($_POST['type'] ?? 'HTML');
        if (!array_key_exists($type, Widget::types())) {
            throw new \App\Core\ValidationException('Jenis widget tidak dikenal.');
        }
        $section = (string)($_POST['section_id'] ?? 'sidebar');
        if (!array_key_exists($section, Widget::sections())) {
            throw new \App\Core\ValidationException('Area widget tidak dikenal.');
        }
        $configJson = (string)($_POST['config_json'] ?? '{}');
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            throw new \App\Core\ValidationException('Konfigurasi widget harus JSON yang valid.');
        }
        $payload = [
            'title' => trim((string)($_POST['title'] ?? '')),
            'type' => $type,
            'section_id' => $section,
            'content' => (string)($_POST['content'] ?? ''),
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'visibility' => (string)($_POST['visibility'] ?? 'all'),
            'pages' => trim((string)($_POST['pages'] ?? '')),
            'devices' => (string)($_POST['devices'] ?? 'all'),
            'roles' => trim((string)($_POST['roles'] ?? 'all')) ?: 'all',
        ];
        if ($id > 0) {
            Database::update('widgets', $payload, 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
        } else {
            $payload['blog_id'] = Context::blogId();
            $payload['widget_key'] = 'w-' . bin2hex(random_bytes(6));
            $payload['sort_order'] = (int)Database::value(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM widgets WHERE blog_id = ? AND section_id = ?',
                [Context::blogId(), $section]
            );
            Database::insert('widgets', $payload);
        }
        Widget::flush();
        log_activity('widget.save', $payload['title'] ?: $type);
    }

    private function deleteWidget(): void
    {
        Auth::requirePermission('widget.manage');
        Database::delete('widgets', 'id = ? AND blog_id = ?', [(int)($_POST['id'] ?? 0), Context::blogId()]);
        Widget::flush();
    }

    private function toggleWidget(): void
    {
        Auth::requirePermission('widget.manage');
        $id = (int)($_POST['id'] ?? 0);
        $widget = Widget::find($id);
        if (!$widget) {
            throw new \App\Core\ValidationException('Widget tidak ditemukan.');
        }
        Database::update('widgets', ['enabled' => $widget['enabled'] === 1 ? 0 : 1], 'id = ?', [$id]);
        Widget::flush();
    }

    // ------------------------------------------------------------------
    // Menus
    // ------------------------------------------------------------------
    private function menusData(): array
    {
        $menus = Menu::forBlog();
        $selected = (int)($_GET['menu'] ?? ($menus[0]['id'] ?? 0));
        $items = $selected > 0 ? Menu::items($selected) : [];
        return [
            'title' => 'Menu',
            'menus' => $menus,
            'selected' => $selected,
            'items' => $items,
            'pages' => Page::all(['status' => 'published']),
            'categories' => Taxonomy::categories(),
        ];
    }

    private function createMenu(): void
    {
        Auth::requirePermission('menu.manage');
        $id = Menu::create((string)($_POST['name'] ?? 'Menu Baru'), (string)($_POST['location'] ?? 'primary'));
        \App\Core\Response::redirect(url('admin/index.php?view=menus&menu=' . $id));
    }

    private function saveMenuItem(): void
    {
        Auth::requirePermission('menu.manage');
        $menuId = (int)($_POST['menu_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $payload = [
            'label' => (string)($_POST['label'] ?? ''),
            'url' => (string)($_POST['url'] ?? ''),
            'target' => (string)($_POST['target'] ?? '_self'),
            'parent_id' => (int)($_POST['parent_id'] ?? 0),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            Menu::updateItem($id, $payload);
        } else {
            Menu::addItem($menuId, $payload);
        }
    }

    // ------------------------------------------------------------------
    // Page builder
    // ------------------------------------------------------------------
    private function builderData(array $user): array
    {
        $pageId = (int)($_GET['page'] ?? 0);
        $page = $pageId > 0 ? Page::find($pageId) : null;
        return [
            'title' => 'Page Builder',
            'page' => $page,
            'pages' => Page::all(['status' => 'all']),
            'document' => Builder::decode($page ? (string)($page['content_json'] ?? '') : null),
            'blockTypes' => Builder::blockTypes(),
            'previewDocument' => Builder::previewDocument(
                $page ? (string)($page['content_json'] ?? '') : null,
                $page ? (string)$page['title'] : ''
            ),
        ];
    }

    private function saveBuilder(array $user): void
    {
        Auth::requirePermission('builder.manage');
        $pageId = (int)($_POST['page_id'] ?? 0);
        $json = Builder::normalize((string)($_POST['document'] ?? ''));
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \App\Core\ValidationException('Dokumen builder tidak valid.');
        }
        if ($pageId > 0) {
            $page = Page::find($pageId);
            if (!$page) {
                throw new \App\Core\ValidationException('Halaman tidak ditemukan.');
            }
            Page::save([
                'id' => $pageId,
                'title' => $page['title'],
                'body' => $page['body'],
                'template' => 'builder',
                'status' => $page['status'],
                'content_json' => $json,
            ]);
        } else {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                throw new \App\Core\ValidationException('Judul halaman wajib diisi.');
            }
            $pageId = Page::save([
                'title' => $title,
                'body' => '',
                'template' => 'builder',
                'status' => 'published',
                'content_json' => $json,
                'author_id' => (int)$user['id'],
            ]);
        }
        log_activity('builder.save', 'Halaman #' . $pageId);
        \App\Core\Response::redirect(url('admin/index.php?view=builder&page=' . $pageId));
    }

    // ------------------------------------------------------------------
    // Blogs & users
    // ------------------------------------------------------------------
    private function userBlogs(array $user): array
    {
        $blogs = Blog::forUser((int)$user['id']);
        return $blogs ?: [Context::blog()];
    }

    private function createBlog(array $user): void
    {
        Auth::requirePermission('blog.manage');
        $id = Blog::create([
            'name' => (string)($_POST['name'] ?? ''),
            'slug' => (string)($_POST['slug'] ?? ''),
            'domain' => (string)($_POST['domain'] ?? ''),
            'description' => (string)($_POST['description'] ?? ''),
            'theme' => (string)($_POST['theme'] ?? Theme::instance()->slug()),
            'language' => (string)($_POST['language'] ?? 'id'),
        ], (int)$user['id']);
        log_activity('blog.create', 'Blog #' . $id);
        \App\Core\Response::redirect(url('admin/index.php?view=blogs'));
    }

    private function switchBlog(array $user): void
    {
        $blogId = (int)($_POST['blog_id'] ?? 0);
        if (!Auth::switchBlog($blogId)) {
            throw new \App\Core\SecurityException('Anda tidak memiliki akses ke blog ini.', 403);
        }
        \App\Core\Response::redirect(url('admin/index.php?view=dashboard'));
    }

    private function updateBlog(): void
    {
        Auth::requirePermission('blog.manage');
        Blog::update(Context::blogId(), [
            'name' => (string)($_POST['name'] ?? ''),
            'description' => (string)($_POST['description'] ?? ''),
            'logo' => (string)($_POST['logo'] ?? ''),
            'favicon' => (string)($_POST['favicon'] ?? ''),
            'language' => (string)($_POST['language'] ?? 'id'),
            'timezone' => (string)($_POST['timezone'] ?? 'Asia/Jakarta'),
            'custom_domain' => (string)($_POST['custom_domain'] ?? ''),
        ]);
    }

    private function usersData(array $user): array
    {
        $rows = Database::fetchAll(
            'SELECT u.*, bu.role AS blog_role, (SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id AND p.blog_id = ?) AS post_count
             FROM users u LEFT JOIN blog_users bu ON bu.user_id = u.id AND bu.blog_id = ?
             ORDER BY u.role, u.username',
            [Context::blogId(), Context::blogId()]
        );
        return [
            'title' => 'Pengguna',
            'users' => $rows,
            'current' => $user,
            'members' => Database::fetchAll(
                'SELECT bu.*, u.username FROM blog_users bu JOIN users u ON u.id = bu.user_id WHERE bu.blog_id = ?',
                [Context::blogId()]
            ),
            'roles' => Auth::ROLES,
        ];
    }

    private function saveUser(array $user): void
    {
        Auth::requirePermission('user.manage');
        $id = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'author');
        if (!in_array($role, Auth::ROLES, true)) {
            throw new \App\Core\ValidationException('Role tidak dikenal.');
        }
        if ($id > 0) {
            $payload = [
                'display_name' => trim((string)($_POST['display_name'] ?? '')),
                'role' => $role,
                'bio' => (string)($_POST['bio'] ?? ''),
                'status' => (string)($_POST['status'] ?? 'active'),
            ];
            if ($password !== '') {
                $payload['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            Database::update('users', $payload, 'id = ?', [$id]);
        } else {
            if ($username === '' || $email === '' || $password === '') {
                throw new \App\Core\ValidationException('Username, email, dan password wajib diisi.');
            }
            if (Database::fetch('SELECT id FROM users WHERE username = ? OR email = ?', [$username, $email])) {
                throw new \App\Core\ValidationException('Username atau email sudah dipakai.');
            }
            $id = Database::insert('users', [
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => trim((string)($_POST['display_name'] ?? '')) ?: $username,
                'role' => $role,
            ]);
            Database::insert('blog_users', [
                'blog_id' => Context::blogId(),
                'user_id' => $id,
                'role' => 'author',
            ]);
        }
        $blogRole = (string)($_POST['blog_role'] ?? '');
        if ($blogRole !== '' && in_array($blogRole, ['owner', 'admin', 'editor', 'author', 'contributor'], true)) {
            Database::run(
                'INSERT INTO blog_users (blog_id, user_id, role, is_owner) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE role = VALUES(role), is_owner = VALUES(is_owner)',
                [Context::blogId(), $id, $blogRole, $blogRole === 'owner' ? 1 : 0]
            );
        }
        log_activity('user.save', $username ?: ('#' . $id));
    }

    private function deleteUser(array $user): void
    {
        Auth::requirePermission('user.manage');
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$user['id']) {
            throw new \App\Core\ValidationException('Akun Anda sendiri tidak dapat dihapus.');
        }
        if ((int)(Database::value('SELECT COUNT(*) FROM posts WHERE author_id = ?', [$id]) ?: 0) > 0) {
            throw new \App\Core\ValidationException('Pengguna masih memiliki postingan. Pindahkan atau hapus postingan terlebih dahulu.');
        }
        Database::delete('users', 'id = ?', [$id]);
    }

    private function saveProfile(array $user): void
    {
        $payload = [
            'display_name' => trim((string)($_POST['display_name'] ?? '')),
            'bio' => (string)($_POST['bio'] ?? ''),
            'website' => (string)($_POST['website'] ?? ''),
        ];
        if ((string)($_POST['password'] ?? '') !== '') {
            $payload['password_hash'] = password_hash((string)$_POST['password'], PASSWORD_DEFAULT);
        }
        Database::update('users', $payload, 'id = ?', [(int)$user['id']]);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Profil diperbarui.'];
    }

    // ------------------------------------------------------------------
    // SEO, settings, backups, newsletter
    // ------------------------------------------------------------------
    private function deleteRedirect(): void
    {
        Database::delete('redirects', 'id = ? AND blog_id = ?', [(int)($_POST['id'] ?? 0), Context::blogId()]);
    }

    private function saveSettings(): void
    {
        Auth::requirePermission('settings.manage');
        $allowed = array_keys(Settings::defaults());
        $extra = ['robots_txt', 'meta_title_suffix', 'tagline', 'footer_links', 'header_links', 'social_facebook', 'social_instagram', 'social_youtube', 'social_twitter', 'social_linkedin', 'email_from', 'email_name', 'comments_closed_message'];
        $values = [];
        foreach (array_merge($allowed, $extra) as $key) {
            if (array_key_exists($key, $_POST)) {
                $values[$key] = is_array($_POST[$key]) ? implode(',', $_POST[$key]) : (string)$_POST[$key];
            }
        }
        foreach (['maintenance_mode', 'allow_comments', 'comment_moderation', 'media_webp', 'sitemap_enabled', 'registration_enabled'] as $checkbox) {
            $values[$checkbox] = isset($_POST[$checkbox]) ? '1' : '0';
        }
        if (isset($values['site_url'])) {
            $values['site_url'] = rtrim($values['site_url'], '/') . '/';
            if (!valid_site_url($values['site_url'])) {
                throw new \App\Core\ValidationException('Site URL harus URL HTTP(S) yang valid tanpa query.');
            }
        }
        Settings::setMany($values);
        Cache::flushGroup('settings');
        Cache::flushGroup('theme');
        log_activity('settings.save', 'Pengaturan situs diperbarui');
    }

    private function createBackup(): void
    {
        $payload = Backup::create();
        $file = Backup::writeFile($payload);
        log_activity('backup.create', basename($file));
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Backup dibuat: ' . basename($file)];
    }

    private function deleteBackup(): void
    {
        $name = basename((string)($_POST['file'] ?? ''));
        $path = CMS_ROOT . '/storage/backups/' . $name;
        if ($name === '' || !preg_match('/^blog-backup-[a-z0-9_.-]+\.json$/i', $name) || !is_file($path)) {
            throw new \App\Core\ValidationException('Berkas backup tidak ditemukan.');
        }
        @unlink($path);
    }

    private function restoreBackup(): void
    {
        $name = basename((string)($_POST['file'] ?? ''));
        $path = CMS_ROOT . '/storage/backups/' . $name;
        if (!is_file($path)) {
            throw new \App\Core\ValidationException('Berkas backup tidak ditemukan.');
        }
        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new \App\Core\ValidationException('Berkas backup rusak.');
        }
        $result = Backup::restore($payload, !empty($_POST['wipe']));
        log_activity('backup.restore', $name);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Restore selesai: ' . $result['posts'] . ' artikel, ' . $result['pages'] . ' halaman.'];
    }

    private function deleteSubscriber(): void
    {
        Database::delete('newsletter_subscribers', 'id = ? AND blog_id = ?', [(int)($_POST['id'] ?? 0), Context::blogId()]);
    }

    private function activityEntries(): array
    {
        return Database::fetchAll(
            'SELECT a.*, u.username FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.blog_id = ? ORDER BY a.id DESC LIMIT 200',
            [Context::blogId()]
        );
    }

    // ------------------------------------------------------------------
    // JSON endpoints
    // ------------------------------------------------------------------
    private function ajax(string $endpoint): string
    {
        if (!Auth::check()) {
            return \App\Core\Response::error('Login diperlukan.', 401);
        }
        $user = Auth::require();
        $this->prepareContext($user);
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                Csrf::verify((string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
            }
            return match ($endpoint) {
                'widget.reorder' => $this->ajaxReorderWidgets(),
                'post.autosave' => $this->ajaxAutosave(),
                'media.list' => $this->ajaxMediaList(),
                'media.upload' => $this->ajaxMediaUpload($user),
                'builder.preview' => $this->ajaxBuilderPreview(),
                'seo.audit' => \App\Core\Response::json(['ok' => true, 'audit' => Seo::audit()]),
                'search.posts' => $this->ajaxSearchPosts(),
                'slug.check' => $this->ajaxSlugCheck(),
                default => \App\Core\Response::error('Endpoint tidak dikenal.', 404),
            };
        } catch (\Throwable $exception) {
            Logger::warning('admin ajax failed', ['endpoint' => $endpoint, 'error' => $exception->getMessage()]);
            return \App\Core\Response::error($exception->getMessage(), 400);
        }
    }

    private function ajaxReorderWidgets(): string
    {
        Auth::requirePermission('widget.manage');
        $payload = json_decode((string)($_POST['payload'] ?? file_get_contents('php://input') ?: ''), true);
        if (!is_array($payload)) {
            return \App\Core\Response::error('Payload tidak valid.');
        }
        $positions = [];
        foreach ($payload as $row) {
            $positions[] = [
                'id' => (int)($row['id'] ?? 0),
                'section' => (string)($row['section'] ?? ''),
                'order' => (int)($row['order'] ?? 0),
            ];
        }
        Widget::reorder($positions);
        return \App\Core\Response::json(['ok' => true, 'updated' => count($positions)]);
    }

    private function ajaxAutosave(): string
    {
        Auth::requirePermission('post.create');
        $id = (int)($_POST['id'] ?? 0);
        $payload = [
            'title' => (string)($_POST['title'] ?? ''),
            'body' => (string)($_POST['body'] ?? ''),
            'excerpt' => (string)($_POST['excerpt'] ?? ''),
            'status' => 'draft',
        ];
        if ($id > 0) {
            $existing = Post::find($id);
            if (!$existing) {
                return \App\Core\Response::error('Postingan tidak ditemukan.', 404);
            }
            Database::update('posts', [
                'title' => $payload['title'] !== '' ? $payload['title'] : $existing['title'],
                'body' => $payload['body'],
                'excerpt' => $payload['excerpt'] !== '' ? $payload['excerpt'] : $existing['excerpt'],
                'reading_time' => Str::readingTime($payload['body']),
            ], 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
            return \App\Core\Response::json(['ok' => true, 'id' => $id, 'savedAt' => gmdate('H:i')]);
        }
        $authored = (int)($_SESSION['user_id'] ?? 0) ?: 1;
        $created = Post::save($payload + ['author_id' => $authored]);
        return \App\Core\Response::json(['ok' => true, 'id' => $created, 'savedAt' => gmdate('H:i')]);
    }

    private function ajaxMediaList(): string
    {
        $items = Media::all(['type' => (string)($_GET['type'] ?? 'all'), 'search' => (string)($_GET['q'] ?? '')], 120);
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'id' => (int)$item['id'],
                'url' => Media::publicUrl($item, false),
                'thumb' => url((string)($item['thumb_path'] ?: $item['file_path'])),
                'name' => (string)$item['file_name'],
                'alt' => (string)($item['alt'] ?? ''),
                'width' => (int)($item['width'] ?? 0),
                'height' => (int)($item['height'] ?? 0),
            ];
        }
        return \App\Core\Response::json(['ok' => true, 'items' => $out]);
    }

    private function ajaxMediaUpload(array $user): string
    {
        Auth::requirePermission('media.upload');
        $media = Media::upload($_FILES['file'] ?? [], (int)$user['id'], ['alt' => $_POST['alt'] ?? '']);
        return \App\Core\Response::json([
            'ok' => true,
            'item' => [
                'id' => (int)$media['id'],
                'url' => Media::publicUrl($media, false),
                'thumb' => url((string)($media['thumb_path'] ?: $media['file_path'])),
            ],
        ]);
    }

    private function ajaxBuilderPreview(): string
    {
        Auth::requirePermission('builder.manage');
        $json = (string)($_POST['document'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        return \App\Core\Response::json([
            'ok' => true,
            'html' => Builder::previewDocument($json, $title),
        ]);
    }

    private function ajaxSearchPosts(): string
    {
        $term = trim((string)($_GET['q'] ?? ''));
        if ($term === '') {
            return \App\Core\Response::json(['ok' => true, 'items' => []]);
        }
        $posts = Post::query(['status' => 'all', 'search' => $term, 'include_future' => true], 12);
        $items = [];
        foreach ($posts as $post) {
            $items[] = ['id' => (int)$post['id'], 'title' => (string)$post['title'], 'status' => (string)$post['status'], 'editUrl' => url('admin/index.php?view=post-edit&id=' . (int)$post['id'])];
        }
        return \App\Core\Response::json(['ok' => true, 'items' => $items]);
    }

    private function ajaxSlugCheck(): string
    {
        $table = (string)($_GET['table'] ?? 'posts');
        if (!in_array($table, ['posts', 'pages'], true)) {
            return \App\Core\Response::error('Tabel tidak valid.');
        }
        $slug = Slug::make((string)($_GET['slug'] ?? ''));
        $exclude = (int)($_GET['exclude'] ?? 0);
        $exists = (int)Database::value(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE blog_id = ? AND slug = ? AND id <> ?',
            [Context::blogId(), $slug, $exclude]
        ) > 0;
        return \App\Core\Response::json(['ok' => true, 'slug' => $slug, 'available' => !$exists]);
    }
}
