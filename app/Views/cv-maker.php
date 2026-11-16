<?php
declare(strict_types=1);

/**
 * CV & Resume Maker — markup aplikasi.
 *
 * Struktur dua panel: formulir (kiri) dan pratinjau kertas (kanan). Semua teks
 * template diambil dari satu sumber data (state di localStorage), sehingga
 * berganti desain tidak menghilangkan isian.
 *
 * Gaya: assets/front/cv-maker.css — perilaku: assets/front/cv-maker.js
 *
 * @var string       $title
 * @var string       $desc
 * @var array<int,string> $templates
 */

$templates = is_array($templates ?? null) ? $templates : ['modern'];
?>
<div class="cv-app" id="cvApp">

  <div class="cv-toolbar">
    <div class="cv-toolbar-text">
      <p class="cv-kicker">Alat gratis · tanpa login</p>
      <h1><?= e((string)$title) ?></h1>
      <p class="cv-lead"><?= e((string)$desc) ?></p>
    </div>
    <div class="cv-toolbar-actions">
      <button type="button" class="sa-btn" id="cvPreviewToggle" aria-pressed="false">Pratinjau</button>
      <button type="button" class="sa-btn primary" id="cvDownload">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M5 21h14"/></svg>
        Download PDF
      </button>
    </div>
  </div>

  <div class="cv-workspace">

    <!-- ================= FORM ================= -->
    <form class="cv-form" id="cvForm" novalidate autocomplete="on">

      <div class="cv-presets">
        <div class="cv-field">
          <label for="cvTpl">Desain CV</label>
          <select id="cvTpl"></select>
        </div>
        <div class="cv-field">
          <label for="cvPaperSize">Ukuran kertas</label>
          <select id="cvPaperSize">
            <option value="a4">A4 — 210 × 297 mm</option>
            <option value="letter">Letter — 216 × 279 mm</option>
            <option value="legal">Legal — 216 × 356 mm</option>
            <option value="custom">Kustom…</option>
          </select>
        </div>
        <div class="cv-field cv-custom-size" id="cvCustomSize" hidden>
          <label for="cvPaperW">Lebar (mm)</label>
          <input id="cvPaperW" type="number" min="105" max="500" step="1" value="210" inputmode="numeric" placeholder="210">
          <label for="cvPaperH">Tinggi (mm)</label>
          <input id="cvPaperH" type="number" min="148" max="600" step="1" value="297" inputmode="numeric" placeholder="297">
        </div>
      </div>

      <div class="cv-notice" id="cvFitNotice" role="status" hidden></div>

      <!-- 1. Informasi pribadi -->
      <details class="cv-sec" open>
        <summary><span class="cv-sec-num">1</span> Informasi Pribadi</summary>
        <div class="cv-sec-body">
          <div class="cv-grid">
            <div class="cv-field span2">
              <label for="p_name">Nama lengkap <span class="req">*</span></label>
              <input id="p_name" type="text" data-bind="name" placeholder="Andini Prameswari" maxlength="80" required>
              <p class="cv-hint">Nama panjang otomatis menyesuaikan ukuran font agar tetap dalam satu baris.</p>
            </div>
            <div class="cv-field span2">
              <label for="p_role">Jabatan / posisi yang dilamar</label>
              <input id="p_role" type="text" data-bind="role" placeholder="Senior Product Designer & Frontend Engineer" maxlength="90">
            </div>
            <div class="cv-field">
              <label for="p_email">Email</label>
              <input id="p_email" type="email" data-bind="email" placeholder="andini.prameswari@contoh.com" maxlength="120" autocomplete="email">
            </div>
            <div class="cv-field">
              <label for="p_phone">Telepon</label>
              <input id="p_phone" type="tel" data-bind="phone" placeholder="+62 812 3456 7890" maxlength="40" autocomplete="tel">
            </div>
            <div class="cv-field">
              <label for="p_city">Kota / lokasi</label>
              <input id="p_city" type="text" data-bind="city" placeholder="Jakarta Selatan, Indonesia" maxlength="80">
            </div>
            <div class="cv-field">
              <label for="p_link">Website / LinkedIn / Portofolio</label>
              <input id="p_link" type="text" data-bind="link" placeholder="linkedin.com/in/andiniprameswari" maxlength="120">
            </div>
          </div>
        </div>
      </details>

      <!-- 2. Foto profil -->
      <details class="cv-sec"<?= in_array('modern', $templates, true) || in_array('minimal', $templates, true) ? '' : ' hidden' ?> data-photo-section>
        <summary><span class="cv-sec-num">2</span> Foto Profil <span class="cv-opt">(opsional)</span></summary>
        <div class="cv-sec-body">
          <div class="cv-photo-row">
            <div class="cv-photo-preview" id="cvPhotoPreview" aria-hidden="true">
              <span>Foto</span>
            </div>
            <div class="cv-photo-actions">
              <label class="sa-btn" for="cvPhotoInput">Pilih foto</label>
              <input id="cvPhotoInput" type="file" accept="image/png,image/jpeg,image/webp" hidden>
              <button type="button" class="sa-btn" id="cvPhotoClear">Hapus foto</button>
              <p class="cv-hint">JPG/PNG, maks 2 MB. Foto disimpan di peramban Anda, tidak diunggah ke server. Desain ATS sebaiknya tanpa foto.</p>
            </div>
          </div>
        </div>
      </details>

      <!-- 3. Ringkasan profesional -->
      <details class="cv-sec" open>
        <summary><span class="cv-sec-num">3</span> Ringkasan Profesional</summary>
        <div class="cv-sec-body">
          <div class="cv-field">
            <label for="p_summary">Ringkasan (2–4 kalimat)</label>
            <textarea id="p_summary" rows="4" data-bind="summary" maxlength="900" placeholder="Product Designer & Frontend Engineer dengan 8 tahun pengalaman membangun produk digital berskala besar. Terbiasa memimpin tim lintas fungsi dan meningkatkan konversi melalui riset pengguna."></textarea>
            <p class="cv-hint"><span data-count="summary">0</span>/900 karakter</p>
          </div>
        </div>
      </details>

      <!-- 4. Pengalaman kerja -->
      <details class="cv-sec" open>
        <summary><span class="cv-sec-num">4</span> Pengalaman Kerja</summary>
        <div class="cv-sec-body">
          <div class="cv-list" data-list="experience"></div>
          <button type="button" class="sa-btn" data-add="experience">+ Tambah pengalaman</button>
        </div>
      </details>

      <!-- 5. Pendidikan -->
      <details class="cv-sec">
        <summary><span class="cv-sec-num">5</span> Pendidikan</summary>
        <div class="cv-sec-body">
          <div class="cv-list" data-list="education"></div>
          <button type="button" class="sa-btn" data-add="education">+ Tambah pendidikan</button>
        </div>
      </details>

      <!-- 6. Keterampilan -->
      <details class="cv-sec">
        <summary><span class="cv-sec-num">6</span> Keterampilan</summary>
        <div class="cv-sec-body">
          <div class="cv-field">
            <label for="p_skills">Daftar keterampilan</label>
            <textarea id="p_skills" rows="2" data-bind="skills" maxlength="400" placeholder="Figma, Design System, React, TypeScript, User Research, A/B Testing"></textarea>
            <p class="cv-hint">Pisahkan dengan koma. Ditampilkan sebagai tag agar hemat ruang.</p>
          </div>
        </div>
      </details>

      <!-- 7. Sertifikasi -->
      <details class="cv-sec">
        <summary><span class="cv-sec-num">7</span> Sertifikasi &amp; Pelatihan</summary>
        <div class="cv-sec-body">
          <div class="cv-list" data-list="certs"></div>
          <button type="button" class="sa-btn" data-add="certs">+ Tambah sertifikasi</button>
        </div>
      </details>

      <!-- 8. Bahasa -->
      <details class="cv-sec">
        <summary><span class="cv-sec-num">8</span> Bahasa</summary>
        <div class="cv-sec-body">
          <div class="cv-list" data-list="languages"></div>
          <button type="button" class="sa-btn" data-add="languages">+ Tambah bahasa</button>
        </div>
      </details>

      <!-- 9. Proyek -->
      <details class="cv-sec">
        <summary><span class="cv-sec-num">9</span> Proyek</summary>
        <div class="cv-sec-body">
          <div class="cv-list" data-list="projects"></div>
          <button type="button" class="sa-btn" data-add="projects">+ Tambah proyek</button>
        </div>
      </details>

      <!-- 10. Penghargaan -->
      <details class="cv-sec">
        <summary><span class="cv-sec-num">10</span> Penghargaan</summary>
        <div class="cv-sec-body">
          <div class="cv-list" data-list="awards"></div>
          <button type="button" class="sa-btn" data-add="awards">+ Tambah penghargaan</button>
        </div>
      </details>

      <!-- 11. Informasi tambahan -->
      <details class="cv-sec">
        <summary><span class="cv-sec-num">11</span> Informasi Tambahan</summary>
        <div class="cv-sec-body">
          <div class="cv-field">
            <label for="p_extra">Catatan tambahan</label>
            <textarea id="p_extra" rows="3" data-bind="extra" maxlength="600" placeholder="Tersedia mulai 1 bulan ke depan · Bersedia bekerja hybrid di Jakarta · Portofolio tersedia atas permintaan."></textarea>
          </div>
        </div>
      </details>

      <div class="cv-form-foot">
        <button type="button" class="sa-btn" id="cvReset">Hapus semua data</button>
        <span class="cv-muted" id="cvSaveState">Tersimpan otomatis di peramban</span>
      </div>
    </form>

    <!-- ================= PREVIEW ================= -->
    <aside class="cv-preview" id="cvPreview" aria-label="Pratinjau CV">
      <div class="cv-preview-bar">
        <span class="cv-preview-title">Pratinjau</span>
        <span class="cv-demo-badge" id="cvDemoBadge" hidden>Contoh</span>
        <div class="cv-zoom" role="group" aria-label="Zoom pratinjau">
          <button type="button" data-zoom="fit" aria-pressed="true">Muat</button>
          <button type="button" data-zoom="full" aria-pressed="false">100%</button>
        </div>
        <span class="cv-page-count" id="cvPageCount">1 halaman</span>
        <button type="button" class="sa-btn only-mobile" id="cvPreviewClose">Tutup</button>
      </div>
      <div class="cv-stage" id="cvStage">
        <div class="cv-pages" id="cvPages" aria-live="polite"></div>
      </div>
    </aside>
  </div>

  <!-- Bilah aksi cepat khusus mobile: selalu terjangkau saat menggulir form -->
  <div class="cv-mobile-bar" aria-label="Aksi cepat">
    <button type="button" class="sa-btn" data-mobile-action="preview" aria-pressed="false">Pratinjau</button>
    <button type="button" class="sa-btn primary" data-mobile-action="download">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M5 21h14"/></svg>
      Download
    </button>
  </div>
</div>

<!-- Modal dukungan (diisi dari data-config) -->
<div class="sa-modal" id="cvSupport" role="dialog" aria-modal="true" aria-labelledby="cvSupportTitle" hidden>
  <div class="sa-modal-card sa-modal-wide">
    <button type="button" class="sa-modal-close" id="cvSupportClose" aria-label="Tutup">×</button>
    <h2 id="cvSupportTitle">Dukung Kami</h2>
    <p id="cvSupportDesc"></p>
    <div class="sa-qris" id="cvSupportQris" hidden>
      <div class="sa-qris-frame" id="cvSupportZoom" role="button" tabindex="0" aria-label="Perbesar gambar QRIS">
        <img id="cvSupportImage" src="" alt="QRIS dukungan" loading="lazy">
        <span class="sa-qris-zoom">🔍 Perbesar</span>
      </div>
      <a class="sa-btn" id="cvSupportOpen" href="#" target="_blank" rel="noopener" hidden style="font-size:.82rem;padding:7px 12px">Buka gambar ukuran penuh</a>
    </div>
    <p class="sa-note" id="cvSupportQrisNote" hidden style="margin:0 0 10px">Pindai QRIS di atas dengan aplikasi e-wallet atau mobile banking. Ketuk gambar untuk memperbesar. Dukungan sepenuhnya sukarela — CV tetap bisa diunduh tanpa membayar.</p>
    <div class="sa-modal-actions">
      <a class="sa-btn primary" id="cvSupportButton" href="#" target="_blank" rel="noopener" hidden>Dukung Kami</a>
      <button type="button" class="sa-btn" id="cvSupportLater">Nanti Dulu</button>
    </div>
  </div>
</div>

<!-- Lightbox QRIS: gambar ukuran penuh agar kode jelas terbaca -->
<div class="sa-lightbox" id="cvLightbox" hidden role="dialog" aria-modal="true" aria-label="QRIS ukuran penuh">
  <button type="button" class="sa-lightbox-close" id="cvLightboxClose" aria-label="Tutup">×</button>
  <img id="cvLightboxImage" src="" alt="QRIS dukungan ukuran penuh">
  <div class="sa-lightbox-bar">
    <a class="sa-btn" id="cvLightboxOpen" href="#" target="_blank" rel="noopener">Buka di tab baru</a>
    <button type="button" class="sa-btn" id="cvLightboxDismiss">Tutup</button>
  </div>
</div>

<!-- Item template untuk baris dinamis -->
<template id="tplEntryExperience">
  <div class="cv-item" data-item>
    <div class="cv-item-head"><strong data-item-title>Pengalaman</strong>
      <div class="cv-item-tools">
        <button type="button" class="cv-icon" data-move="up" title="Naikkan" aria-label="Naikkan">↑</button>
        <button type="button" class="cv-icon" data-move="down" title="Turunkan" aria-label="Turunkan">↓</button>
        <button type="button" class="cv-icon danger" data-remove title="Hapus" aria-label="Hapus">×</button>
      </div>
    </div>
    <div class="cv-grid">
      <div class="cv-field"><label>Posisi</label><input type="text" data-k="title" placeholder="Frontend Developer" maxlength="90"></div>
      <div class="cv-field"><label>Perusahaan</label><input type="text" data-k="company" placeholder="PT Contoh Jaya" maxlength="90"></div>
      <div class="cv-field"><label>Lokasi</label><input type="text" data-k="place" placeholder="Jakarta" maxlength="60"></div>
      <div class="cv-field"><label>Mulai</label><input type="text" data-k="start" placeholder="Jan 2021" maxlength="24"></div>
      <div class="cv-field"><label>Selesai</label><input type="text" data-k="end" placeholder="Sekarang" maxlength="24"></div>
      <div class="cv-field span2"><label>Deskripsi / pencapaian</label><textarea rows="3" data-k="desc" maxlength="900" placeholder="Satu poin per baris. Awali dengan kata kerja, sertakan angka hasil bila ada."></textarea></div>
    </div>
  </div>
</template>
<template id="tplEntryEducation">
  <div class="cv-item" data-item>
    <div class="cv-item-head"><strong data-item-title>Pendidikan</strong>
      <div class="cv-item-tools">
        <button type="button" class="cv-icon" data-move="up" title="Naikkan" aria-label="Naikkan">↑</button>
        <button type="button" class="cv-icon" data-move="down" title="Turunkan" aria-label="Turunkan">↓</button>
        <button type="button" class="cv-icon danger" data-remove title="Hapus" aria-label="Hapus">×</button>
      </div>
    </div>
    <div class="cv-grid">
      <div class="cv-field"><label>Jenjang / gelar</label><input type="text" data-k="degree" placeholder="S1 Teknik Informatika" maxlength="90"></div>
      <div class="cv-field"><label>Institusi</label><input type="text" data-k="school" placeholder="Universitas Indonesia" maxlength="90"></div>
      <div class="cv-field"><label>Lokasi</label><input type="text" data-k="place" placeholder="Depok" maxlength="60"></div>
      <div class="cv-field"><label>Mulai</label><input type="text" data-k="start" placeholder="2016" maxlength="24"></div>
      <div class="cv-field"><label>Selesai</label><input type="text" data-k="end" placeholder="2020" maxlength="24"></div>
      <div class="cv-field span2"><label>IPK / catatan</label><input type="text" data-k="note" placeholder="IPK 3.72 / 4.00" maxlength="120"></div>
    </div>
  </div>
</template>
<template id="tplEntryCerts">
  <div class="cv-item" data-item>
    <div class="cv-item-head"><strong data-item-title>Sertifikasi</strong>
      <div class="cv-item-tools">
        <button type="button" class="cv-icon" data-move="up" title="Naikkan" aria-label="Naikkan">↑</button>
        <button type="button" class="cv-icon" data-move="down" title="Turunkan" aria-label="Turunkan">↓</button>
        <button type="button" class="cv-icon danger" data-remove title="Hapus" aria-label="Hapus">×</button>
      </div>
    </div>
    <div class="cv-grid">
      <div class="cv-field span2"><label>Nama sertifikasi</label><input type="text" data-k="title" placeholder="AWS Certified Cloud Practitioner" maxlength="120"></div>
      <div class="cv-field"><label>Penerbit</label><input type="text" data-k="issuer" placeholder="Amazon Web Services" maxlength="90"></div>
      <div class="cv-field"><label>Tahun</label><input type="text" data-k="year" placeholder="2023" maxlength="24"></div>
    </div>
  </div>
</template>
<template id="tplEntryLanguages">
  <div class="cv-item" data-item>
    <div class="cv-item-head"><strong data-item-title>Bahasa</strong>
      <div class="cv-item-tools">
        <button type="button" class="cv-icon" data-move="up" title="Naikkan" aria-label="Naikkan">↑</button>
        <button type="button" class="cv-icon" data-move="down" title="Turunkan" aria-label="Turunkan">↓</button>
        <button type="button" class="cv-icon danger" data-remove title="Hapus" aria-label="Hapus">×</button>
      </div>
    </div>
    <div class="cv-grid">
      <div class="cv-field"><label>Bahasa</label><input type="text" data-k="name" placeholder="Bahasa Inggris" maxlength="60"></div>
      <div class="cv-field"><label>Tingkat</label>
        <select data-k="level">
          <option value="">Pilih tingkat</option>
          <option>Dasar</option>
          <option>Menengah</option>
          <option>Mahir</option>
          <option>Profesional</option>
          <option>Bahasa ibu</option>
        </select>
      </div>
    </div>
  </div>
</template>
<template id="tplEntryProjects">
  <div class="cv-item" data-item>
    <div class="cv-item-head"><strong data-item-title>Proyek</strong>
      <div class="cv-item-tools">
        <button type="button" class="cv-icon" data-move="up" title="Naikkan" aria-label="Naikkan">↑</button>
        <button type="button" class="cv-icon" data-move="down" title="Turunkan" aria-label="Turunkan">↓</button>
        <button type="button" class="cv-icon danger" data-remove title="Hapus" aria-label="Hapus">×</button>
      </div>
    </div>
    <div class="cv-grid">
      <div class="cv-field"><label>Nama proyek</label><input type="text" data-k="title" placeholder="Design System &quot;Nusa UI&quot;" maxlength="90"></div>
      <div class="cv-field"><label>Peran / tahun</label><input type="text" data-k="meta" placeholder="Lead Designer · 2023" maxlength="60"></div>
      <div class="cv-field span2"><label>Deskripsi</label><textarea rows="2" data-k="desc" maxlength="600" placeholder="Masalah, solusi, teknologi, dan hasil terukur."></textarea></div>
    </div>
  </div>
</template>
<template id="tplEntryAwards">
  <div class="cv-item" data-item>
    <div class="cv-item-head"><strong data-item-title>Penghargaan</strong>
      <div class="cv-item-tools">
        <button type="button" class="cv-icon" data-move="up" title="Naikkan" aria-label="Naikkan">↑</button>
        <button type="button" class="cv-icon" data-move="down" title="Turunkan" aria-label="Turunkan">↓</button>
        <button type="button" class="cv-icon danger" data-remove title="Hapus" aria-label="Hapus">×</button>
      </div>
    </div>
    <div class="cv-grid">
      <div class="cv-field span2"><label>Nama penghargaan</label><input type="text" data-k="title" placeholder="Juara 1 Hackathon Nasional" maxlength="120"></div>
      <div class="cv-field"><label>Penerbit</label><input type="text" data-k="issuer" placeholder="Indonesia Design Award" maxlength="90"></div>
      <div class="cv-field"><label>Tahun</label><input type="text" data-k="year" placeholder="2023" maxlength="24"></div>
    </div>
  </div>
</template>
