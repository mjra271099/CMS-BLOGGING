<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;

/**
 * Halaman penjualan script CMS.
 *
 * Satu halaman mandiri (layout sendiri, tanpa navbar/footer blog) yang seluruh
 * isinya — headline, harga, fitur, FAQ, kontak, screenshot, CTA — diatur dari
 * Admin → Tools & Landing (App\Services\ToolConfig).
 *
 * Zona: `sales.header` (judul/logo/kontak) dan `sales.slot` (tambahan bebas).
 */
final class SalesPage
{
    public function run(): string
    {
        Response::securityHeaders();

        if (!ToolConfig::bool('price_enabled')) {
            http_response_code(404);
            return '<!doctype html><meta charset="utf-8"><title>404</title><p style="font:16px system-ui;padding:40px">Halaman penjualan sedang tidak aktif.</p>';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->handlePost();
        }

        $cfg = ToolConfig::all();
        $features = $this->pairs($cfg['price_features']) ?: $this->defaultFeatures();
        $faq = $this->pairs($cfg['price_faq']) ?: $this->defaultFaq();
        $testimonials = $this->triples($cfg['price_testimonials']);

        $siteName = Settings::get('site_name', 'CMS');
        $home = url();
        $demo = $cfg['price_demo_url'] !== '' ? $cfg['price_demo_url'] : $home;
        $buy = $cfg['price_buy_url'] !== '' ? $cfg['price_buy_url'] : '#pesan';
        $contact = $cfg['price_contact_url'];

        $page = [
            'slug' => 'landing-sales',
            'home' => $home,
            'lang' => Lang::htmlLocale(),
            'config' => '',
            'bodyClass' => 'sales-standalone',
            'head' => '',
            'scripts' => '',
        ];
        return $this->shell(ToolConfig::get('price_headline', 'Jual Script CMS'), $page, View::render('sales', [
            'cfg' => $cfg,
            'features' => $features,
            'faq' => $faq,
            'testimonials' => $testimonials,
            'siteName' => $siteName,
            'home' => $home,
            'demo' => $demo,
            'buy' => $buy,
            'contact' => $contact,
            'shots' => [
                'dashboard' => $this->mediaUrl($cfg['price_shot_dashboard']),
                'frontend' => $this->mediaUrl($cfg['price_shot_frontend']),
            ],
            'tech' => $this->lines($cfg['price_tech']),
            'flash' => $this->pullFlash(),
            'csrf' => Csrf::field(),
            'orderAmount' => (int)preg_replace('/\D+/', '', $cfg['price_amount']),
        ]));
    }

    private function handlePost(): string
    {
        Response::securityHeaders();
        $flash = [];
        try {
            Csrf::verify();
            // Honeypot anti-spam sederhana.
            if (trim((string)($_POST['url_extra'] ?? '')) !== '') {
                throw new \App\Core\ValidationException('Permintaan ditolak.');
            }
            $id = SalesOrder::create([
                'name' => $_POST['name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'whatsapp' => $_POST['whatsapp'] ?? '',
                'package' => $_POST['package'] ?? 'CMS Script',
                'note' => $_POST['note'] ?? '',
                'amount' => (int)($_POST['amount'] ?? 0),
            ]);
            log_activity('order.create', 'ID ' . $id);
            $flash = ['type' => 'success', 'message' => 'Pesanan diterima. Kami akan mengirim instruksi pembayaran ke email Anda.'];
        } catch (\Throwable $exception) {
            $flash = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        $_SESSION['sales_flash'] = $flash;
        Response::redirect(url('jual-script-cms#pesan'));
        return '';
    }

    /** @return array<string, string>|null */
    private function pullFlash(): ?array
    {
        $flash = $_SESSION['sales_flash'] ?? null;
        unset($_SESSION['sales_flash']);
        return is_array($flash) ? $flash : null;
    }

    /**
     * "Judul | Deskripsi" per baris → daftar pasangan.
     *
     * @return array<int, array{0:string,1:string}>
     */
    private function pairs(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $out[] = [$parts[0], $parts[1] ?? ''];
        }
        return $out;
    }

    /** @return array<int, string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R|,/', $text) ?: [])));
    }

    /**
     * "Nama | Peran | Kutipan" per baris → daftar triple.
     *
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function triples(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 3));
            if (($parts[0] ?? '') === '' && ($parts[2] ?? '') === '') {
                continue;
            }
            $out[] = [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
        }
        return $out;
    }

    private function mediaUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        return preg_match('~^(https?:)?//~i', $path) === 1 ? $path : url(ltrim($path, '/'));
    }

    /** @return array<int, array{0:string,1:string}> */
    private function defaultFeatures(): array
    {
        return [
            ['Multi-blog & multi-user', 'Kelola beberapa blog dari satu dashboard; peran owner, admin, editor, author, contributor.'],
            ['Editor + Page Builder', 'Editor kaya dengan autosave, revisi, dan 18 blok builder: hero, galeri, CTA, FAQ, testimoni, newsletter.'],
            ['Tema & kustomizer', 'Dua tema bawaan (AMP & Schema) plus kustomizer warna, tipografi, radius, dan widget per area.'],
            ['SEO lengkap', 'Meta, canonical, Open Graph, sitemap, robots, redirect manager, dan 25 jenis Structured Data Google.'],
            ['Widget engine', '20+ tipe widget dengan visibilitas per halaman, perangkat, dan peran pengguna.'],
            ['Tools bawaan', 'CV Maker, QR Code Generator, dan halaman penjualan — semuanya bisa diatur dari dashboard.'],
            ['Media library', 'Unggah, potong, kompres, dan konversi WebP otomatis dengan thumbnail.'],
            ['Dua bahasa antarmuka', 'Indonesia dan English untuk seluruh halaman depan.'],
            ['Keamanan', 'CSRF, escaping output, sanitasi HTML, security headers, dan proteksi folder internal.'],
            ['CLI', 'bin/console.php untuk instalasi, migrasi, seed, sinkronisasi tema, dan maintenance.'],
        ];
    }

    /** @return array<int, array{0:string,1:string}> */
    private function defaultFaq(): array
    {
        return [
            ['Apakah script bisa dipasang di shared hosting?', 'Bisa. CMS ini adalah PHP + MySQL tanpa framework, cukup PHP 8.2+ dan database MySQL/MariaDB.'],
            ['Berapa lisensinya?', 'Lisensi tunggal per instalasi. Bebas modifikasi untuk situs Anda sendiri, tidak untuk dijual ulang sebagai script.'],
            ['Apakah dapat update?', 'Pembelian menyertakan update selama satu tahun dan bantuan instalasi.'],
            ['Bisa dipakai untuk beberapa blog?', 'Ya. Satu instalasi mendukung multi-blog dengan pengguna dan peran terpisah per blog.'],
            ['Apakah data saya dikirim ke pihak ketiga?', 'Tidak. CV Maker dan QR Code Generator berjalan di peramban; data formulir tidak disimpan di server.'],
        ];
    }

    /**
     * Kerangka dokumen mandiri untuk halaman penjualan (tanpa chrome blog).
     *
     * @param array<string, mixed> $page
     */
    public function shell(string $title, array $page, string $content): string
    {
        return View::render('standalone/sales-shell', [
            'title' => $title,
            'page' => $page,
            'content' => $content,
            'siteName' => Settings::get('site_name', 'CMS'),
            'logo' => (string)Settings::get('logo_url', ''),
            'favicon' => (string)Settings::get('favicon_url', ''),
            'home' => url(),
        ]);
    }
}
