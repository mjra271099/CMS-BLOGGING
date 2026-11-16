<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Lang;
use App\Core\Settings;
use App\Core\View;

/**
 * Halaman QR Code Generator.
 *
 * Dipakai dua cara:
 *
 *  1. Sebagai halaman CMS bertemplate `qr-generator` (legacy) — aset & markup
 *     disuntikkan oleh FrontController.
 *  2. Sebagai halaman mandiri `/qr-code-generator` (layout sendiri, tanpa
 *     chrome tema) — lihat {@see self::run()}.
 *
 * Semua konfigurasi berasal dari {@see ToolConfig}, sehingga dapat diatur dari
 * Admin → Tools (judul, deskripsi, format, ukuran, ECC, popup, QRIS, direct
 * link iklan).
 */
final class QrPage
{
    public const TEMPLATE = 'qr-generator';
    public const SLUG = 'qr-code-generator';

    /** Kunci lama; tetap dibaca sebagai fallback direct link (kompatibilitas). */
    public const DIRECT_LINK_KEY = 'qr_download_direct_url';

    public static function isTemplate(string $template): bool
    {
        return $template === self::TEMPLATE;
    }

    /** Tag <link> + <script> untuk <head>. Kosong bila halaman bukan template QR. */
    public static function headHtml(string $template): string
    {
        if (!self::isTemplate($template)) {
            return '';
        }
        return '<link rel="stylesheet" href="' . e(self::assetUrl('assets/front/qr-generator.css')) . '">'
            . '<link rel="stylesheet" href="' . e(self::assetUrl('assets/front/standalone.css')) . '">'
            . '<script src="' . e(self::assetUrl('assets/front/qr-generator.js')) . '" defer></script>';
    }

    /** URL Direct Link iklan; string kosong berarti unduhan tanpa perantara. */
    public static function directUrl(): string
    {
        $config = ToolConfig::publicConfig('qr');
        if (($config['directUrl'] ?? '') !== '') {
            return (string)$config['directUrl'];
        }
        $url = trim((string)Settings::get(self::DIRECT_LINK_KEY, ''));
        return preg_match('~^https?://~i', $url) === 1 ? $url : '';
    }

    /** Markup aplikasi QR (form + pratinjau + tombol unduh). */
    public static function body(): string
    {
        $cfg = ToolConfig::publicConfig('qr');
        return View::render('qr-generator', [
            'directUrl' => self::directUrl(),
            'config' => ToolConfig::dataAttribute('qr'),
            'formats' => $cfg['formats'] ?? ['png', 'jpeg', 'webp', 'svg'],
            'sizes' => $cfg['sizes'] ?? [256, 512, 1024, 2048],
            'ecc' => (string)($cfg['ecc'] ?? 'H'),
            'kicker' => 'Alat gratis · tanpa login',
            'intro' => ToolConfig::get('tool_qr_desc'),
        ]);
    }

    /** Halaman mandiri /qr-code-generator. */
    public function run(): string
    {
        \App\Core\Response::securityHeaders();

        if (!ToolConfig::bool('tool_qr_enabled')) {
            http_response_code(404);
            return '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>404</title>'
                . '<style>body{font:15px system-ui;padding:40px}</style></head><body><h1>404</h1>'
                . '<p>QR Code Generator sedang tidak aktif.</p><p><a href="' . e(url()) . '">Kembali ke beranda</a></p></body></html>';
        }

        $title = ToolConfig::get('tool_qr_title', 'QR Code Generator');

        return View::render('standalone/shell', [
            'title' => $title,
            'page' => [
                'slug' => self::SLUG,
                'home' => url(),
                'lang' => Lang::htmlLocale(),
                'config' => '',
                'bodyClass' => 'qr-standalone',
                'head' => '<link rel="stylesheet" href="' . e(self::assetUrl('assets/front/qr-generator.css')) . '">',
                'scripts' => '<script src="' . e(self::assetUrl('assets/front/qr-generator.js')) . '" defer></script>',
            ],
            'content' => '<div class="qr-standalone-wrap">' . self::body() . '</div>',
            'siteName' => Settings::get('site_name', 'CMS'),
            'logo' => (string)Settings::get('logo_url', ''),
            'favicon' => (string)Settings::get('favicon_url', ''),
            'home' => url(),
        ]);
    }

    private static function assetUrl(string $path): string
    {
        $file = CMS_ROOT . '/' . $path;
        $version = is_file($file) ? (string)filemtime($file) : '1';
        return url($path) . '?v=' . $version;
    }
}
