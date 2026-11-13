# CMS PHP berdasarkan template-Dipake.xml

Proyek ini dibuat terpisah di folder baru dan mempertahankan `assets/template-source.xml` sebagai sumber Blogger yang tidak diubah. CSS template diekstrak ke `assets/template.css`; `assets/cms-adapter.css` hanya menambahkan adapter kecil untuk elemen AMP yang diganti dengan HTML standar.

## Pemetaan template

| Komponen Blogger | Implementasi CMS |
|---|---|
| `b:section` / `b:widget` | Baris widget di tabel `widgets`, dikelompokkan dengan `section_id`; tata letak dapat diubah dari Admin → Tata Letak |
| `b:include` | Komponen PHP bersama seperti `header.php`, `footer.php`, `sidebar.php`, dan `functions.php` |
| `b:loop` | Loop PHP atas hasil query PDO |
| `data:post` / `data:blog` | Kolom posting/halaman dan pengaturan website dari MySQL |
| Header1, PageList1, LinkList3 | Identitas website/logo dan navigasi ke beranda/halaman serta tautan kustom |
| BlogSearch1 | Form pencarian MySQL |
| HTML2 / HTML1 | Konten widget HTML sumber template, termasuk marquee, disimpan pada database |
| FeaturedPost1 / Blog1 | Postingan unggulan bertanda dan daftar posting terbaru dengan pagination |
| Label1 / PopularPosts1 | Kategori dan posting populer berdasarkan jumlah kunjungan |
| Text1, Text2, LinkList1, LinkList2 | Widget footer dengan konten dari tabel `widgets` |
| Disqus AMP comments | Komentar MySQL dengan antrean moderasi, persetujuan, spam, dan penghapusan |
| AMP carousel / gambar / form | HTML responsif biasa; carousel memakai scroll horizontal dan form komentar memakai PHP |
| Permalink Blogger | URL artikel `/YYYY/MM/slug.html`; halaman `/p/slug.html`; kategori `/search/label/slug` |

Template sumber juga memuat metadata SEO, logo SVG, warna dan aturan responsive CSS, marquee animasi, menu, pencarian, serta markup postingan. Nilai CSS asal dipertahankan. Runtime AMP tidak dipakai pada output PHP karena halaman memakai form server-side dan aset stylesheet lokal; elemen gambar/form diubah ke HTML standar dan aturan tampilannya dipertahankan.

## Persyaratan

- PHP 8.1 atau lebih baru dengan ekstensi `PDO`, `pdo_mysql`, dan `fileinfo`.
- MySQL 5.7+ atau MariaDB yang mendukung tipe JSON dan indeks FULLTEXT InnoDB.
- Apache dengan `mod_rewrite`/`.htaccess` aktif (umum pada shared hosting/cPanel).
- Folder proyek dapat ditulis sementara saat instalasi; `uploads/` harus dapat ditulis oleh PHP.

## Instalasi

1. Unggah folder `cms-self-hosted` ke hosting.
2. Buat database MySQL/MariaDB dan pengguna database dari cPanel, lalu berikan hak akses penuh ke database tersebut.
3. Buka `https://domain-anda/cms-self-hosted/install.php`.
4. Isi koneksi database, URL website lengkap (termasuk subfolder jika ada), nama website, dan akun administrator. Password admin minimal 12 karakter.
5. Setelah diarahkan ke Admin, masuk melalui `/admin/`. Atur deskripsi, logo/favicon, homepage, jumlah posting per halaman, dan password melalui Pengaturan.

Installer membuat `config.php`, tabel database, akun admin pertama, serta mengimpor konfigurasi 14 widget dari XML. `config.php` diblokir dari akses HTTP oleh `.htaccess`. Jika installer tidak dapat menulis file konfigurasi pada hosting, atur izin folder aplikasi sementara sesuai panduan hosting, jalankan installer, lalu kembalikan izin folder ke nilai yang aman.

## Fitur Admin

- Ringkasan jumlah posting, halaman, komentar tertunda, posting terbaru, dan aktivitas.
- CRUD posting/halaman, draft/publikasi/jadwal, slug, kategori, tag, thumbnail, SEO, preview aman untuk admin, dan editor visual/HTML.
- Tata letak widget berbasis database: tambah, edit, aktif/nonaktif, urutkan, pindahkan area, dan hapus. Widget Blog inti tetap terkunci.
- Tema: CSS override, layout HTML frontend dengan token komponen, kode tambahan di `<head>`/footer, preview, snapshot, dan pemulihan.
- Moderasi komentar, pencarian/filter status, dan penghapusan.
- Media library gambar (JPG/PNG/GIF/WebP maksimal 5 MB).
- Pengaturan website/homepage, pagination, permalink Blogger, favicon/logo, komentar, dan kata sandi admin.
- Sitemap XML, robots.txt, RSS, canonical, Open Graph/Twitter, Schema.org, breadcrumb, dan pencarian.

Konten artikel/halaman tidak dimasukkan ke file PHP. HTML widget, konten artikel, dan kode tema memang dirender sebagai HTML sesuai hak administrator; batasi akun admin dan gunakan HTTPS. Perubahan template/CSS tambahan dicadangkan sebelum penyimpanan. Layout HTML tema opsional; jika digunakan, tata susunan komponen menggunakan token `{{header}}`, `{{search}}`, `{{top_banner}}`, `{{featured}}`, `{{main_content}}` (wajib), `{{middle_content}}`, `{{sidebar}}`, dan `{{footer}}`.

## Pemeriksaan lokal

Jalankan lint PHP dengan:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Server pengembangan juga harus memiliki ekstensi `pdo_mysql`; uji CRUD dan tampilan setelah database dibuat melalui installer.
