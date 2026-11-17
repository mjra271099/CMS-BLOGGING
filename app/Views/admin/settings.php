<?php
declare(strict_types=1);

/**
 * Site settings grouped into tabs: identity, reading, comments, media,
 * social, integrations and advanced HTML injection.
 *
 * Tabs use the existing `.filter-tabs` pattern and a `tab` query parameter so
 * the page works without JavaScript and each group can be reset to defaults.
 *
 * @var array $settings
 * @var array $defaults
 */

use App\Http\AdminController;
use App\Http\AdminUi;

$settings = $settings ?? [];
$defaults = $defaults ?? [];
$adminBase = url('admin/index.php');
$value = static fn(string $key): string => (string)($settings[$key] ?? ($defaults[$key] ?? ''));

$groups = AdminController::settingsGroups();
$tabLabels = [
    'identity' => 'Identitas',
    'reading' => 'Membaca',
    'comments' => 'Komentar',
    'media' => 'Media',
    'seo' => 'SEO',
    'social' => 'Sosial',
    'email' => 'Email',
    'advanced' => 'Lanjutan',
];
$activeTab = (string)($_GET['tab'] ?? 'identity');
if (!isset($groups[$activeTab])) {
    $activeTab = 'identity';
}
$tabUrl = static fn(string $tab): string => $adminBase . '?view=settings&tab=' . rawurlencode($tab);
?>
<div class="toolbar">
    <h1>Pengaturan</h1>
    <span class="muted">Berlaku untuk blog aktif</span>
</div>

<nav class="filter-tabs" style="margin-bottom:16px" aria-label="Bagian pengaturan">
    <?php foreach ($tabLabels as $key => $label): ?>
        <a class="filter-tab<?= $key === $activeTab ? ' active' : '' ?>" href="<?= e($tabUrl($key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<form method="post" action="<?= e($adminBase . '?view=settings&tab=' . rawurlencode($activeTab)) ?>">
    <input type="hidden" name="action" value="settings.save">
    <?= \App\Core\Csrf::field() ?>

    <?php if ($activeTab === 'identity'): ?>
    <div class="card">
        <h2>Identitas Situs</h2>
        <div class="row">
            <div>
                <label for="site_name">Nama situs</label>
                <input id="site_name" type="text" name="site_name" value="<?= e($value('site_name')) ?>" required>
            </div>
            <div>
                <label for="tagline">Tagline</label>
                <input id="tagline" type="text" name="tagline" value="<?= e($value('tagline')) ?>">
            </div>
        </div>
        <label for="site_description">Deskripsi situs</label>
        <textarea id="site_description" name="site_description" rows="3"><?= e($value('site_description')) ?></textarea>
        <div class="row">
            <div>
                <label for="site_url">URL situs</label>
                <input id="site_url" type="url" name="site_url" value="<?= e($value('site_url')) ?>" placeholder="https://example.com/">
                <p class="help">Harus diakhiri garis miring. Digunakan untuk URL absolut, sitemap dan feed.</p>
            </div>
            <div>
                <label for="language">Bahasa</label>
                <select id="language" name="language">
                    <?php foreach (\App\Core\Lang::SUPPORTED as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $value('language') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="timezone">Zona waktu</label>
                <input id="timezone" type="text" name="timezone" value="<?= e($value('timezone')) ?>">
            </div>
        </div>
        <div class="row">
            <div>
                <label for="logo_url">Logo</label>
                <input id="logo_url" type="text" name="logo_url" value="<?= e($value('logo_url')) ?>" placeholder="URL gambar">
            </div>
            <div>
                <label for="favicon_url">Favicon</label>
                <input id="favicon_url" type="text" name="favicon_url" value="<?= e($value('favicon_url')) ?>">
            </div>
            <div>
                <label for="social_image">Gambar sosial default</label>
                <input id="social_image" type="text" name="social_image" value="<?= e($value('social_image')) ?>">
            </div>
        </div>
    </div>
    <?php elseif ($activeTab === 'reading'): ?>
    <div class="card">
        <h2>Membaca &amp; Permalink</h2>
        <div class="row">
            <div>
                <label for="posts_per_page">Artikel per halaman</label>
                <input id="posts_per_page" type="number" name="posts_per_page" min="1" max="60" value="<?= e($value('posts_per_page')) ?>">
            </div>
            <div>
                <label>Format permalink</label>
                <input type="text" value="/YYYY/MM/slug.html (Blogger-style, tetap)" readonly disabled>
                <p class="help">URL artikel memakai format Blogger dan dipertahankan demi kompatibilitas tautan lama. Format ini tidak dapat diubah dari UI.</p>
            </div>
            <div>
                <label for="homepage_mode">Beranda</label>
                <select id="homepage_mode" name="homepage_mode">
                    <option value="posts"<?= $value('homepage_mode') === 'posts' ? ' selected' : '' ?>>Postingan terbaru</option>
                    <option value="page"<?= $value('homepage_mode') === 'page' ? ' selected' : '' ?>>Halaman statis</option>
                </select>
            </div>
        </div>
        <label for="footer_text">Teks footer</label>
        <input id="footer_text" type="text" name="footer_text" value="<?= e($value('footer_text')) ?>">
    </div>
    <?php elseif ($activeTab === 'comments'): ?>
    <div class="card">
        <h2>Komentar</h2>
        <label class="checkbox"><input type="checkbox" name="allow_comments" value="1"<?= $value('allow_comments') === '1' ? ' checked' : '' ?>> Izinkan komentar</label>
        <label class="checkbox"><input type="checkbox" name="comment_moderation" value="1"<?= $value('comment_moderation') === '1' ? ' checked' : '' ?>> Moderasi komentar baru</label>
        <div class="row">
            <div>
                <label for="comments_per_page">Komentar per halaman</label>
                <input id="comments_per_page" type="number" name="comments_per_page" value="<?= e($value('comments_per_page')) ?>">
            </div>
            <div>
                <label for="comments_closed_message">Pesan saat komentar ditutup</label>
                <input id="comments_closed_message" type="text" name="comments_closed_message" value="<?= e($value('comments_closed_message')) ?>">
            </div>
        </div>
    </div>
    <?php elseif ($activeTab === 'media'): ?>
    <div class="card">
        <h2>Media &amp; Kinerja</h2>
        <div class="row">
            <div>
                <label for="media_max_mb">Ukuran unggahan maks (MB)</label>
                <input id="media_max_mb" type="number" name="media_max_mb" min="1" max="200" value="<?= e($value('media_max_mb')) ?>">
            </div>
            <div>
                <label for="cache_ttl">Cache TTL (detik)</label>
                <input id="cache_ttl" type="number" name="cache_ttl" min="0" max="86400" value="<?= e($value('cache_ttl')) ?>">
            </div>
            <div>
                <label for="cdn_url">CDN URL</label>
                <input id="cdn_url" type="url" name="cdn_url" value="<?= e($value('cdn_url')) ?>" placeholder="https://cdn.example.com/">
            </div>
        </div>
        <label class="checkbox"><input type="checkbox" name="media_webp" value="1"<?= $value('media_webp') === '1' ? ' checked' : '' ?>> Buat versi WebP otomatis</label>
    </div>
    <?php elseif ($activeTab === 'seo'): ?>
    <div class="card">
        <h2>SEO Default</h2>
        <div class="row">
            <div>
                <label for="seo_title">Judul SEO</label>
                <input id="seo_title" type="text" name="seo_title" value="<?= e($value('seo_title')) ?>">
            </div>
            <div>
                <label for="meta_title_suffix">Sufiks judul</label>
                <input id="meta_title_suffix" type="text" name="meta_title_suffix" value="<?= e($value('meta_title_suffix')) ?>" placeholder=" — Nama Situs">
            </div>
        </div>
        <label for="seo_description">Deskripsi SEO</label>
        <textarea id="seo_description" name="seo_description" rows="3"><?= e($value('seo_description')) ?></textarea>
        <label for="seo_keywords">Kata kunci</label>
        <input id="seo_keywords" type="text" name="seo_keywords" value="<?= e($value('seo_keywords')) ?>">
        <div class="row">
            <div>
                <label for="google_site_verification">Google verification</label>
                <input id="google_site_verification" type="text" name="google_site_verification" value="<?= e($value('google_site_verification')) ?>">
            </div>
            <div>
                <label for="bing_site_verification">Bing verification</label>
                <input id="bing_site_verification" type="text" name="bing_site_verification" value="<?= e($value('bing_site_verification')) ?>">
            </div>
            <div>
                <label for="yandex_site_verification">Yandex verification</label>
                <input id="yandex_site_verification" type="text" name="yandex_site_verification" value="<?= e($value('yandex_site_verification')) ?>">
            </div>
        </div>
        <div class="row">
            <div>
                <label for="robots_policy">Robots</label>
                <select id="robots_policy" name="robots_policy">
                    <?php foreach (['index,follow' => 'index,follow', 'noindex,follow' => 'noindex,follow', 'index,nofollow' => 'index,nofollow', 'noindex,nofollow' => 'noindex,nofollow'] as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $value('robots_policy') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="feed_items">Item feed</label>
                <input id="feed_items" type="number" name="feed_items" value="<?= e($value('feed_items')) ?>">
            </div>
            <div>
                <label for="twitter_site">Akun Twitter</label>
                <input id="twitter_site" type="text" name="twitter_site" value="<?= e($value('twitter_site')) ?>" placeholder="@username">
            </div>
        </div>
        <label class="checkbox"><input type="checkbox" name="sitemap_enabled" value="1"<?= $value('sitemap_enabled') === '1' ? ' checked' : '' ?>> Aktifkan sitemap XML</label>
        <label for="robots_txt">robots.txt kustom</label>
        <textarea id="robots_txt" name="robots_txt" rows="5" placeholder="User-agent: *&#10;Disallow: /admin/&#10;Sitemap: …"><?= e($value('robots_txt')) ?></textarea>
    </div>
    <?php elseif ($activeTab === 'social'): ?>
    <div class="card">
        <h2>Sosial</h2>
        <div class="row">
            <?php foreach (['social_facebook' => 'Facebook', 'social_instagram' => 'Instagram', 'social_youtube' => 'YouTube', 'social_twitter' => 'Twitter/X', 'social_linkedin' => 'LinkedIn'] as $key => $label): ?>
                <div>
                    <label for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input id="<?= e($key) ?>" type="url" name="<?= e($key) ?>" value="<?= e($value($key)) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php elseif ($activeTab === 'email'): ?>
    <div class="card">
        <h2>Email</h2>
        <div class="row">
            <div>
                <label for="email_from">Alamat pengirim</label>
                <input id="email_from" type="email" name="email_from" value="<?= e($value('email_from')) ?>">
            </div>
            <div>
                <label for="email_name">Nama pengirim</label>
                <input id="email_name" type="text" name="email_name" value="<?= e($value('email_name')) ?>">
            </div>
        </div>
    </div>
    <?php elseif ($activeTab === 'advanced'): ?>
    <div class="card">
        <h2>Direct Link Iklan (QR Code)</h2>
        <label for="qr_download_direct_url">URL Direct Link untuk tombol unduh QR</label>
        <input id="qr_download_direct_url" type="url" name="qr_download_direct_url" value="<?= e($value('qr_download_direct_url')) ?>" placeholder="https://direct-link-iklan.example/">
        <p class="help">Opsional. Bila diisi, tautan ini dibuka di tab baru setiap pengunjung menekan tombol unduh di halaman QR Code Generator. Kosongkan untuk unduhan langsung tanpa perantara.</p>
    </div>
    <div class="card">
        <h2>Lanjutan</h2>
        <label class="checkbox"><input type="checkbox" name="maintenance_mode" value="1"<?= $value('maintenance_mode') === '1' ? ' checked' : '' ?>> Mode perawatan (situs tertutup untuk pengunjung)</label>
        <label for="analytics_code">Kode analytics (dalam &lt;head&gt;)</label>
        <textarea id="analytics_code" name="analytics_code" rows="3"><?= e($value('analytics_code')) ?></textarea>
        <label for="header_code">HTML tambahan di &lt;head&gt;</label>
        <textarea id="header_code" name="header_code" rows="3"><?= e($value('header_code')) ?></textarea>
        <label for="footer_code">HTML tambahan sebelum &lt;/body&gt;</label>
        <textarea id="footer_code" name="footer_code" rows="3"><?= e($value('footer_code')) ?></textarea>
    </div>
    <?php endif; ?>

    <div class="customizer-actions">
        <button type="submit">Simpan Pengaturan</button>
        <span class="muted">Cache dibersihkan otomatis setelah menyimpan.</span>
    </div>
</form>

<form method="post" action="<?= e($adminBase . '?view=settings&tab=' . rawurlencode($activeTab)) ?>" style="margin-top:12px"
      onsubmit="return confirm('Kembalikan pengaturan bagian ini ke nilai bawaan?');">
    <input type="hidden" name="action" value="settings.reset">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="group" value="<?= e($activeTab) ?>">
    <button type="submit" class="btn secondary small">Reset bagian ini ke bawaan</button>
</form>
