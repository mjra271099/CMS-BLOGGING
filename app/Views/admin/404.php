<?php
declare(strict_types=1);

/**
 * Admin 404 — unknown admin screen.
 */

use App\Http\AdminUi;

$adminBase = url('admin/index.php');
?>
<div class="card">
    <div class="empty-state">
        <div class="es-icon"><?= AdminUi::icon('search', 40) ?></div>
        <h2>Halaman tidak ditemukan</h2>
        <p>Menu yang Anda tuju tidak tersedia.</p>
        <a class="btn" style="margin-top:14px" href="<?= e($adminBase . '?view=dashboard') ?>">Kembali ke Ringkasan</a>
    </div>
</div>
