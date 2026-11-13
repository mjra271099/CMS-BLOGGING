<?php
declare(strict_types=1);

/**
 * User and role management for the active blog.
 *
 * @var array $users
 * @var array $current
 * @var array $members
 * @var array $roles
 */

use App\Http\AdminUi;

$users = $users ?? [];
$current = $current ?? [];
$members = $members ?? [];
$roles = $roles ?? [];
$adminBase = url('admin/index.php');
?>
<div class="toolbar">
    <h1>Pengguna</h1>
    <span class="muted"><?= count($users) ?> akun terdaftar</span>
</div>

<div class="card">
    <h2>Anggota Blog Ini</h2>
    <?php if (!$members): ?>
        <p class="muted">Belum ada anggota terdaftar pada blog ini.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Pengguna</th><th>Peran blog</th><th>Pemilik</th></tr></thead>
            <tbody>
            <?php foreach ($members as $member): ?>
                <tr>
                    <td><strong><?= e((string)$member['username']) ?></strong></td>
                    <td><span class="pill info"><?= e((string)$member['role']) ?></span></td>
                    <td><?= !empty($member['is_owner']) ? '✓' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Semua Pengguna</h2>
    <?php if (!$users): ?>
        <p class="muted">Belum ada pengguna.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Pengguna</th><th>Email</th><th>Peran</th><th>Artikel</th><th>Status</th><th>Login terakhir</th><th style="width:90px"></th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <strong><?= e((string)($user['display_name'] ?: $user['username'])) ?></strong>
                        <div class="muted">@<?= e((string)$user['username']) ?><?= (int)$user['id'] === (int)($current['id'] ?? 0) ? ' · Anda' : '' ?></div>
                    </td>
                    <td class="muted"><?= e((string)$user['email']) ?></td>
                    <td><span class="pill"><?= e((string)$user['role']) ?></span></td>
                    <td><?= (int)($user['post_count'] ?? 0) ?></td>
                    <td><?= AdminUi::pill((string)$user['status']) ?></td>
                    <td class="muted"><?= e(AdminUi::dateShort((string)($user['last_login_at'] ?? ''))) ?></td>
                    <td>
                        <?php if ((int)$user['id'] !== (int)($current['id'] ?? 0)): ?>
                            <form method="post" action="<?= e($adminBase . '?view=users') ?>" class="inline">
                                <input type="hidden" name="action" value="user.delete">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="danger small" onclick="return confirm('Hapus pengguna ini?')">Hapus</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Tambah Pengguna</h2>
    <form method="post" action="<?= e($adminBase . '?view=users') ?>">
        <input type="hidden" name="action" value="user.save">
        <input type="hidden" name="id" value="0">
        <?= \App\Core\Csrf::field() ?>
        <div class="row">
            <div>
                <label for="username">Username</label>
                <input id="username" type="text" name="username" required>
            </div>
            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" required>
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>
            </div>
        </div>
        <div class="row">
            <div>
                <label for="display_name">Nama tampilan</label>
                <input id="display_name" type="text" name="display_name">
            </div>
            <div>
                <label for="role">Peran global</label>
                <select id="role" name="role">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>"<?= $role === 'author' ? ' selected' : '' ?>><?= e($role) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="blog_role">Peran di blog ini</label>
                <select id="blog_role" name="blog_role">
                    <?php foreach (['author', 'editor', 'admin', 'contributor', 'owner'] as $role): ?>
                        <option value="<?= e($role) ?>"<?= $role === 'author' ? ' selected' : '' ?>><?= e($role) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" style="margin-top:16px">Tambah Pengguna</button>
    </form>
</div>

<div class="card">
    <h2>Matriks Izin</h2>
    <table>
        <thead><tr><th>Peran</th><th>Kemampuan</th></tr></thead>
        <tbody>
        <tr><td><strong>admin</strong></td><td>Semua aksi, termasuk pengaturan, pengguna, tema, dan backup.</td></tr>
        <tr><td><strong>editor</strong></td><td>Kelola semua konten, media, kategori, tampilan, SEO, dan pengguna.</td></tr>
        <tr><td><strong>author</strong></td><td>Buat/edit artikel dan halaman miliknya, unggah media.</td></tr>
        <tr><td><strong>contributor</strong></td><td>Buat draf artikel dan unggah media; publikasi oleh editor.</td></tr>
        </tbody>
    </table>
</div>
