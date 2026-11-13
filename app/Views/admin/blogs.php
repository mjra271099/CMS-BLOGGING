<?php
declare(strict_types=1);

/**
 * Multi-blog management: blog identity, domains, switching and creation.
 *
 * @var array $blogs
 * @var array $activeBlog
 * @var array $domains
 */

use App\Http\AdminUi;

$blogs = $blogs ?? [];
$activeBlog = $activeBlog ?? [];
$domains = $domains ?? [];
$adminBase = url('admin/index.php');
?>
<div class="toolbar">
    <h1>Blog</h1>
    <span class="muted"><?= count($blogs) ?> blog dalam akun ini</span>
</div>

<div class="dashboard-cols">
    <div class="card">
        <h2>Identitas Blog Aktif</h2>
        <form method="post" action="<?= e($adminBase . '?view=blogs') ?>">
            <input type="hidden" name="action" value="blog.update">
            <?= \App\Core\Csrf::field() ?>
            <label for="name">Nama blog</label>
            <input id="name" type="text" name="name" value="<?= e((string)($activeBlog['name'] ?? '')) ?>" required>
            <label for="description">Deskripsi</label>
            <textarea id="description" name="description" rows="3"><?= e((string)($activeBlog['description'] ?? '')) ?></textarea>
            <div class="row">
                <div>
                    <label for="language">Bahasa</label>
                    <select id="language" name="language">
                        <?php foreach (['id' => 'Indonesia', 'en' => 'English', 'ms' => 'Bahasa Melayu'] as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= (string)($activeBlog['language'] ?? 'id') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="timezone">Zona waktu</label>
                    <input id="timezone" type="text" name="timezone" value="<?= e((string)($activeBlog['timezone'] ?? 'Asia/Jakarta')) ?>">
                </div>
            </div>
            <label for="custom_domain">Domain kustom</label>
            <input id="custom_domain" type="text" name="custom_domain" value="<?= e((string)($activeBlog['custom_domain'] ?? '')) ?>" placeholder="blog.example.com">
            <label for="logo">Logo</label>
            <input id="logo" type="text" name="logo" value="<?= e((string)($activeBlog['logo'] ?? '')) ?>">
            <label for="favicon">Favicon</label>
            <input id="favicon" type="text" name="favicon" value="<?= e((string)($activeBlog['favicon'] ?? '')) ?>">
            <button type="submit" style="margin-top:16px">Simpan Identitas</button>
        </form>
    </div>

    <div class="card">
        <h2>Blog Saya</h2>
        <ul class="blog-list">
            <?php foreach ($blogs as $blog): ?>
                <li class="<?= (int)($activeBlog['id'] ?? 0) === (int)$blog['id'] ? 'active' : '' ?>">
                    <div>
                        <strong><?= e((string)$blog['name']) ?></strong>
                        <span class="muted"><?= e((string)($blog['domain'] ?: $blog['slug'])) ?></span>
                    </div>
                    <?php if ((int)($activeBlog['id'] ?? 0) === (int)$blog['id']): ?>
                        <span class="pill success">Sedang aktif</span>
                    <?php else: ?>
                        <form method="post" action="<?= e($adminBase . '?view=dashboard') ?>" class="inline">
                            <input type="hidden" name="action" value="blog.switch">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="blog_id" value="<?= (int)$blog['id'] ?>">
                            <button type="submit" class="secondary small">Pindah</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <h3 style="margin-top:26px">Domain Terdaftar</h3>
        <?php if (!$domains): ?>
            <p class="muted">Belum ada domain tambahan untuk blog aktif.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Domain</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($domains as $domain): ?>
                    <tr>
                        <td><?= e((string)$domain['domain']) ?></td>
                        <td>
                            <?php if (!empty($domain['verified_at'])): ?>
                                <span class="pill success">Terverifikasi</span>
                            <?php else: ?>
                                <span class="pill warning">Belum</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-actions">
                                <?php if (empty($domain['verified_at'])): ?>
                                    <form method="post" action="<?= e($adminBase . '?view=blogs') ?>" class="inline">
                                        <input type="hidden" name="action" value="domain.verify">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$domain['id'] ?>">
                                        <button type="submit" class="secondary small">Verifikasi</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e($adminBase . '?view=blogs') ?>" class="inline">
                                    <input type="hidden" name="action" value="domain.remove">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$domain['id'] ?>">
                                    <button type="submit" class="danger small">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" action="<?= e($adminBase . '?view=blogs') ?>" style="margin-top:14px">
            <input type="hidden" name="action" value="domain.add">
            <?= \App\Core\Csrf::field() ?>
            <div class="row">
                <input type="text" name="domain" placeholder="blog.example.com" required>
                <button type="submit" class="secondary">Tambah Domain</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <h2>Buat Blog Baru</h2>
    <form method="post" action="<?= e($adminBase . '?view=blogs') ?>">
        <input type="hidden" name="action" value="blog.create">
        <?= \App\Core\Csrf::field() ?>
        <div class="row">
            <div>
                <label for="new-name">Nama blog</label>
                <input id="new-name" type="text" name="name" required>
            </div>
            <div>
                <label for="new-slug">Slug</label>
                <input id="new-slug" type="text" name="slug" placeholder="otomatis">
            </div>
            <div>
                <label for="new-domain">Domain</label>
                <input id="new-domain" type="text" name="domain" placeholder="opsional">
            </div>
        </div>
        <div class="row">
            <div>
                <label for="new-theme">Tema awal</label>
                <select id="new-theme" name="theme">
                    <?php foreach (\App\Core\Theme::catalog() as $theme): ?>
                        <option value="<?= e((string)$theme['slug']) ?>"><?= e((string)$theme['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="new-language">Bahasa</label>
                <select id="new-language" name="language">
                    <option value="id">Indonesia</option>
                    <option value="en">English</option>
                </select>
            </div>
        </div>
        <label for="new-description">Deskripsi</label>
        <textarea id="new-description" name="description" rows="2"></textarea>
        <button type="submit" style="margin-top:16px">Buat Blog</button>
    </form>
</div>
