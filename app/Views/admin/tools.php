<?php
declare(strict_types=1);

/**
 * Admin → Tools & Landing.
 *
 * Satu formulir untuk CV Maker, QR Code Generator (termasuk QRIS), dan
 * halaman penjualan CMS. Semua nilai disimpan lewat App\Services\ToolConfig.
 *
 * @var array  $values  nilai ToolConfig::all()
 * @var string $qris    URL gambar QRIS aktif
 * @var string $customQris path QRIS kustom (kosong = bawaan)
 * @var array  $demos   URL pratinjau tools
 * @var string $selfUrl
 */

use App\Core\Csrf;

$v = static fn(string $key): string => (string)($values[$key] ?? '');
$checked = static fn(string $key): string => $v($key) === '1' ? ' checked' : '';
$selfUrl = (string)($selfUrl ?? url('admin/tools.php'));

$templateLabels = [
    'modern' => 'Desain 1 — Modern 2 Kolom + Foto',
    'colored' => 'Desain 2 — Klasik Serif (ATS)',
    'minimal' => 'Desain 3 — Minimalis Bersih + Foto',
    'ats' => 'ATS Friendly — Satu Kolom Ketat',
    'timeline' => 'Timeline — Riwayat Bergaris Waktu',
];
$activeTemplates = array_filter(explode(',', $v('tool_cv_templates')));
?>
<style>
/* Gaya lokal halaman Admin → Tools; dibatasi pada .tools-admin. */
.tools-admin { display: grid; gap: 18px; }
.tools-admin .toolbar { display: flex; flex-wrap: wrap; align-items: baseline; gap: 10px; margin: 0; }
.tools-admin .card { display: grid; gap: 8px; }
.tools-admin .card > h2 { margin: 0 0 2px; font-size: 1rem; }
.tools-admin .card > h3 { margin: 14px 0 4px; font-size: .9rem; color: var(--admin-text-secondary, #475569); }
.tools-admin .row { display: flex; flex-wrap: wrap; gap: 10px; }
.tools-admin .row > * { flex: 1 1 210px; min-width: 0; }
.tools-admin .row > div { display: grid; gap: 4px; }
.tools-admin label { font-size: .82rem; font-weight: 600; }
.tools-admin .checkbox { display: flex; align-items: center; gap: 8px; font-weight: 500; font-size: .85rem; }
.tools-admin .checkbox input { width: auto; }
.tools-admin .help { margin: 0; font-size: .76rem; color: var(--admin-text-muted, #64748b); }
.tools-admin input[type="text"],
.tools-admin input[type="url"],
.tools-admin input[type="email"],
.tools-admin input[type="number"],
.tools-admin input[type="color"],
.tools-admin select,
.tools-admin textarea { width: 100%; }
.tools-admin input[type="color"] { height: 38px; padding: 2px; }
.tools-admin .tools-actions {
  position: sticky;
  bottom: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  padding: 12px 14px;
  background: var(--admin-surface, #fff);
  border: 1px solid var(--admin-border, #e2e8f0);
  border-radius: 12px;
  z-index: 5;
}
.tools-admin .tools-grid-3 { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.tools-admin a { overflow-wrap: anywhere; }
</style>
<div class="tmdb-wrap tools-admin">
<div class="toolbar">
    <h1>Tools &amp; Landing</h1>
    <span class="muted">CV Maker, QR Code Generator, dan halaman penjualan</span>
</div>

<div class="card">
    <h2>Pratinjau cepat</h2>
    <p class="help">Halaman alat memakai layout mandiri (tanpa navbar/footer blog) dan tetap memakai infrastruktur CMS.</p>
    <div class="row">
        <div><label>CV Maker</label><p><a href="<?= e($demos['cv']) ?>" target="_blank" rel="noopener"><?= e($demos['cv']) ?></a></p></div>
        <div><label>QR Code Generator</label><p><a href="<?= e($demos['qr']) ?>" target="_blank" rel="noopener"><?= e($demos['qr']) ?></a></p></div>
        <div><label>Halaman penjualan</label><p><a href="<?= e($demos['sales']) ?>" target="_blank" rel="noopener"><?= e($demos['sales']) ?></a></p></div>
    </div>
</div>

<form method="post" action="<?= e($selfUrl) ?>" enctype="multipart/form-data">
    <input type="hidden" name="action" value="tools.save">
    <input type="hidden" name="_redirect" value="admin/tools.php">
    <input type="hidden" name="cfg[_scope]" value="tool_cv_,tool_qr_">
    <?= Csrf::field() ?>

    <!-- ================= CV MAKER ================= -->
    <div class="card">
        <h2>CV Maker</h2>
        <label class="checkbox"><input type="checkbox" name="cfg[tool_cv_enabled]" value="1"<?= $checked('tool_cv_enabled') ?>> Aktifkan halaman CV Maker</label>
        <div class="row">
            <div>
                <label for="tool_cv_title">Judul tools</label>
                <input id="tool_cv_title" type="text" name="cfg[tool_cv_title]" value="<?= e($v('tool_cv_title')) ?>">
            </div>
            <div>
                <label for="tool_cv_default_template">Template default</label>
                <select id="tool_cv_default_template" name="cfg[tool_cv_default_template]">
                    <?php foreach ($templateLabels as $slug => $label): ?>
                        <option value="<?= e($slug) ?>"<?= $v('tool_cv_default_template') === $slug ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="tool_cv_paper">Ukuran kertas default</label>
                <select id="tool_cv_paper" name="cfg[tool_cv_paper]">
                    <?php foreach (['a4' => 'A4 (210 × 297 mm)', 'letter' => 'Letter (216 × 279 mm)', 'legal' => 'Legal (216 × 356 mm)'] as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $v('tool_cv_paper') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <label for="tool_cv_desc">Deskripsi tools</label>
        <textarea id="tool_cv_desc" name="cfg[tool_cv_desc]" rows="2"><?= e($v('tool_cv_desc')) ?></textarea>

        <label>Template tersedia</label>
        <div class="row">
            <?php foreach ($templateLabels as $slug => $label): ?>
                <label class="checkbox">
                    <input type="checkbox" name="cfg[tool_cv_templates_<?= e($slug) ?>]" value="<?= e($slug) ?>"<?= in_array($slug, $activeTemplates, true) ? ' checked' : '' ?>>
                    <?= e($label) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="cfg[tool_cv_templates]" id="tool_cv_templates" value="<?= e(implode(',', $activeTemplates)) ?>">

        <div class="row">
            <div>
                <label for="tool_cv_accent">Warna aksen CV</label>
                <input id="tool_cv_accent" type="color" name="cfg[tool_cv_accent]" value="<?= e($v('tool_cv_accent') ?: '#0f172a') ?>">
            </div>
            <div>
                <label for="tool_cv_font_scale">Skala font PDF (0.85 – 1.15)</label>
                <input id="tool_cv_font_scale" type="number" step="0.01" min="0.85" max="1.15" name="cfg[tool_cv_font_scale]" value="<?= e($v('tool_cv_font_scale')) ?>">
            </div>
            <div>
                <label for="tool_cv_min_font">Ukuran font minimum (px)</label>
                <input id="tool_cv_min_font" type="number" step="0.5" min="8" max="12" name="cfg[tool_cv_min_font]" value="<?= e($v('tool_cv_min_font')) ?>">
            </div>
        </div>
        <p class="help">Ukuran font &amp; jarak mengecil otomatis secara bertahap dan proporsional hanya bila teks lebih panjang dari ruang tersedia, sampai batas minimum di atas (agar tetap nyaman dibaca). Bila di batas minimum pun belum muat satu halaman, konten dibagi ke halaman berikutnya — tidak pernah dikecilkan di bawah batas ini dan tidak pernah dipotong.</p>

        <h3>Popup dukungan</h3>
        <label class="checkbox"><input type="checkbox" name="cfg[tool_cv_support_enabled]" value="1"<?= $checked('tool_cv_support_enabled') ?>> Tampilkan popup dukungan saat menekan Download</label>
        <div class="row">
            <div>
                <label for="tool_cv_support_title">Judul popup</label>
                <input id="tool_cv_support_title" type="text" name="cfg[tool_cv_support_title]" value="<?= e($v('tool_cv_support_title')) ?>">
            </div>
            <div>
                <label for="tool_cv_support_button">Label tombol dukung</label>
                <input id="tool_cv_support_button" type="text" name="cfg[tool_cv_support_button]" value="<?= e($v('tool_cv_support_button')) ?>">
            </div>
        </div>
        <label for="tool_cv_support_desc">Deskripsi popup</label>
        <textarea id="tool_cv_support_desc" name="cfg[tool_cv_support_desc]" rows="2"><?= e($v('tool_cv_support_desc')) ?></textarea>
        <label class="checkbox"><input type="checkbox" name="cfg[tool_cv_qris_enabled]" value="1"<?= $checked('tool_cv_qris_enabled') ?>> Tampilkan gambar QRIS di popup (memakai gambar QRIS yang sama dengan QR Code Generator)</label>
        <p class="help">Unggah/ganti gambar QRIS di kartu <strong>Gambar QRIS</strong> di bawah — dipakai bersama oleh CV Maker dan QR Code Generator.</p>

        <h3>Monetisasi</h3>
        <label class="checkbox"><input type="checkbox" name="cfg[tool_cv_monetize]" value="1"<?= $checked('tool_cv_monetize') ?>> Aktifkan direct link iklan CV Maker</label>
        <div class="row">
            <div>
                <label for="tool_cv_direct_url">Direct link iklan (dibuka di tab baru)</label>
                <input id="tool_cv_direct_url" type="url" name="cfg[tool_cv_direct_url]" value="<?= e($v('tool_cv_direct_url')) ?>" placeholder="https://...">
            </div>
            <div>
                <label for="tool_cv_support_url">URL dukungan (opsional)</label>
                <input id="tool_cv_support_url" type="url" name="cfg[tool_cv_support_url]" value="<?= e($v('tool_cv_support_url')) ?>" placeholder="https://...">
            </div>
        </div>
        <p class="help">Unduhan tetap berjalan di halaman utama; pengguna tidak perlu kembali dari halaman iklan.</p>
    </div>

    <!-- ================= QR CODE ================= -->
    <div class="card">
        <h2>QR Code Generator</h2>
        <label class="checkbox"><input type="checkbox" name="cfg[tool_qr_enabled]" value="1"<?= $checked('tool_qr_enabled') ?>> Aktifkan halaman QR Code Generator</label>
        <div class="row">
            <div>
                <label for="tool_qr_title">Judul tools</label>
                <input id="tool_qr_title" type="text" name="cfg[tool_qr_title]" value="<?= e($v('tool_qr_title')) ?>">
            </div>
            <div>
                <label for="tool_qr_ecc">Koreksi kesalahan</label>
                <select id="tool_qr_ecc" name="cfg[tool_qr_ecc]">
                    <?php foreach (['L' => 'L — 7%', 'M' => 'M — 15%', 'Q' => 'Q — 25%', 'H' => 'H — 30% (paling tahan)' ] as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $v('tool_qr_ecc') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="tool_qr_formats">Format unduhan</label>
                <input id="tool_qr_formats" type="text" name="cfg[tool_qr_formats]" value="<?= e($v('tool_qr_formats')) ?>">
                <p class="help">Daftar dipisah koma: png, jpeg, webp, svg.</p>
            </div>
            <div>
                <label for="tool_qr_sizes">Ukuran (px)</label>
                <input id="tool_qr_sizes" type="text" name="cfg[tool_qr_sizes]" value="<?= e($v('tool_qr_sizes')) ?>">
            </div>
            <div>
                <label for="tool_qr_timer">Batas waktu generate (detik, 0 = tanpa batas)</label>
                <input id="tool_qr_timer" type="number" min="0" max="120" name="cfg[tool_qr_timer]" value="<?= e($v('tool_qr_timer')) ?>">
            </div>
        </div>
        <label for="tool_qr_desc">Deskripsi tools</label>
        <textarea id="tool_qr_desc" name="cfg[tool_qr_desc]" rows="2"><?= e($v('tool_qr_desc')) ?></textarea>

        <h3>Popup dukungan &amp; QRIS</h3>
        <label class="checkbox"><input type="checkbox" name="cfg[tool_qr_support_enabled]" value="1"<?= $checked('tool_qr_support_enabled') ?>> Tampilkan popup dukungan saat menekan Download</label>
        <label class="checkbox"><input type="checkbox" name="cfg[tool_qr_qris_enabled]" value="1"<?= $checked('tool_qr_qris_enabled') ?>> Tampilkan gambar QRIS di popup</label>
        <div class="row">
            <div>
                <label for="tool_qr_support_title">Judul popup</label>
                <input id="tool_qr_support_title" type="text" name="cfg[tool_qr_support_title]" value="<?= e($v('tool_qr_support_title')) ?>">
            </div>
            <div>
                <label for="tool_qr_support_url">URL dukungan alternatif</label>
                <input id="tool_qr_support_url" type="url" name="cfg[tool_qr_support_url]" value="<?= e($v('tool_qr_support_url')) ?>" placeholder="https://...">
            </div>
        </div>
        <label for="tool_qr_support_desc">Deskripsi popup</label>
        <textarea id="tool_qr_support_desc" name="cfg[tool_qr_support_desc]" rows="2"><?= e($v('tool_qr_support_desc')) ?></textarea>

        <h3>Monetisasi</h3>
        <label class="checkbox"><input type="checkbox" name="cfg[tool_qr_monetize]" value="1"<?= $checked('tool_qr_monetize') ?>> Aktifkan direct link iklan QR Code</label>
        <label for="tool_qr_direct_url">Direct link iklan (dibuka di tab baru)</label>
        <input id="tool_qr_direct_url" type="url" name="cfg[tool_qr_direct_url]" value="<?= e($v('tool_qr_direct_url')) ?>" placeholder="https://...">
    </div>

    <div class="tools-actions">
        <button type="submit">Simpan Tools &amp; Landing</button>
        <span class="muted">Perubahan langsung berlaku di frontend.</span>
    </div>
</form>

<!-- ================= QRIS (upload terpisah) ================= -->
<div class="card">
    <h2>Gambar QRIS</h2>
    <p class="help">Dipakai popup dukungan QR Code Generator. Bila kosong, dipakai berkas bawaan <code>QR-DANA.jpeg</code>.</p>
    <div class="row">
        <div>
            <label>QRIS aktif</label>
            <?php if ($qris !== ''): ?>
                <a href="<?= e($qris) ?>" target="_blank" rel="noopener" title="Buka gambar QRIS ukuran penuh">
                    <img src="<?= e($qris) ?>" alt="QRIS dukungan" style="max-width:300px;width:100%;height:auto;border:1px solid var(--border,#e5e7eb);border-radius:12px;background:#fff;padding:8px;cursor:zoom-in">
                </a>
                <p class="help"><?= $customQris !== '' ? 'Kustom: ' . e($customQris) : 'Bawaan: QR-DANA.jpeg' ?> · klik gambar untuk membuka ukuran penuh.</p>
            <?php else: ?>
                <p class="muted">Belum ada gambar QRIS.</p>
            <?php endif; ?>
        </div>
        <div>
            <form method="post" action="<?= e($selfUrl) ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tools.qris">
                <?= Csrf::field() ?>
                <label for="qris">Unggah QRIS baru (JPG/PNG/WebP, maks <?= (int)\App\Core\Settings::get('media_max_mb', '5') ?> MB)</label>
                <input id="qris" type="file" name="qris" accept="image/jpeg,image/png,image/webp" required>
                <button type="submit">Ganti Gambar QRIS</button>
            </form>
            <?php if ($customQris !== ''): ?>
                <form method="post" action="<?= e($selfUrl) ?>" style="margin-top:8px">
                    <input type="hidden" name="action" value="tools.qris.reset">
                    <?= Csrf::field() ?>
                    <button type="submit" class="secondary">Kembalikan ke bawaan</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ================= LANDING PENJUALAN ================= -->
<form method="post" action="<?= e($selfUrl) ?>">
    <input type="hidden" name="action" value="tools.save">
    <input type="hidden" name="_redirect" value="admin/tools.php">
    <input type="hidden" name="cfg[_scope]" value="price_">
    <?= Csrf::field() ?>

    <div class="card">
        <h2>Halaman Penjualan CMS</h2>
        <label class="checkbox"><input type="checkbox" name="cfg[price_enabled]" value="1"<?= $checked('price_enabled') ?>> Aktifkan halaman penjualan (/jual-script-cms)</label>
        <label class="checkbox"><input type="checkbox" name="cfg[price_hero]" value="1"<?= $checked('price_hero') ?>> Tampilkan hero section</label>
        <div class="row">
            <div>
                <label for="price_headline">Headline</label>
                <input id="price_headline" type="text" name="cfg[price_headline]" value="<?= e($v('price_headline')) ?>">
            </div>
            <div>
                <label for="price_amount">Harga</label>
                <input id="price_amount" type="text" name="cfg[price_amount]" value="<?= e($v('price_amount')) ?>">
            </div>
            <div>
                <label for="price_original">Harga coret (opsional)</label>
                <input id="price_original" type="text" name="cfg[price_original]" value="<?= e($v('price_original')) ?>">
            </div>
            <div>
                <label for="price_note">Catatan harga</label>
                <input id="price_note" type="text" name="cfg[price_note]" value="<?= e($v('price_note')) ?>">
            </div>
        </div>
        <label for="price_subheadline">Subheadline</label>
        <textarea id="price_subheadline" name="cfg[price_subheadline]" rows="2"><?= e($v('price_subheadline')) ?></textarea>
        <label for="price_description">Deskripsi produk</label>
        <textarea id="price_description" name="cfg[price_description]" rows="3"><?= e($v('price_description')) ?></textarea>
    </div>

    <div class="card">
        <h2>Fitur, Teknologi, Lisensi</h2>
        <label class="checkbox"><input type="checkbox" name="cfg[price_show_features]" value="1"<?= $checked('price_show_features') ?>> Tampilkan daftar fitur</label>
        <label for="price_features">Daftar fitur</label>
        <textarea id="price_features" name="cfg[price_features]" rows="10" placeholder="Satu fitur per baris, format: Judul | Deskripsi"><?= e($v('price_features')) ?></textarea>
        <p class="help">Satu fitur per baris: <code>Judul | Deskripsi</code>. Kosongkan untuk memakai daftar fitur bawaan yang sudah sesuai kode CMS.</p>

        <label class="checkbox"><input type="checkbox" name="cfg[price_show_tech]" value="1"<?= $checked('price_show_tech') ?>> Tampilkan teknologi</label>
        <textarea id="price_tech" name="cfg[price_tech]" rows="3"><?= e($v('price_tech')) ?></textarea>

        <label class="checkbox"><input type="checkbox" name="cfg[price_show_license]" value="1"<?= $checked('price_show_license') ?>> Tampilkan lisensi</label>
        <textarea id="price_license" name="cfg[price_license]" rows="3"><?= e($v('price_license')) ?></textarea>
    </div>

    <div class="card">
        <h2>CTA, Gambar, FAQ, Kontak</h2>
        <label class="checkbox"><input type="checkbox" name="cfg[price_show_pricing]" value="1"<?= $checked('price_show_pricing') ?>> Tampilkan blok harga</label>
        <div class="row">
            <div>
                <label for="price_demo_url">URL demo</label>
                <input id="price_demo_url" type="url" name="cfg[price_demo_url]" value="<?= e($v('price_demo_url')) ?>" placeholder="https://...">
            </div>
            <div>
                <label for="price_buy_url">URL pembelian</label>
                <input id="price_buy_url" type="url" name="cfg[price_buy_url]" value="<?= e($v('price_buy_url')) ?>" placeholder="https://...">
            </div>
            <div>
                <label for="price_contact_url">URL kontak / konsultasi</label>
                <input id="price_contact_url" type="url" name="cfg[price_contact_url]" value="<?= e($v('price_contact_url')) ?>" placeholder="https://...">
            </div>
        </div>
        <p class="help">Bila URL demo kosong, tombol demo mengarah ke halaman depan CMS ini. Bila URL pembelian kosong, tombol Beli mengarah ke formulir pesanan bawaan.</p>

        <label class="checkbox"><input type="checkbox" name="cfg[price_show_screens]" value="1"<?= $checked('price_show_screens') ?>> Tampilkan screenshot</label>
        <div class="row">
            <div>
                <label for="price_shot_dashboard">Screenshot dashboard (path/URL)</label>
                <input id="price_shot_dashboard" type="text" name="cfg[price_shot_dashboard]" value="<?= e($v('price_shot_dashboard')) ?>" placeholder="uploads/2026/11/dashboard.png">
            </div>
            <div>
                <label for="price_shot_frontend">Screenshot frontend (path/URL)</label>
                <input id="price_shot_frontend" type="text" name="cfg[price_shot_frontend]" value="<?= e($v('price_shot_frontend')) ?>" placeholder="uploads/2026/11/frontend.png">
            </div>
        </div>

        <label class="checkbox"><input type="checkbox" name="cfg[price_show_faq]" value="1"<?= $checked('price_show_faq') ?>> Tampilkan FAQ</label>
        <label for="price_faq">FAQ</label>
        <textarea id="price_faq" name="cfg[price_faq]" rows="8" placeholder="Satu tanya-jawab per baris: Pertanyaan | Jawaban"><?= e($v('price_faq')) ?></textarea>
        <p class="help">Format: <code>Pertanyaan | Jawaban</code> per baris. Kosongkan untuk FAQ bawaan.</p>

        <label class="checkbox"><input type="checkbox" name="cfg[price_show_testimonials]" value="1"<?= $checked('price_show_testimonials') ?>> Tampilkan testimoni</label>
        <label for="price_testimonials">Testimoni</label>
        <textarea id="price_testimonials" name="cfg[price_testimonials]" rows="6" placeholder="Satu testimoni per baris: Nama | Peran/Profesi | Kutipan"><?= e($v('price_testimonials')) ?></textarea>
        <p class="help">Format: <code>Nama | Peran | Kutipan</code> per baris. Kosongkan untuk menyembunyikan bagian testimoni.</p>

        <label class="checkbox"><input type="checkbox" name="cfg[price_show_contacts]" value="1"<?= $checked('price_show_contacts') ?>> Tampilkan kontak</label>
        <div class="row">
            <div>
                <label for="price_contact_email">Email</label>
                <input id="price_contact_email" type="email" name="cfg[price_contact_email]" value="<?= e($v('price_contact_email')) ?>">
            </div>
            <div>
                <label for="price_contact_whatsapp">WhatsApp</label>
                <input id="price_contact_whatsapp" type="text" name="cfg[price_contact_whatsapp]" value="<?= e($v('price_contact_whatsapp')) ?>" placeholder="0812...">
            </div>
        </div>
        <label for="price_contact_address">Alamat / catatan kontak</label>
        <textarea id="price_contact_address" name="cfg[price_contact_address]" rows="2"><?= e($v('price_contact_address')) ?></textarea>
    </div>

    <div class="tools-actions">
        <button type="submit">Simpan Halaman Penjualan</button>
    </div>
</form>

<script>
// Gabungkan checkbox template CV menjadi satu nilai CSV sebelum submit.
document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
        var hidden = form.querySelector('#tool_cv_templates');
        if (!hidden) return;
        var picked = Array.prototype.map.call(
            form.querySelectorAll('input[name^="cfg[tool_cv_templates_"]:checked'),
            function (el) { return el.value; }
        );
        hidden.value = picked.join(',');
        // Buang checkbox pembantu agar tidak masuk payload.
        form.querySelectorAll('input[name^="cfg[tool_cv_templates_"]').forEach(function (el) { el.disabled = true; });
        hidden.disabled = false;
    });
});
</script>
</div>
