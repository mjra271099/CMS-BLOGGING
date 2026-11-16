<?php
declare(strict_types=1);

/**
 * Halaman QR Code Generator (template halaman `qr-generator`).
 *
 * Markup saja — gaya ada di assets/front/qr-generator.css dan perilakunya di
 * assets/front/qr-generator.js; keduanya dimuat hanya untuk template ini.
 *
 * @var string $directUrl  Direct Link iklan (opsional) dari Pengaturan situs.
 * @var string $kicker     Label kecil di atas deskripsi.
 * @var string $intro      Deskripsi singkat halaman.
 */

$directUrl = (string)($directUrl ?? '');
$config    = (string)($config ?? '');
$formats   = is_array($formats ?? null) ? $formats : ['png','jpeg','webp','svg'];
$sizes     = is_array($sizes ?? null) ? $sizes : [256,512,1024,2048];
$ecc       = (string)($ecc ?? 'H');
$kicker    = (string)($kicker ?? 'Alat gratis');
$intro     = (string)($intro ?? 'Buat QR Code untuk URL, WhatsApp, teks, email, WiFi, vCard, dan lainnya — lalu unduh sebagai PNG, JPG, WEBP, atau SVG.');
?>
<div class="qr-app" data-direct-url="<?= e($directUrl) ?>"<?= $config ?>>
  <div class="qr-page-head">
    <span class="qr-kicker"><?= e($kicker) ?></span>
    <p><?= e($intro) ?></p>
  </div>

    <div class="layout">

      <main class="card form-card" id="form">

        <div class="tabs" id="typeTabs" role="tablist" aria-label="Jenis konten QR"></div>

        <button type="button" class="btn secondary smart-paste" id="smartPaste">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
            <rect x="8" y="2" width="8" height="4" rx="1"/>
          </svg>
          Tempel dari clipboard &amp; deteksi otomatis
        </button>

        <!-- URL -->
        <div class="panel active" data-type="url">
          <div class="field">
            <label for="f-url">URL Website</label>
            <input id="f-url" type="url" inputmode="url" placeholder="https://contoh.com/video"
                   autocomplete="off" spellcheck="false">
          </div>
          <p class="note">Ketik domain tanpa <b>https://</b> juga bisa — akan dilengkapi otomatis.</p>
        </div>

        <!-- WHATSAPP -->
        <div class="panel" data-type="wa">
          <div class="field">
            <label for="f-wa">Tautan WhatsApp</label>
            <input id="f-wa" type="url" inputmode="url" placeholder="https://wa.me/628123456789?text=Halo"
                   autocomplete="off" spellcheck="false">
          </div>
          <details class="helper">
            <summary>Bantu buat tautan dari nomor</summary>
            <div class="inner">
              <div class="field">
                <label for="f-wa-num">Nomor WhatsApp</label>
                <input id="f-wa-num" type="tel" inputmode="tel" placeholder="081234567890" autocomplete="off">
              </div>
              <div class="field">
                <label for="f-wa-msg">Pesan otomatis (opsional)</label>
                <textarea id="f-wa-msg">Halo, saya ingin bertanya.</textarea>
              </div>
              <button type="button" class="btn secondary" id="buildWa">Isi Tautan Otomatis</button>
            </div>
          </details>
          <p class="note">Nomor <b>08…</b> otomatis diubah menjadi <b>62…</b>.</p>
        </div>

        <!-- TEKS -->
        <div class="panel" data-type="text">
          <div class="field">
            <label for="f-text">Teks / Catatan</label>
            <textarea id="f-text" style="min-height:120px">Halo dunia!</textarea>
          </div>
          <p class="note">Cocok untuk catatan, kupon, atau pesan singkat.</p>
        </div>

        <!-- EMAIL -->
        <div class="panel" data-type="email">
          <div class="field">
            <label for="f-email-to">Alamat email tujuan</label>
            <input id="f-email-to" type="email" inputmode="email" placeholder="nama@contoh.com"
                   autocomplete="off" spellcheck="false">
          </div>
          <div class="field">
            <label for="f-email-sub">Subjek (opsional)</label>
            <input id="f-email-sub" type="text" placeholder="Halo" autocomplete="off">
          </div>
          <div class="field">
            <label for="f-email-body">Isi pesan (opsional)</label>
            <textarea id="f-email-body" placeholder="Tulis pesan…"></textarea>
          </div>
        </div>

        <!-- TELEPON -->
        <div class="panel" data-type="tel">
          <div class="field">
            <label for="f-tel">Nomor telepon</label>
            <input id="f-tel" type="tel" inputmode="tel" placeholder="+628123456789" autocomplete="off">
          </div>
          <p class="note">Pemindai akan langsung menawarkan panggilan.</p>
        </div>

        <!-- SMS -->
        <div class="panel" data-type="sms">
          <div class="field">
            <label for="f-sms-num">Nomor tujuan</label>
            <input id="f-sms-num" type="tel" inputmode="tel" placeholder="+628123456789" autocomplete="off">
          </div>
          <div class="field">
            <label for="f-sms-msg">Isi SMS (opsional)</label>
            <textarea id="f-sms-msg" placeholder="Tulis pesan…"></textarea>
          </div>
        </div>

        <!-- WIFI -->
        <div class="panel" data-type="wifi">
          <div class="field">
            <label for="f-wifi-ssid">Nama WiFi (SSID)</label>
            <input id="f-wifi-ssid" type="text" placeholder="WiFi Rumah" autocomplete="off" spellcheck="false">
          </div>
          <div class="field">
            <label for="f-wifi-pass">Kata sandi</label>
            <input id="f-wifi-pass" type="text" placeholder="••••••••" autocomplete="off" spellcheck="false">
          </div>
          <div class="field">
            <label for="f-wifi-enc">Keamanan</label>
            <select id="f-wifi-enc">
              <option value="WPA" selected>WPA / WPA2 / WPA3</option>
              <option value="WEP">WEP</option>
              <option value="nopass">Tanpa kata sandi</option>
            </select>
          </div>
          <label class="switch"><input type="checkbox" id="f-wifi-hidden"><span>Jaringan tersembunyi</span></label>
        </div>

        <!-- VCARD -->
        <div class="panel" data-type="vcard">
          <div class="field">
            <label for="f-vc-name">Nama lengkap *</label>
            <input id="f-vc-name" type="text" placeholder="Budi Santoso" autocomplete="off">
          </div>
          <div class="field">
            <label for="f-vc-tel">Telepon</label>
            <input id="f-vc-tel" type="tel" inputmode="tel" placeholder="+628123456789" autocomplete="off">
          </div>
          <div class="field">
            <label for="f-vc-email">Email</label>
            <input id="f-vc-email" type="email" inputmode="email" placeholder="budi@contoh.com"
                   autocomplete="off" spellcheck="false">
          </div>
          <div class="field">
            <label for="f-vc-org">Organisasi / Perusahaan</label>
            <input id="f-vc-org" type="text" placeholder="PT Contoh Jaya" autocomplete="off">
          </div>
          <div class="field">
            <label for="f-vc-title">Jabatan</label>
            <input id="f-vc-title" type="text" placeholder="Manajer" autocomplete="off">
          </div>
          <div class="field">
            <label for="f-vc-url">Website</label>
            <input id="f-vc-url" type="url" inputmode="url" placeholder="https://contoh.com"
                   autocomplete="off" spellcheck="false">
          </div>
          <div class="field">
            <label for="f-vc-addr">Alamat</label>
            <textarea id="f-vc-addr" placeholder="Jl. Contoh No. 1, Jakarta"></textarea>
          </div>
        </div>

        <!-- LOKASI -->
        <div class="panel" data-type="loc">
          <div class="field">
            <label for="f-lat">Latitude</label>
            <input id="f-lat" type="text" inputmode="decimal" placeholder="-6.200000" autocomplete="off" spellcheck="false">
          </div>
          <div class="field">
            <label for="f-lng">Longitude</label>
            <input id="f-lng" type="text" inputmode="decimal" placeholder="106.816666" autocomplete="off" spellcheck="false">
          </div>
          <p class="note">Koordinat bisa disalin dari Google Maps (klik kanan → salin angka).</p>
        </div>

        <!-- DESAIN -->
        <details class="design" open>
          <summary>🎨 Gaya, Warna &amp; Logo</summary>
          <div class="inner">
            <div class="presets" id="presets"></div>

            <div class="dgrid">
              <div class="field">
                <label for="c1">Warna QR</label>
                <input type="color" id="c1" value="#2563eb">
              </div>
              <div class="field">
                <label for="c2">Warna latar</label>
                <input type="color" id="c2" value="#ffffff">
              </div>
              <div class="field">
                <label for="c3">Gradien (warna ke-2)</label>
                <input type="color" id="c3" value="#7c3aed" disabled>
              </div>
              <div class="field">
                <label>Gradien</label>
                <label class="switch"><input type="checkbox" id="gradOn"><span>Aktifkan</span></label>
              </div>

              <div class="field">
                <label for="dotStyle">Gaya titik</label>
                <select id="dotStyle">
                  <option value="square">Kotak</option>
                  <option value="rounded" selected>Membulat</option>
                  <option value="dots">Titik bulat</option>
                  <option value="classy">Classy</option>
                  <option value="classy-rounded">Classy rounded</option>
                  <option value="extra-rounded">Sangat bulat</option>
                </select>
              </div>
              <div class="field">
                <label for="cornerSq">Sudut luar</label>
                <select id="cornerSq">
                  <option value="square">Kotak</option>
                  <option value="extra-rounded" selected>Membulat</option>
                  <option value="dot">Bulat</option>
                </select>
              </div>
              <div class="field">
                <label for="cornerDot">Sudut dalam</label>
                <select id="cornerDot">
                  <option value="square">Kotak</option>
                  <option value="dot" selected>Bulat</option>
                </select>
              </div>
              <div class="field">
                <label for="logoSize">Ukuran logo — <span id="logoSizeVal">40%</span></label>
                <input type="range" id="logoSize" min="25" max="50" step="1" value="40">
              </div>

              <div class="field span2">
                <label for="logo">Logo di tengah</label>
                <select id="logo">
                  <option value="auto" selected>Otomatis (sesuai jenis)</option>
                  <option value="globe">Globe / Website</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="instagram">Instagram</option>
                  <option value="shop">Toko / Belanja</option>
                  <option value="play">Tombol Play</option>
                  <option value="mail">Email</option>
                  <option value="phone">Telepon</option>
                  <option value="wifi">WiFi</option>
                  <option value="pin">Lokasi</option>
                  <option value="heart">Hati</option>
                  <option value="star">Bintang</option>
                  <option value="custom">Unggah logo sendiri…</option>
                  <option value="none">Tanpa logo</option>
                </select>
              </div>

              <div class="field span2 hidden" id="uploadWrap">
                <label for="logoFile">Berkas logo (PNG/JPG — latar transparan lebih baik)</label>
                <input id="logoFile" type="file" accept="image/*">
                <p class="note" style="margin-top:6px">💡 Bisa juga <b>tarik &amp; lepas</b> gambar ke area ini.</p>
              </div>
            </div>

            <p class="note">Tips: logo di atas <b>40%</b> bisa menyulitkan pemindaian. Selalu uji pindai sebelum dicetak.</p>
          </div>
        </details>

        <button type="button" class="btn generate-btn" id="generateBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1.5"/>
            <rect x="14" y="3" width="7" height="7" rx="1.5"/>
            <rect x="3" y="14" width="7" height="7" rx="1.5"/>
            <path d="M14 14h3v3h-3zM18 18h3v3h-3z"/>
          </svg>
          Generate QR Code
        </button>

      </main>

      <aside class="card preview-card" id="preview" tabindex="-1">
        <div class="preview-head">
          <h2>👁️ Pratinjau</h2>
          <span class="badge" id="statusBadge">Menunggu</span>
        </div>

        <div class="qrbox"><div id="qr"><div class="ph">Memuat…</div></div></div>
        <div class="status" id="status"></div>

        <div class="field">
          <label for="dlSize">Ukuran unduhan</label>
          <select id="dlSize">
            <?php foreach ($sizes as $sz): ?>
              <option value="<?= (int)$sz ?>"<?= (int)$sz === 512 ? ' selected' : '' ?>><?= (int)$sz ?> × <?= (int)$sz ?> px<?= (int)$sz >= 2048 ? ' (cetak)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="dl">
          <?php $labels = ['png' => 'PNG', 'jpeg' => 'JPG', 'webp' => 'WEBP', 'svg' => 'SVG'];
          foreach ($formats as $i => $fmt): if (!isset($labels[$fmt])) continue; ?>
            <button type="button" class="btn<?= $i === 0 ? '' : ' secondary' ?> fmt" data-fmt="<?= e($fmt) ?>"><?= e($labels[$fmt]) ?></button>
          <?php endforeach; ?>
        </div>

        <p class="note"><b>SVG</b> paling tajam untuk cetak besar. <b>PNG</b> untuk media sosial &amp; dokumen.</p>

        <details class="helper">
          <summary>Lihat isi QR (teks terenkode)</summary>
          <div class="inner">
            <div class="input-wrap raw-wrap">
              <textarea id="rawData" readonly rows="3" placeholder="Isi QR akan muncul di sini…"></textarea>
              <button type="button" class="input-btn" id="copyRaw" aria-label="Salin isi QR">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="9" y="9" width="13" height="13" rx="2"/>
                  <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
              </button>
            </div>
          </div>
        </details>
      </aside>

    </div>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>
</div>

<!-- Popup dukungan sukarela (QRIS DANA). Isi diatur dari Admin → Tools. -->
<div class="sa-modal" id="qrSupport" role="dialog" aria-modal="true" aria-labelledby="qrSupportTitle" hidden>
  <div class="sa-modal-card sa-modal-wide">
    <button type="button" class="sa-modal-close" id="qrSupportClose" aria-label="Tutup">×</button>
    <h2 id="qrSupportTitle">Dukung Pengembangan Tools Ini</h2>
    <p id="qrSupportDesc">QR Code Generator ini gratis. Dukungan sukarela membantu biaya server dan pengembangan fitur berikutnya.</p>
    <div class="sa-qris" hidden>
      <div class="sa-qris-frame" id="qrSupportZoom" role="button" tabindex="0" aria-label="Perbesar gambar QRIS">
        <img id="qrSupportImage" src="" alt="QRIS DANA" loading="lazy">
        <span class="sa-qris-zoom">🔍 Perbesar</span>
      </div>
      <a class="sa-btn" id="qrSupportOpen" href="#" target="_blank" rel="noopener" hidden style="font-size:.82rem;padding:7px 12px">Buka gambar ukuran penuh</a>
    </div>
    <p class="sa-note" style="margin:0 0 10px">Pindai QRIS di atas dengan aplikasi e-wallet atau mobile banking. Ketuk gambar untuk memperbesar. Dukungan sepenuhnya sukarela — QR Code tetap bisa diunduh tanpa membayar.</p>
    <div class="sa-modal-actions">
      <a class="sa-btn primary" id="qrSupportLink" href="#" target="_blank" rel="noopener" hidden>Buka Halaman Dukungan</a>
      <button type="button" class="sa-btn" id="qrSupportLater">Nanti Dulu</button>
    </div>
  </div>
</div>

<!-- Lightbox QRIS: gambar ukuran penuh agar kode jelas terbaca -->
<div class="sa-lightbox" id="qrLightbox" hidden role="dialog" aria-modal="true" aria-label="QRIS ukuran penuh">
  <button type="button" class="sa-lightbox-close" id="qrLightboxClose" aria-label="Tutup">×</button>
  <img id="qrLightboxImage" src="" alt="QRIS DANA ukuran penuh">
  <div class="sa-lightbox-bar">
    <a class="sa-btn" id="qrLightboxOpen" href="#" target="_blank" rel="noopener">Buka di tab baru</a>
    <button type="button" class="sa-btn" id="qrLightboxDismiss">Tutup</button>
  </div>
</div>
