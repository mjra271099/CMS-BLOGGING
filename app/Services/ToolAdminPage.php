<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Settings;
use App\Core\View;
use App\Models\Media;

/**
 * Halaman Admin → Tools: satu tempat untuk mengelola CV Maker, QR Code
 * Generator, dan halaman penjualan CMS (termasuk monetisasi per tools).
 *
 * Berdiri sendiri seperti `tmdb-generator.php` supaya tidak menyentuh
 * `AdminController`; memakai shell admin yang sama agar tampilannya identik.
 * Semua data disimpan lewat {@see ToolConfig} (tabel `settings`).
 */
final class ToolAdminPage
{
    private const SELF = 'admin/tools.php';

    public function run(): string
    {
        if (!Auth::check()) {
            \App\Core\Response::redirect(url('admin/index.php?view=login&next=' . urlencode(url(self::SELF))));
        }
        $user = Auth::require();
        if (!empty($_SESSION['active_blog_id'])) {
            Context::forceBlog((int)$_SESSION['active_blog_id']);
        }
        $flash = \App\Core\Container::get(\App\Core\Flash::class);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                Auth::requirePermission('settings.manage');
                Csrf::verify();
                $this->handlePost($flash, (int)($user['id'] ?? 0));
            } catch (\Throwable $exception) {
                $flash->error($exception->getMessage());
            }
            $returnUrl = (string)($_POST['_redirect'] ?? '');
            \App\Core\Response::redirect(preg_match('~^admin/tools\.php~', $returnUrl) === 1 ? url($returnUrl) : url(self::SELF));
        }

        $data = [
            'title' => 'Tools & Landing',
            'values' => ToolConfig::all(),
            'qris' => ToolConfig::qrisImage(),
            'customQris' => ToolConfig::get('tool_qr_qris_image'),
            'flash' => $flash->pull(),
            'user' => $user,
            'nav' => \App\Http\AdminController::navigation(),
            'blogs' => [],
            'activeBlog' => Context::blog(),
            'commentsPending' => 0,
            'siteName' => Settings::get('site_name', 'CMS'),
            'activeKey' => 'tools',
            'selfUrl' => url(self::SELF),
            'demos' => [
                'cv' => url('cv-maker'),
                'qr' => url('qr-code-generator'),
                'sales' => url('jual-script-cms'),
            ],
        ];
        try {
            $data['commentsPending'] = \App\Models\Comment::counts()['pending'] ?? 0;
            $data['blogs'] = \App\Models\Blog::forUser((int)$user['id']) ?: [Context::blog()];
        } catch (\Throwable) {
        }

        $content = View::render('admin/tools', $data);
        return View::render('admin/tmdb-shell', $data + ['content' => $content]);
    }

    private function handlePost(\App\Core\Flash $flash, int $userId): void
    {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'tools.save') {
            ToolConfig::save((array)($_POST['cfg'] ?? []));
            $flash->success('Pengaturan tools dan landing disimpan.');
            return;
        }
        if ($action === 'tools.qris') {
            $this->replaceQris($userId);
            $flash->success('Gambar QRIS diperbarui.');
            return;
        }
        if ($action === 'tools.qris.reset') {
            Settings::set('tool_qr_qris_image', '');
            $flash->success('Gambar QRIS kembali ke bawaan (QR-DANA.jpeg).');
            return;
        }
        if ($action === 'tools.accept-order') {
            $this->acceptOrder((int)($_POST['id'] ?? 0), $userId);
            $flash->success('Pesanan ditandai lunas.');
        }
    }

    /**
     * QRIS diunggah lewat Media library (validasi MIME/ukuran yang sudah ada),
     * lalu diberi nama stabil sehingga bisa diganti tanpa menyentuh record lain.
     */
    private function replaceQris(int $userId): void
    {
        $file = $_FILES['qris'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \App\Core\ValidationException('Pilih file gambar QRIS terlebih dahulu.');
        }
        $media = Media::upload($file, $userId, ['alt' => 'QRIS dukungan', 'folder' => 'tools']);
        if (empty($media['file_path'])) {
            throw new \RuntimeException('Gambar QRIS gagal disimpan.');
        }
        Settings::set('tool_qr_qris_image', (string)$media['file_path']);
        Settings::set('tool_qr_qris_enabled', '1');
    }

    private function acceptOrder(int $id, int $userId): void
    {
        $order = Database::fetch('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$order) {
            throw new \App\Core\ValidationException('Pesanan tidak ditemukan.');
        }
        Database::update('orders', [
            'status' => 'paid',
            'paid_at' => gmdate('Y-m-d H:i:s'),
            'verified_by' => $userId,
        ], 'id = ?', [$id]);
        log_activity('order.paid', 'ID ' . $id);
    }
}
