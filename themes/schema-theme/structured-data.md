# Schema Theme — panduan Structured Data

Tema ini memancarkan **satu dokumen JSON-LD** (`@context` + `@graph`) di dalam
`<head>` setiap halaman. Isinya dibangun oleh `App\Services\StructuredData`
dari data yang benar-benar ada di halaman: judul, kanonik, breadcrumb, isi
artikel, daftar post, profil penulis, dan setelan tema. Tidak ada node yang
dikarang: bila field wajib suatu jenis belum tersedia, node itu tidak
dipancarkan.

Format yang dipakai konsisten dengan rekomendasi Google:

```php
JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
| JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
```

## 1. Pemetaan 25 jenis galeri Google

Prioritas menunjukkan cara pengisian:

| Prioritas | Arti |
| --- | --- |
| 1 | Selalu dipancarkan otomatis di semua halaman |
| 2 | Otomatis per jenis halaman (artikel, profil, katalog) |
| 3 | Otomatis bila datanya ada (gambar, video, rating) |
| 4 | Mengikuti setelan tema (organisasi, film, paywall) |
| 5 | Node manual lewat `schema_site` (semua halaman) |
| 6 | Node manual lewat `schema_pages` (path tertentu) |
| 7 | Properti tempelan pada node lain (bukan node mandiri) |

| # | Jenis (Google Search Gallery) | Tipe schema.org | Sumber data | Prioritas |
| --- | --- | --- | --- | --- |
| 1 | Article | `BlogPosting` (bisa `NewsArticle`, `Article`, `TechArticle`, `Report`) | Halaman post: judul, deskripsi, gambar, tanggal, penulis, kategori | 2 |
| 2 | Breadcrumb | `BreadcrumbList` | Breadcrumb halaman (post, kategori, tag, penulis, arsip) | 2 |
| 3 | Carousel | `ItemList` | Daftar post pada home/kategori/tag/search/arsip (minimal 2 item) | 2 |
| 4 | Course list | `ItemList` | `schema_pages` → `{"gallery":"Course list"}` | 6 |
| 5 | Dataset | `Dataset` | `schema_site` / `schema_pages` | 5–6 |
| 6 | Discussion Forum | `DiscussionForumPosting` | `schema_pages` | 6 |
| 7 | Education Q&A | `Quiz` | `schema_pages` | 6 |
| 8 | Employer Aggregate Rating | `AggregateRating` | Setelan `org_rating_value` + `org_rating_count` pada Organization | 4 |
| 9 | Event | `Event` | `schema_pages` | 6 |
| 10 | Image Metadata | `ImageObject` | Gambar utama artikel + setelan `image_license` | 3 |
| 11 | Job Posting | `JobPosting` | `schema_pages` | 6 |
| 12 | Local Business | `LocalBusiness` | Setelan `org_type`, alamat, geo, jam buka, rentang harga | 4 |
| 13 | Math Solver | `MathSolver` | `schema_pages` | 6 |
| 14 | Movie | `Movie` | Post pada kategori film (`movie_categories`); katalog film menjadi `ItemList` berisi `Movie` | 4 |
| 15 | Organization | `Organization` | Nama situs, logo, deskripsi, `sameAs` (`social_links`), kontak | 1 |
| 16 | Product | `Product` | `schema_pages` (atau `schema_site`) | 6 |
| 17 | Profile Page | `ProfilePage` + `Person` | Halaman penulis: nama, URL, bio, avatar | 2 |
| 18 | Q&A Page | `QAPage` | `schema_pages` | 6 |
| 19 | Recipe | `Recipe` | `schema_pages` dengan `path` seperti `/resep/*` | 6 |
| 20 | Review Snippet | `Review` | `schema_pages` | 6 |
| 21 | Software App | `SoftwareApplication` | `schema_pages` | 6 |
| 22 | Speakable | `SpeakableSpecification` | Properti `speakable` pada `WebPage` artikel | 7 |
| 23 | Subscription | `WebPageElement` | Paywall: `isAccessibleForFree` + `hasPart` pada artikel/WebPage | 4 |
| 24 | Vacation Rental | `VacationRental` | `schema_pages` | 6 |
| 25 | Video | `VideoObject` | Embed YouTube/Vimeo di isi artikel (maksimal 3) | 3 |

## 2. Node otomatis per jenis halaman

| Halaman | Node yang dipancarkan |
| --- | --- |
| Semua halaman | `Organization` (+ `LocalBusiness`), `WebSite` + `SearchAction` |
| Post | `BlogPosting`, `WebPage`, `ImageObject`, `VideoObject`, `Movie` (bila kategori film), `BreadcrumbList`, `WebPageElement` (bila paywall) |
| Kategori / tag / arsip / search | `ItemList` (carousel `Article`, atau `Movie` untuk kategori film) + `BreadcrumbList` |
| Profil penulis | `ProfilePage` + `Person` + `BreadcrumbList` |
| Halaman statis | `BreadcrumbList` (+ node manual yang cocok) |

Contoh dokumen untuk satu post biasa:

```json
{
  "@context": "https://schema.org",
  "@graph": [
    { "@type": "Organization", "@id": "https://contoh.id/#organization", "name": "Studio Nusantara", "url": "https://contoh.id/" },
    { "@type": "WebSite", "@id": "https://contoh.id/#website", "url": "https://contoh.id/", "name": "Studio Nusantara", "publisher": { "@id": "https://contoh.id/#organization" } },
    { "@type": "BlogPosting", "@id": "https://contoh.id/post/hello#article", "headline": "Hello", "datePublished": "2026-01-02T09:00:00+07:00", "author": { "@type": "Person", "name": "Robby" }, "publisher": { "@id": "https://contoh.id/#organization" } },
    { "@type": "WebPage", "@id": "https://contoh.id/post/hello#webpage", "url": "https://contoh.id/post/hello", "speakable": { "@type": "SpeakableSpecification", "cssSelector": ["h1", ".article-body p:first-of-type"] } },
    { "@type": "ImageObject", "@id": "https://contoh.id/post/hello#primaryimage", "url": "https://contoh.id/media/hello.jpg", "representativeOfPage": true },
    { "@type": "BreadcrumbList", "itemListElement": [{ "@type": "ListItem", "position": 1, "name": "Beranda", "item": "https://contoh.id/" }] }
  ]
}
```

## 3. Setelan organisasi dan bisnis

Buka **Admin → Kustomisasi → Schema Theme**, grup
**Structured Data — Organisasi**:

- `org_type` = `LocalBusiness` untuk memasarkan bisnis lokal; isi alamat,
  latitude/longitude, `org_price_range`, dan `org_opening_hours` (satu aturan
  per baris, mis. `Mo-Fr 09:00-17:00`).
- `social_links` diisi satu URL per baris; semua URL valid menjadi `sameAs`.
- `org_rating_value` + `org_rating_count` mengisi `aggregateRating` pada
  Organization (Employer Aggregate Rating).

## 4. Artikel, gambar, video, dan paywall

- `article_type` mengganti tipe node artikel (`BlogPosting`, `NewsArticle`,
  `Article`, `TechArticle`, `Report`).
- `image_license` (URL) menambahkan `license` + `acquireLicensePage` pada
  `ImageObject`.
- `speakable_selectors` dipisah koma; kosongkan untuk memakai `h1` dan
  paragraf pertama.
- `VideoObject` dibuat dari setiap `<iframe>` YouTube/Vimeo di isi artikel.
  Thumbnail YouTube diturunkan dari ID video; Vimeo tidak punya thumbnail
  publik sehingga properti itu dilewati.
- Aktifkan `paywall` lalu isi `paywall_selector` (default `.article-body`)
  untuk memancarkan `isAccessibleForFree: false` + `hasPart`
  (`WebPageElement`), termasuk otomatis untuk post ber-visibility password.

## 5. Node manual (`schema_site` dan `schema_pages`)

Keduanya menerima JSON. `schema_site` berlaku di semua halaman:

```json
{
  "@type": "Dataset",
  "name": "Data Harga Pasar 2026",
  "description": "Berkas CSV harga komoditas harian.",
  "license": "https://creativecommons.org/licenses/by/4.0/",
  "creator": { "@type": "Organization", "name": "Studio Nusantara" }
}
```

`schema_pages` menerima array; setiap entri punya `path` (mendukung `*`),
`gallery` (nama pada tabel di atas), dan `data`:

```json
[
  { "path": "/resep/*", "gallery": "Recipe", "data": { "recipeCategory": "Dessert", "recipeYield": "4 porsi", "totalTime": "PT45M" } },
  { "path": "/produk/*", "gallery": "Product", "data": { "sku": "SKU-1", "brand": { "@type": "Brand", "name": "Nusantara" } } },
  { "path": "/acara/*", "gallery": "Event", "data": { "startDate": "2026-12-01T19:00:00+07:00", "location": { "@type": "Place", "name": "Balai Kota" } } },
  { "path": "/lowongan/*", "gallery": "Job Posting", "data": { "title": "Editor", "datePosted": "2026-11-01", "hiringOrganization": { "@type": "Organization", "name": "Studio Nusantara" } } }
]
```

Aturan pengisian:

1. `@type` ditentukan otomatis dari `gallery`; jangan menulisnya sendiri.
2. Properti yang sudah diisi `data` tidak ditimpa default halaman.
3. Untuk tipe `CreativeWork` (Article, Recipe, Movie, Review, VideoObject, …)
   engine menambahkan `url`, `datePublished`, `dateModified`, `author`,
   `publisher`, `inLanguage`, dan `image` bila kosong. Untuk tipe non-CreativeWork
   (Event, Product, JobPosting, Organization, ItemList) hanya `name`, `url`,
   dan `description` yang ditambahkan.
4. JSON tidak valid diabaikan tanpa mematikan halaman.

## 6. Kategori film

`movie_categories` berisi nama kategori dipisah koma (default
`film, movie, sinema`). Post pada kategori tersebut mendapat node `Movie`
dengan `director` (dari `movie_directors`), `aggregateRating` (dari
`movie_rating_value`/`movie_rating_count`), dan katalog kategorinya berubah
menjadi `ItemList` berisi `Movie` (bukan `Article`).

## 7. Memeriksa hasil

1. Buka halaman di server lokal, salin blok `<script type="application/ld+json">`.
2. Tempel ke [Rich Results Test](https://search.google.com/test/rich-results)
   atau [Schema Markup Validator](https://validator.schema.org/).
3. Dari CLI, seluruh graph bisa di-`json_decode` untuk memastikan tidak ada
   karakter ilegal:

```bash
CMS_CONFIG=config.local.php /opt/lampp/bin/php -r '
require "app/bootstrap.php";
$page = ["route" => "home", "meta" => ["title" => "Uji", "canonical" => "https://contoh.id/"]];
$json = App\Services\StructuredData::json($page);
var_dump(json_decode($json, true) !== null);
'
```

Tema lain tidak terpengaruh: grafik lanjutan hanya aktif bila `theme.json`
mendeklarasikan blok `structured_data` seperti pada tema ini.
