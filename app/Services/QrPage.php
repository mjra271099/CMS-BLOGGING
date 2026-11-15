<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;
use App\Core\View;

/**
 * Halaman QR Code Generator sebagai fitur Page CMS (template `qr-generator`).
 *
 * Tiga hal yang diurus di sini supaya controller dan tema tetap ramping:
 *
 *  1. Aset halaman (CSS + JS) yang hanya dimuat untuk template ini.
 *  2. Markup aplikasi (`app/Views/front/qr-generator.php`).
 *  3. Direct Link iklan dari Pengaturan situs, disiapkan untuk monetisasi
 *     tautan unduhan tanpa sistem iklan yang rumit.
 */
final class QrPage
{
    public const TEMPLATE = 'qr-generator';

    /** Kunci pengaturan situs: isi dengan URL direct link iklan bila ada. */
    public const DIRECT_LINK_KEY = 'qr_download_direct_url';

    public static function isTemplate(string $template): bool
    {
        return $template === self::TEMPLATE;
    }

    /**
     * Tag <link> + <script> untuk <head>. Kosong bila halaman bukan template
     * QR, sehingga halaman lain tidak menanggung biaya aset ini.
     */
    public static function headHtml(string $template): string
    {
        if (!self::isTemplate($template)) {
            return '';
        }
        $css = self::assetUrl('assets/front/qr-generator.css');
        $js = self::assetUrl('assets/front/qr-generator.js');
        return '<link rel="stylesheet" href="' . e($css) . '">'
            . '<script src="' . e($js) . '" defer></script>';
    }

    /** URL Direct Link iklan; string kosong berarti unduhan tanpa perantara. */
    public static function directUrl(): string
    {
        $url = trim((string)Settings::get(self::DIRECT_LINK_KEY, ''));
        return preg_match('~^https?://~i', $url) === 1 ? $url : '';
    }

    /** Markup aplikasi QR (form + pratinjau + tombol unduh). */
    public static function body(): string
    {
        return View::render('qr-generator', [
            'directUrl' => self::directUrl(),
            'kicker' => 'Alat gratis',
            'intro' => 'Buat QR Code untuk URL, WhatsApp, teks, email, WiFi, vCard, dan lainnya — lalu unduh sebagai PNG, JPG, WEBP, atau SVG.',
        ]);
    }

    private static function assetUrl(string $path): string
    {
        $file = CMS_ROOT . '/' . $path;
        $version = is_file($file) ? (string)filemtime($file) : '1';
        return url($path) . '?v=' . $version;
    }
}
