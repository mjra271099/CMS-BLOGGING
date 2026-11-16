<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Lang;
use App\Core\Settings;
use App\Core\View;

/**
 * Halaman mandiri CV & Resume Maker.
 *
 * Berdiri sendiri: tidak memakai navbar/footer tema blog, tidak membutuhkan
 * halaman CMS. Seluruh konfigurasi (judul, template aktif, ukuran kertas,
 * popup dukungan, direct link iklan) datang dari {@see ToolConfig} sehingga
 * bisa diatur dari Admin → Tools.
 *
 * Tool ini berjalan sepenuhnya di peramban: data formulir disimpan di
 * localStorage pengguna dan tidak pernah dikirim ke server.
 */
final class CvMakerPage
{
    public const SLUG = 'cv-maker';

    public function run(): string
    {
        \App\Core\Response::securityHeaders();

        if (!ToolConfig::bool('tool_cv_enabled')) {
            return $this->notFound('CV Maker sedang tidak aktif.');
        }

        $siteName = Settings::get('site_name', 'CMS');
        $title = ToolConfig::get('tool_cv_title', 'CV & Resume Maker');
        $desc = ToolConfig::get('tool_cv_desc');

        $body = View::render('cv-maker', [
            'title' => $title,
            'desc' => $desc,
            'templates' => ToolConfig::publicConfig('cv')['templates'] ?? [],
        ]);

        return View::render('standalone/shell', [
            'title' => $title,
            'page' => [
                'slug' => self::SLUG,
                'home' => url(),
                'lang' => Lang::htmlLocale(),
                'config' => ToolConfig::dataAttribute('cv'),
                'bodyClass' => 'cv-standalone',
                'head' => '<link rel="stylesheet" href="' . e(url('assets/front/cv-maker.css')) . '?v=' . $this->version('assets/front/cv-maker.css') . '">',
                'scripts' => '<script src="' . e(url('assets/front/cv-maker.js')) . '?v=' . $this->version('assets/front/cv-maker.js') . '" defer></script>',
            ],
            'content' => $body,
            'siteName' => $siteName,
            'logo' => (string)Settings::get('logo_url', ''),
            'favicon' => (string)Settings::get('favicon_url', ''),
            'home' => url(),
        ]);
    }

    private function version(string $path): string
    {
        $file = CMS_ROOT . '/' . $path;
        return is_file($file) ? (string)filemtime($file) : '1';
    }

    private function notFound(string $message): string
    {
        http_response_code(404);
        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>404</title><style>body{font:15px system-ui;padding:40px;background:#f5f7fb;color:#0f172a}'
            . 'a{color:#2563eb}</style></head><body><h1>404</h1><p>' . e($message) . '</p>'
            . '<p><a href="' . e(url()) . '">Kembali ke beranda</a></p></body></html>';
    }
}
