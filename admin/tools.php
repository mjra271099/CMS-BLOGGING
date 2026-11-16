<?php
declare(strict_types=1);

/**
 * Halaman Admin → Tools: pengelolaan CV Maker, QR Code Generator, QRIS, dan
 * halaman penjualan CMS. Halaman mandiri seperti tmdb-generator.php agar
 * tidak perlu menyentuh AdminController.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$page = new \App\Services\ToolAdminPage();
echo $page->run();
