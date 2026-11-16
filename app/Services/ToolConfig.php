<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;
use App\Core\ValidationException;

/**
 * Konfigurasi terpusat untuk halaman alat mandiri (CV Maker, QR Code
 * Generator) dan halaman penjualan CMS.
 *
 * Semua nilai disimpan di `settings` (per blog) memakai prefix `tool_`, jadi
 * otomatis bertahan setelah logout/deploy dan ikut mekanisme cache CMS.
 * Nilai disajikan ke frontend lewat JSON di atribut `data-config` (hanya data
 * publik: judul, tombol, direct link aktif) — tidak ada secret.
 */
final class ToolConfig
{
    /** Definisi: key => [default, tipe, opsi]. Tipe: text|textarea|bool|select|url|int. */
    public const DEFAULTS = [
        // ---------- CV Maker ----------
        'tool_cv_enabled' => ['1', 'bool'],
        'tool_cv_title' => ['CV & Resume Maker', 'text'],
        'tool_cv_desc' => ['Buat CV profesional dalam beberapa desain, siap cetak atau simpan sebagai PDF.', 'textarea'],
        'tool_cv_templates' => ['modern,colored,minimal,ats,timeline', 'text'],
        'tool_cv_default_template' => ['modern', 'text'],
        'tool_cv_paper' => ['a4', 'select'],
        'tool_cv_accent' => ['#0f172a', 'text'],
        'tool_cv_font_scale' => ['1', 'text'],
        'tool_cv_min_font' => ['9.5', 'text'],
        'tool_cv_support_enabled' => ['1', 'bool'],
        'tool_cv_support_title' => ['Dukung Pengembangan CV Maker', 'text'],
        'tool_cv_support_desc' => ['CV Maker ini gratis. Kalau terbantu, dukungan sukarela Anda membuat alat seperti ini terus dikembangkan. Sepenuhnya opsional — CV tetap bisa diunduh.', 'textarea'],
        'tool_cv_support_button' => ['Dukung Kami', 'text'],
        'tool_cv_support_url' => ['', 'url'],
        'tool_cv_monetize' => ['0', 'bool'],
        'tool_cv_direct_url' => ['', 'url'],

        // ---------- QR Code Generator ----------
        'tool_qr_enabled' => ['1', 'bool'],
        'tool_qr_title' => ['QR Code Generator', 'text'],
        'tool_qr_desc' => ['Buat QR Code untuk URL, WhatsApp, teks, email, WiFi, vCard, dan lainnya — lalu unduh sebagai PNG, JPG, WEBP, atau SVG.', 'textarea'],
        'tool_qr_formats' => ['png,jpeg,webp,svg', 'text'],
        'tool_qr_ecc' => ['H', 'select'],
        'tool_qr_sizes' => ['256,512,1024,2048', 'text'],
        'tool_qr_timer' => ['0', 'int'],
        'tool_qr_support_enabled' => ['1', 'bool'],
        'tool_qr_support_title' => ['Dukung Pengembangan Tools Ini', 'text'],
        'tool_qr_support_desc' => ['QR Code Generator ini gratis dan berjalan di peramban Anda. Dukungan sukarela membantu biaya server dan pengembangan fitur berikutnya.', 'textarea'],
        'tool_qr_qris_enabled' => ['1', 'bool'],
        'tool_qr_qris_image' => ['', 'text'],
        'tool_qr_support_url' => ['', 'url'],
        'tool_qr_monetize' => ['0', 'bool'],
        'tool_qr_direct_url' => ['', 'url'],

        // ---------- Landing penjualan CMS ----------
        'price_enabled' => ['1', 'bool'],
        'price_hero' => ['1', 'bool'],
        'price_headline' => ['Script CMS Blog Siap Pakai — Punya Sendiri, Tanpa Langganan', 'text'],
        'price_subheadline' => ['Mesin blog lengkap dengan panel admin, tema, SEO, dan tools bawaan. Sekali bayar, pasang di hosting Anda sendiri.', 'textarea'],
        'price_description' => ['CMS blog berbasis PHP + MySQL dengan multi-blog, editor, page builder, tema, dan 25 jenis Structured Data Google. Ringan, tanpa framework berat, siap jalan di shared hosting.', 'textarea'],
        'price_features' => ['', 'textarea'],
        'price_show_features' => ['1', 'bool'],
        'price_tech' => ['PHP 8.2+, MySQL/MariaDB, tanpa framework, CSS/JS vanilla, siap shared hosting', 'textarea'],
        'price_show_tech' => ['1', 'bool'],
        'price_license' => ['Lisensi tunggal per instalasi. Bebas ubah kode untuk situs Anda sendiri; tidak untuk dijual ulang sebagai script.', 'textarea'],
        'price_show_license' => ['1', 'bool'],
        'price_amount' => ['Rp 450.000', 'text'],
        'price_original' => ['Rp 900.000', 'text'],
        'price_note' => ['Sekali bayar · update 1 tahun · dukungan instalasi', 'text'],
        'price_show_pricing' => ['1', 'bool'],
        'price_demo_url' => ['', 'url'],
        'price_buy_url' => ['', 'url'],
        'price_contact_url' => ['', 'url'],
        'price_contact_text' => ['Hubungi Kami', 'text'],
        'price_show_screens' => ['1', 'bool'],
        'price_shot_dashboard' => ['', 'text'],
        'price_shot_frontend' => ['', 'text'],
        'price_show_faq' => ['1', 'bool'],
        'price_faq' => ['', 'textarea'],
        'price_show_contacts' => ['1', 'bool'],
        'price_contact_email' => ['', 'text'],
        'price_contact_whatsapp' => ['', 'text'],
        'price_contact_address' => ['', 'textarea'],
    ];

    public static function defaults(): array
    {
        return array_map(static fn(array $row): string => (string)$row[0], self::DEFAULTS);
    }

    /** Semua nilai (default + tersimpan). */
    public static function all(): array
    {
        $values = [];
        foreach (self::DEFAULTS as $key => [$default, $type]) {
            $value = (string)Settings::get($key, $default);
            $values[$key] = in_array($type, ['bool', 'checkbox'], true) ? ($value === '1' ? '1' : '0') : $value;
        }
        return $values;
    }

    public static function get(string $key, string $fallback = ''): string
    {
        $default = self::DEFAULTS[$key][0] ?? $fallback;
        return (string)Settings::get($key, $default);
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    /**
     * Validasi + simpan dari form admin.
     *
     * Form admin memakai beberapa form terpisah (CV+QR, lalu landing). Supaya
     * menyimpan satu form tidak menonaktifkan pengaturan form lain, pemanggil
     * mengirim `cfg[_scope]` berisi prefix key yang dikelola form tersebut
     * (mis. `tool_cv_,tool_qr_`). Key di luar scope dibiarkan apa adanya.
     *
     * @param array<string, mixed> $input
     * @return array<string, string> nilai bersih
     */
    public static function save(array $input): array
    {
        $scope = array_values(array_filter(array_map(
            static fn($v): string => trim((string)$v),
            explode(',', (string)($input['_scope'] ?? ''))
        )));
        $inScope = static function (string $key) use ($scope): bool {
            if ($scope === []) {
                return true;
            }
            foreach ($scope as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }
            return false;
        };

        $out = [];
        foreach (self::DEFAULTS as $key => [$default, $type]) {
            if (!$inScope($key)) {
                continue;
            }
            if ($type === 'bool' || $type === 'checkbox') {
                $out[$key] = !empty($input[$key]) ? '1' : '0';
                continue;
            }
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = is_array($input[$key]) ? implode(',', $input[$key]) : (string)$input[$key];
            $out[$key] = self::clean($key, $value, $type);
        }

        // Template default harus ada di daftar template aktif.
        $templates = self::slugList((string)($out['tool_cv_templates'] ?? self::get('tool_cv_templates')));
        $defaultTpl = (string)($out['tool_cv_default_template'] ?? self::get('tool_cv_default_template'));
        if (!in_array($defaultTpl, $templates, true)) {
            $defaultTpl = $templates[0] ?? 'modern';
        }
        if ($templates !== []) {
            $out['tool_cv_templates'] = implode(',', $templates);
        }
        if (isset($out['tool_cv_default_template']) || $templates !== []) {
            $out['tool_cv_default_template'] = $defaultTpl;
        }

        if ($out !== []) {
            Settings::setMany($out);
        }
        return $out;
    }

    private static function clean(string $key, string $value, string $type): string
    {
        $value = trim($value);
        return match ($type) {
            'url' => $value === '' ? '' : self::validUrl($key, $value),
            'int' => (string)max(0, (int)$value),
            'select' => in_array($value, ['a4', 'letter', 'legal', 'modern', 'L', 'M', 'Q', 'H'], true) ? $value : (string)(self::DEFAULTS[$key][0] ?? ''),
            'textarea' => mb_substr($value, 0, 8000),
            default => mb_substr($value, 0, 500),
        };
    }

    private static function validUrl(string $key, string $value): string
    {
        if (preg_match('~^https?://~i', $value) !== 1) {
            throw new ValidationException('URL tidak valid: ' . $key . ' (harus diawali http:// atau https://).');
        }
        return mb_substr($value, 0, 500);
    }

    /** @return array<int, string> */
    public static function slugList(string $csv, array $allowed = []): array
    {
        $items = array_map(static fn($v): string => strtolower(trim((string)$v)), explode(',', $csv));
        $items = array_values(array_unique(array_filter($items, static fn(string $v): bool => preg_match('/^[a-z0-9-]{2,32}$/', $v) === 1)));
        if ($allowed !== []) {
            $items = array_values(array_filter($items, static fn(string $v): bool => in_array($v, $allowed, true)));
        }
        return array_slice($items, 0, 24);
    }

    // ------------------------------------------------------------------
    // Konfigurasi publik untuk frontend (tanpa data sensitif)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function publicConfig(string $tool): array
    {
        $supportBase = [
            'tool' => $tool,
            'enabled' => true,
            'supportEnabled' => self::bool('tool_' . $tool . '_support_enabled'),
            'title' => self::get('tool_' . $tool . '_support_title'),
            'description' => self::get('tool_' . $tool . '_support_desc'),
            'supportUrl' => self::get('tool_' . $tool . '_support_url'),
            'monetize' => self::bool('tool_' . $tool . '_monetize'),
            'directUrl' => self::bool('tool_' . $tool . '_monetize') ? self::get('tool_' . $tool . '_direct_url') : '',
        ];
        if ($tool === 'qr') {
            $qris = self::qrisImage();
            $supportBase['qris'] = self::bool('tool_qr_qris_enabled') && $qris !== '' ? $qris : '';
            $supportBase['button'] = '';
            $supportBase['sizes'] = array_values(array_filter(array_map('intval', explode(',', self::get('tool_qr_sizes'))), static fn(int $v): bool => $v >= 128 && $v <= 4096));
            $supportBase['formats'] = self::slugList(self::get('tool_qr_formats'), ['png', 'jpeg', 'webp', 'svg']);
            $supportBase['ecc'] = self::get('tool_qr_ecc', 'H');
            $supportBase['timer'] = (int)self::get('tool_qr_timer');
        } else {
            $supportBase['button'] = self::get('tool_cv_support_button');
            $supportBase['enabled'] = self::bool('tool_cv_enabled');
            $supportBase['paper'] = self::get('tool_cv_paper', 'a4');
            $supportBase['defaultTemplate'] = self::get('tool_cv_default_template', 'modern');
            $supportBase['templates'] = self::slugList(self::get('tool_cv_templates'));
            $supportBase['accent'] = self::get('tool_cv_accent', '#0f172a');
            $supportBase['fontScale'] = (float)self::get('tool_cv_font_scale', '1') ?: 1.0;
            $supportBase['minFont'] = (float)self::get('tool_cv_min_font', '9.5') ?: 9.5;
        }
        return $supportBase;
    }

    /** Atribut HTML `data-config` untuk halaman alat. */
    public static function dataAttribute(string $tool): string
    {
        return ' data-config="' . e(json_encode(self::publicConfig($tool), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) . '"';
    }

    /** Gambar QRIS efektif: setting → berkas bawaan QR-DANA.jpeg. */
    public static function qrisImage(): string
    {
        $custom = self::get('tool_qr_qris_image');
        if ($custom !== '') {
            return $custom;
        }
        foreach (['QR-DANA.jpeg', 'QR-DANA.jpg', 'QR-DANA.png'] as $candidate) {
            if (is_file(CMS_ROOT . '/' . $candidate)) {
                return url($candidate);
            }
        }
        return '';
    }
}
