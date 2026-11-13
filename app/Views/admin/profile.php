<?php
declare(strict_types=1);

/**
 * Current user's profile: display details, bio, website and password.
 *
 * @var array $user
 */

use App\Http\AdminUi;

$user = $user ?? [];
$adminBase = url('admin/index.php');
$role = (string)($user['role'] ?? 'author');
?>
<div class="toolbar">
    <h1>Profil Saya</h1>
    <span class="pill info"><?= e($role) ?></span>
</div>

<div class="dashboard-cols">
    <div class="card">
        <h2>Informasi Akun</h2>
        <form method="post" action="<?= e($adminBase . '?view=profile') ?>">
            <input type="hidden" name="action" value="profile.save">
            <?= \App\Core\Csrf::field() ?>
            <label for="display_name">Nama tampilan</label>
            <input id="display_name" type="text" name="display_name" value="<?= e((string)($user['display_name'] ?? '')) ?>">
            <label for="bio">Bio</label>
            <textarea id="bio" name="bio" rows="4"><?= e((string)($user['bio'] ?? '')) ?></textarea>
            <label for="website">Situs web</label>
            <input id="website" type="url" name="website" value="<?= e((string)($user['website'] ?? '')) ?>">
            <label for="password">Password baru</label>
            <input id="password" type="password" name="password" placeholder="Kosongkan bila tidak diubah" autocomplete="new-password">
            <p class="help">Minimal 8 karakter. Password lama tidak dapat dipulihkan setelah diubah.</p>
            <button type="submit" style="margin-top:14px">Simpan Profil</button>
        </form>
    </div>

    <div class="card">
        <h2>Akun</h2>
        <div class="profile-card">
            <div class="avatar avatar-lg"><?= e(AdminUi::initials((string)($user['display_name'] ?? $user['username'] ?? ''))) ?></div>
            <div>
                <strong><?= e((string)($user['display_name'] ?? $user['username'] ?? '')) ?></strong>
                <div class="muted">@<?= e((string)($user['username'] ?? '')) ?></div>
                <div class="muted"><?= e((string)($user['email'] ?? '')) ?></div>
            </div>
        </div>
        <p class="help">Bergabung <?= e(AdminUi::dateShort((string)($user['created_at'] ?? ''))) ?>.</p>

        <h3 style="margin-top:24px">Peran &amp; Izin</h3>
        <ul class="help" style="padding-left:18px">
            <li>Peran global: <strong><?= e($role) ?></strong></li>
            <li>Peran di blog aktif ditentukan oleh keanggotaan blog.</li>
            <li>Hubungi administrator untuk perubahan peran.</li>
        </ul>

        <h3 style="margin-top:24px">Sesi</h3>
        <form method="post" action="<?= e($adminBase . '?view=dashboard') ?>">
            <input type="hidden" name="action" value="logout">
            <?= \App\Core\Csrf::field() ?>
            <button type="submit" class="secondary">Keluar dari sesi ini</button>
        </form>
    </div>
</div>
