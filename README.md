# CMS Blogging Multi-Blog (setara Blogger)

Platform blogging PHP modern yang dibangun di atas fondasi CMS lama: satu kode basis untuk banyak blog, banyak pengguna, tema folder-based, widget engine, page builder, SEO lengkap, dan dashboard admin baru. Semua URL Blogger lama tetap berfungsi, dan data lama dapat diupgrade tanpa menghapus isi.

Aset Blogger asli (`assets/template-source.xml`) tidak diubah; CSS template lama diekstrak ke `assets/template.css`.

## Fitur

- **Multi-blog & multi-user** — tabel `blogs`, `blog_users`, dan `Context` per-blog; setiap posting, halaman, kategori, tag, komentar, media, widget, dan pengaturan ter-scope `blog_id`. Pengguna bisa punya peran berbeda di tiap blog (`owner`, `admin`, `editor`, `author`, `contributor`).
- **Dua tema bawaan: AMP Theme dan Schema Theme** — tema folder-based dengan layout, CSS, screenshot, dan `functions.php` opsional. Palet, radius, tipografi, spasi, dan gaya komponen berasal dari `assets/front/system.css`, jadi keduanya berbagi satu bahasa desain; yang berbeda token warna/radius dan tata letaknya. Aktifkan, duplikat, ekspor, atau impor ZIP dari Admin → Tema.
- **Kustomisasi tema** — skema pengaturan per tema (`color`, `select`, `checkbox`, `text`), disimpan per blog di `theme_settings`, dan dipancarkan sebagai CSS variable (`--t-*`) tanpa menimpa file tema.
- **Widget engine** — 20+ tipe widget dengan visibilitas (per halaman, perangkat, peran), urutan drag-and-drop, duplikasi, serta area widget per tema (`header`, `sidebar`, `footer-1..3`, `before-content`, `ad-top`, dan lainnya).
- **Page builder** — 18 tipe blok (hero, heading, teks, gambar, galeri, tombol, CTA, kolom, fitur, video, kutipan, pemisah, spacer, HTML, postingan, FAQ, testimoni, newsletter) yang dikembalikan sebagai JSON tervalidasi lalu dirender sebagai HTML tersanitasi. Pratinjau iframe langsung (WYSIWYG) memakai tema aktif, lengkap dengan kontrol latar/jarak/lebar per bagian, tombol lebar perangkat, dan mode pratinjau besar.
- **Dua bahasa antarmuka** — Bahasa Indonesia dan English untuk seluruh halaman depan (navigasi, arsip, pencarian, artikel, komentar, 404, tanggal/bulan, waktu relatif). Bahasa default diatur di Admin → Pengaturan; pengunjung dapat berganti lewat tombol ID/EN di header (disimpan di cookie `site_language`). Katalog string ada di `app/Lang/id.php` dan `app/Lang/en.php`, dipakai melalui helper global `__()`.
- **SEO** — meta title/description, canonical, Open Graph/Twitter, sitemap XML, robots.txt, RSS feed, redirect manager, dan audit SEO dengan skor. Mesin JSON-LD `App\Services\StructuredData` mendukung seluruh **25 jenis Structured Data Google Search Gallery** (Article, Breadcrumb, Carousel, Course list, Dataset, Discussion Forum, Education Q&A, Employer Aggregate Rating, Event, Image Metadata, Job Posting, Local Business, Math Solver, Movie, Organization, Product, Profile Page, Q&A Page, Recipe, Review Snippet, Software App, Speakable, Subscription, Vacation Rental, Video) dan hanya memancarkan node yang datanya benar-benar ada.
- **Media library** — unggah gambar (JPG/PNG/GIF/WebP) dengan thumbnail otomatis, metadata alt/caption/folder, resize, dan penggantian file.
- **Tools mandiri & landing penjualan** — CV/Resume Maker, QR Code Generator, dan halaman penjualan script CMS yang berjalan sebagai halaman mandiri (`/cv-maker`, `/qr-code-generator`, `/jual-script-cms`) tanpa navbar/footer tema, dengan tombol Home ke beranda. Semua isinya diatur dari **Admin → Tools & Landing**: judul, deskripsi, desain CV aktif, format/ukuran/ECC QR, popup dukungan sukarela (termasuk QRIS), status monetisasi + Direct Link per alat, serta konten landing (harga, fitur, FAQ, kontak). Lihat [Tools & Landing](#tools--landing).
- **Halaman QR Code Generator** — fitur Page dengan template `qr-generator`: aplikasi QR (URL, WhatsApp, teks, email, telepon, WiFi, vCard, lokasi) yang berjalan di peramban, dengan unduhan PNG/JPG/WEBP/SVG. Aset halaman hanya dimuat di template itu, dan tombol unduh bisa diarahkan ke **Direct Link iklan** lewat Pengaturan situs.
- **Dashboard admin baru** — MVC (`AdminController` + `app/Views/admin/*`) menggantikan `admin/index.php` satu-file. Ringkasan, editor dengan autosave, kustomizer live preview, builder, moderasi komentar, pengaturan, backup, dan log aktivitas.
- **Keamanan** — CSRF pada semua form, escaping output, sanitasi HTML pada konten/kustom HTML, password hash `PASSWORD_DEFAULT`, cookie `HttpOnly`/`SameSite`, security headers, folder implementasi (`app/`, `database/`, `storage/`, `tests/`, `bin/`) dan berkas kredensial diblokir dari HTTP, serta eksekusi PHP di `uploads/` dilarang.
- **CLI** — `bin/console.php` untuk instalasi, migrasi, seed, sinkronisasi tema, pembuatan pengguna, pembersihan cache, dan status.

## Persyaratan

- PHP 8.1+ dengan ekstensi `pdo_mysql`, `gd`, `fileinfo`, `mbstring`, `json`.
- MySQL 5.7+ / MariaDB dengan InnoDB dan `utf8mb4`.
- Apache dengan `mod_rewrite`/`.htaccess` (shared hosting/cPanel). Untuk pengembangan lokal, PHP built-in server didukung via `router.php`.
- Folder proyek dapat ditulis sementara saat instalasi; `uploads/` dan `storage/` harus dapat ditulis PHP.

## Instalasi

### Web installer

1. Unggah seluruh folder ke hosting.
2. Buat database dan pengguna database, lalu beri hak akses penuh.
3. Buka `https://domain-anda/install.php` dan isi host, port, nama database, pengguna, password, URL situs, serta akun administrator (password minimal 12 karakter).
4. Installer menulis `config.php`, membuat skema v2 (25 tabel), mendaftarkan 2 tema bawaan (AMP Theme dan Schema Theme), mengaktifkan `amp-theme`, lalu mengarahkan ke `admin/index.php`.
5. Setelah selesai, hapus atau kunci kembali `install.php`.

### CLI

```sh
php bin/console.php install --name="Blog Saya" --url="https://contoh.com/" \
    --username=admin --email=admin@example.com --password=rahasia-kuat-123
php bin/console.php theme:sync
php bin/console.php seed            # contoh artikel (opsional)
php bin/console.php status
```

Konfigurasi dibaca dari `config.php`, atau dari berkas lain lewat variabel lingkungan `CMS_CONFIG` (mis. `CMS_CONFIG=config.local.php`). `config.sample.php` menyediakan contoh.

## Upgrade dari instalasi lama

Migrasi 001 (`database/migrations/001_modern_core.php`) mengupgrade skema lama **di tempat**, bukan menimpanya:

1. Backup database.
2. Unggah folder baru di atas instalasi lama (atau ke folder baru lalu salin `config.php`).
3. Jalankan:

```sh
php bin/console.php migrate
php bin/console.php theme:sync
```

Tabel `settings` lama diganti nama menjadi `settings_legacy_backup` dan data dipindahkan ke `settings` baru per blog. Tabel `schema_migrations` mencatat versi yang sudah dijalankan sehingga migrasi aman diulang.

## Struktur

```
app/
  Core/        Config, Database, Context, Settings, Cache, Auth, Csrf, Theme,
               ThemeEngine, Widget, Migrator, View, Response, Lang, Theme/Ui
  Models/      Post, Page, Taxonomy, Comment, Blog, Menu, Media
  Services/    Seo, Builder, Newsletter, Analytics, Backup, QrPage, StructuredData,
               ToolConfig, ToolAdminPage, CvMakerPage, SalesPage, SalesOrder
  Http/        FrontController, AdminController, AdminUi
  Lang/        id.php, en.php — katalog string antarmuka (Indonesia & English)
  Views/admin/ 27 template dashboard (termasuk tools.php)
  Views/front/qr-generator.php   markup aplikasi QR (dipakai template halaman & halaman mandiri)
  Views/cv-maker.php             markup CV/Resume Maker
  Views/sales.php                markup landing penjualan
  Views/standalone/  shell.php, sales-shell.php — dokumen mandiri tanpa chrome tema
  Http/Theme/Ui.php   kit UI yang dipakai semua tema
assets/
  front/base.css                   primitif front-end (reset, token, komponen dasar)
  front/system.css                 sistem desain bersama semua tema (gaya "AMP")
  front/standalone.css             kerangka halaman mandiri + gaya landing penjualan
  front/app.js                     skrip front-end
  front/qr-generator.css|.js        QR Code Generator (template qr-generator & /qr-code-generator)
  front/cv-maker.css|.js            CV/Resume Maker (/cv-maker)
  admin.css, admin-app.css/js      dashboard
  admin-editor.css                 lapisan tampilan editor kaya (Tiptap)
  admin-embed.js                   dukungan embed iframe di editor (token + pemulihan saat simpan)
themes/<slug>/   theme.json, layout.php, style.css, screenshot.svg, functions.php
resources/editor/editor.js         sumber editor; dibundel ke assets/vendor/tiptap/editor.js
database/        schema.sql, migrations/
tests/           run.php (integrasi), http.php (smoke test HTTP)
bin/console.php
index.php, admin/index.php, admin/tools.php, router.php, .htaccess
```

Legacy front files (`header.php`, `footer.php`, `sidebar.php`, `post.php`, `page.php`, `category.php`, `search.php`) tetap ada sebagai shim tipis yang mendelegasikan ke arsitektur `app/` agar tautan lama tetap bekerja.

## URL

| Halaman | URL |
|---|---|
| Artikel | `/YYYY/MM/slug.html` |
| Halaman | `/p/slug.html` |
| Label/kategori | `/search/label/<slug>` |
| Tag | `/tag/<slug>` (`/search/label/<slug>` tetap didukung untuk label & tag) |
| Pencarian | `/search?q=kata` |
| Arsip indeks | `/archive` (atau `/arsip`) — semua bulan dikelompokkan per tahun |
| Arsip bulan | `/archive/YYYY/MM` |
| Permalink numerik (legacy Blogger) | `/?p=<id>` → 301 ke URL artikel |
| Ganti bahasa | `/language/id`, `/language/en` (menyimpan cookie lalu kembali ke halaman asal) |
| Sitemap | `/sitemap.xml` |
| Feed | `/feed.xml`, `/feed/`, `/rss` |
| CV/Resume Maker | `/cv-maker` (halaman mandiri) |
| QR Code Generator | `/qr-code-generator` (halaman mandiri) — juga template halaman `qr-generator` di `/p/<slug>.html` |
| Landing penjualan CMS | `/jual-script-cms` (halaman mandiri) |
| Admin | `/admin/index.php?view=…` — Tools & Landing: `/admin/tools.php` |

## Tema

Tema adalah folder di `themes/<slug>/`:

```
theme.json     metadata: slug, name, version, author, category, supports,
               layouts, dan skema settings untuk kustomizer; blok opsional
               `structured_data` menyalakan grafik JSON-LD lanjutan
layout.php     struktur halaman; memanggil method kit UI ($ui->head(), $ui->header(),
               $ui->content()/contentInner(), $ui->sidebar(), $ui->footer(), dll.)
style.css      token identitas tema (--t-*). Gaya visual dasar (warna, radius,
               tipografi, bayangan) berasal dari assets/front/system.css, jadi
               semua tema seragam; aksen khas tema ditulis dengan awalan
               `.theme-<slug>` agar tidak bocor ke tema lain (lihat
               themes/schema-theme/style.css).
screenshot.svg pratinjau di galeri tema
structured-data.md  (opsional) pemetaan jenis Structured Data untuk tema
functions.php  opsional; hook PHP tema
```

Semua data diakses lewat kit `App\Theme\Ui`, sehingga tema pihak ketiga tidak pernah menyentuh database secara langsung dan aman dipasang dari ZIP.

Tema yang tidak mendeklarasikan `structured_data` tetap memancarkan grafik minimal (Organization, WebSite, artikel, breadcrumb) sehingga outputnya tidak berubah. Schema Theme menyalakan seluruh 25 jenis galeri Google: node otomatis dari data halaman, node dari setelan tema (alamat bisnis, rating, kategori film, paywall), dan node manual lewat `schema_site`/`schema_pages`. Rincian dan contoh JSON ada di `themes/schema-theme/structured-data.md`.

## Bahasa

Antarmuka depan mendukung **Bahasa Indonesia** dan **English**. Rantai penentuan bahasa: cookie `site_language` (pilihan pengunjung) → pengaturan `language` blog → default `id`. Tombol ID/EN menuju `/language/<kode>`, yang menyimpan cookie lalu mengembalikan pengunjung ke halaman asal. Di desktop tombol tampil di header; di bawah 860px tombol pindah ke dalam dropdown menu (lebar penuh) bersama kotak pencarian.

- Katalog string: `app/Lang/id.php` dan `app/Lang/en.php` (kunci harus sama di kedua berkas).
- Pemakaian di kode/tema: `e(__('read_more'))`, dengan penggantian placeholder `__('min_read', ['minutes' => 3])`.
- Bila kunci tidak ada di bahasa aktif, dipakai versi Indonesia, lalu kunci itu sendiri — string baru tidak akan mematahkan halaman.
- `<html lang>`, `og:locale`, nama bulan, dan waktu relatif ikut bahasa aktif.
- Dashboard admin tetap berbahasa Indonesia dan tidak terpengaruh cookie pengunjung.

Untuk menambah string: tambahkan pasangan kunci yang sama ke `app/Lang/id.php` dan `app/Lang/en.php`, lalu panggil lewat `__()` pada template/Ui/Widget.

## Tools & Landing

Tiga halaman mandiri tersedia dan semuanya diatur dari **Admin → Tools & Landing** (`admin/tools.php`). Halaman-halaman ini tidak memakai navbar, footer, atau sidebar tema: masing-masing dirender lewat `app/Views/standalone/shell.php` (token `assets/front/base.css` + `standalone.css`) dengan header ringkas dan tombol **Home** ke beranda. Routing ditangani satu rute `tool` di `FrontController`; bila alat dinonaktifkan lewat admin, URL-nya menjadi 404.

### CV / Resume Maker — `/cv-maker`

- Lima desain: **Modern** (dua kolom + foto), **Klasik Serif**, **Minimalis** (foto bulat), **ATS Friendly** (satu kolom, hitam-putih), dan **Timeline**. Desain yang aktif diatur admin; tiga desain asli selalu tersedia.
- **Layout satu halaman pintar**: `assets/front/cv-maker.js` mengukur tinggi isi lalu mengecilkan font secara bertahap sampai batas keterbacaan minimum, merapatkan spacing, dan mengatur margin. Bila konten tetap melebihi satu halaman, muncul peringatan jujur (bukan memaksa font terkecil yang tidak terbaca).
- Ukuran kertas A4/Letter/Legal/kustom. Unduhan memakai dialog cetak peramban dengan `@page size: <w>mm <h>mm; margin: 0`, sehingga pratinjau dan PDF identik dan sesuai ukuran kertas.
- 11 bagian dinamis (informasi pribadi, foto, ringkasan, pengalaman, pendidikan, keterampilan, sertifikasi, bahasa, proyek, penghargaan, tambahan), autosave ke `localStorage`, dan konfirmasi sebelum menghapus data/item. Semua data tetap di peramban — tidak ada yang dikirim ke server.

### QR Code Generator — `/qr-code-generator`

Berjalan juga sebagai template halaman CMS `qr-generator` (`/p/<slug>.html`) untuk kompatibilitas. Format unduhan, ukuran, dan tingkat ECC dibaca dari konfigurasi admin. Kode QR dirender di peramban (format PNG/JPG/WEBP/SVG).

### Landing penjualan script CMS — `/jual-script-cms`

Hero, deskripsi, fitur, tangkapan layar, teknologi, lisensi, harga, demo, FAQ, testimoni, CTA (**Lihat Demo / Beli Script CMS / Hubungi Kami**), dan kontak — semuanya diisi dari admin; bagian yang belum diisi tidak dirender (tidak ada klaim fitur yang tidak ada). Form kontak/pesan disimpan ke tabel `orders` yang dibuat otomatis saat pertama dipakai (`SalesOrder::ensureTable()`), ter-scope `blog_id`.

### Konfigurasi, dukungan, dan monetisasi

- **Penyimpanan**: `App\Services\ToolConfig` menyimpan semua nilai di tabel `settings` dengan prefix `tool_`/`price_` (per blog) — tidak ada tabel baru. `ToolConfig::save()` menghormati scope per form, jadi menyimpan form CV+QR tidak menimpa pengaturan landing, dan sebaliknya.
- **Popup dukungan sukarela** ("Dukung Kami" + "Nanti Dulu"): unduhan **tetap berjalan** bila pengguna memilih lewat. QRIS dapat diunggah/diganti dari admin (fallback ke `QR-DANA.jpeg` di root bila belum ada) dan tampil besar di popup — ketuk gambar untuk membuka lightbox ukuran penuh agar kode jelas terbaca.
- **Monetisasi per alat**: aktifkan monetisasi lalu isi **Direct Link** untuk sebuah alat; tautan dibuka di tab baru **hanya saat pengguna menekan unduh**, proses unduhan tetap berlanjut di halaman utama, dan tidak pernah ditampilkan ulang tanpa aksi pengguna. Konfigurasi terpisah untuk CV, QR, dan landing.

### Template halaman QR (opsional)

Selain `/qr-code-generator`, QR juga tetap tersedia sebagai halaman CMS bertemplate `qr-generator` untuk instalasi lama: Admin → Halaman → Halaman Baru, pilih template **QR Code Generator**, publikasikan; URL-nya `/p/<slug>.html`. Aset hanya dimuat pada template itu (`QrPage::headHtml()`), dan template memakai `layout_mode = full` sehingga sidebar tidak dirender.

## Widget
Widget disimpan di tabel `widgets` dan dikelompokkan lewat `section_id`. Visibilitas mendukung kombinasi halaman, perangkat (desktop/mobile/tablet), dan peran pengguna. Urutan dan area diatur dari Admin → Tata Letak (drag-and-drop); ada pula endpoint `?ajax=widget.reorder`. Halaman Tata Letak menyertakan pratinjau situs langsung (iframe) dengan pilihan rute — beranda, artikel, halaman, dan arsip — serta tombol lebar perangkat, sehingga penempatan widget langsung terlihat pada tema aktif.

## Aset admin

Dashboard memakai satu set token di `assets/admin.css` (warna, jarak 4–40 px, radius 5 px, tipografi Inter, mode gelap). Komponen baru ada di lapisan v3 pada berkas yang sama; `assets/admin-app.css` hanya menambah gaya per layar.

Editor kaya memakai Tiptap v3 yang di-self-host. Berkas yang disajikan browser adalah hasil bundel, jadi setelah mengubah `resources/editor/editor.js` jalankan:

```sh
npm install      # sekali saja
npm run build    # menulis assets/vendor/tiptap/editor.js
```

`node_modules/` tidak ikut di-commit; hasil bundel (`assets/vendor/tiptap/editor.js`) ikut agar hosting tanpa Node tetap berjalan. Toolbar dirender dari `AdminUi::editorToolbar()` memakai ikon SVG yang sama dengan sisa dashboard.

Tiptap tidak punya node `iframe`, jadi embed video ditangani `assets/admin-embed.js`: setiap `<iframe>` di body ditukar dengan token `[[embed:N]]` selama disunting, lalu dikembalikan utuh (atribut asli dipertahankan) baik saat form dikirim maupun saat autosave berjalan. Tombol **Sisipkan video / embed** di toolbar menerima URL atau potongan `<iframe>` yang ditempel.

## Pengujian

```sh
# Integrasi DB (membuat dan menghapus hanya baris zztest-*)
CMS_CONFIG=config.local.php php tests/run.php

# Smoke test HTTP terhadap server lokal
php -S 127.0.0.1:8090 -t . router.php
php tests/http.php http://127.0.0.1:8090
```

`tests/run.php` menguji utilitas inti, Settings/Cache, CRUD posting/halaman/taksonomi/komentar, widget & reorder, page builder, SEO, Structured Data (25 jenis galeri, node per rute, node manual, escape JSON), tema, hak akses, konfigurasi tools (`ToolConfig`), dan tabel pesanan (`SalesOrder`). `tests/http.php` menguji routing, header keamanan, sitemap/feed, URL Blogger, shim legacy, halaman mandiri (`/cv-maker`, `/qr-code-generator`, `/jual-script-cms`), dan bahwa berkas privat tidak dapat diakses.

Untuk pengujian manual, konfigurasi Tools bisa disetel dari **Admin → Tools & Landing**; nilai uji disimpan di tabel `settings` dan otomatis dibersihkan oleh `tests/run.php`.

## Catatan operasional

- Konten artikel, HTML widget, dan kode tema dirender sebagai HTML atas hak administrator; batasi jumlah admin dan wajibkan HTTPS.
- `config.php`, `config.local.php`, dan `themes/*/functions.php` diblokir dari akses HTTP; berkas di `storage/`, `database/`, `app/`, `tests/`, dan `bin/` juga.
- Jalankan Admin → Backup (atau `App\Services\Backup` via kode) sebelum perubahan besar; backup disimpan di `storage/backups/`.
- Cache ada di `storage/cache/`; bersihkan dengan `php bin/console.php cache:clear`.
