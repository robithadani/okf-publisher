# OKF Publisher

Plugin WordPress yang mengekspor konten situs menjadi bundle [Open Knowledge Format v0.1](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) — direktori markdown + YAML frontmatter yang bisa dibaca aplikasi/agent AI mana pun.

## Instalasi

1. Salin folder `okf-publisher/` ke `wp-content/plugins/`, lalu aktifkan.
2. Buka **Settings → OKF**: pilih post type & taksonomi, isi deskripsi situs, simpan.
3. Klik **Rebuild Sekarang** (atau `wp okf rebuild` untuk situs besar).
4. Bundle tersaji di `https://situs.com/okf/index.md`.

Setelah build awal, bundle ter-update otomatis setiap kali konten disimpan, di-unpublish, atau dihapus.

## Halaman Settings → OKF

Halaman pengaturan dirancang agar konsep OKF mudah dipahami:

- **Cara kerja**: alur Konten WordPress → Bundle OKF → Aplikasi AI.
- **Kartu status**: jumlah dokumen per bagian, waktu perubahan terakhir, dan mode akses, dihitung dari file bundle sungguhan.
- **Penjelajah bundle**: telusuri `index.md` dan folder, lalu lihat isi dokumen persis seperti yang dibaca AI (frontmatter dan isi diberi warna berbeda). Hanya baca, khusus admin.
- **Hubungkan ke aplikasi AI**: URL bundle, perintah `curl`, dan perintah MCP yang sudah terisi alamat situs, masing-masing dengan tombol Salin.

## Di layar WordPress biasa

- **Kolom OKF** di daftar post/halaman: Di bundle, Dikecualikan, Belum terbit, atau Belum masuk, plus link ke versi OKF-nya.
- **Panel OKF di editor**: status, path, link, frontmatter persis seperti yang dibaca AI, dan kotak **Kecualikan dari bundle OKF**. Post yang dikecualikan dihapus dari bundle dan index saat disimpan.

## Konsumsi oleh aplikasi AI

Beri aplikasi AI klien satu URL: `https://situs.com/okf/index.md`. Agent membaca index, lalu menelusuri link markdown antar-dokumen. Bila API key diisi di setting, konsumen wajib mengirim header `X-OKF-Key: <key>`.

## Keamanan

- **API key** (opsional): bila diisi (minimal 16 karakter; tombol "Buat key acak" tersedia), konsumen wajib mengirim header `X-OKF-Key`. Parameter `?key=` sengaja tidak didukung karena tercatat di access log.
- **Folder bundle ber-token acak** (`wp-content/uploads/okf-<token>/`) dengan `.htaccess` penolak akses langsung, sehingga file tidak bisa dibuka lewat URL `uploads/` dan melewati pengecekan key. Di nginx, `.htaccess` diabaikan; nama folder acak menjadi perlindungan utamanya. Pembaruan dari v0.1/0.2 memindahkan folder lama otomatis.
- Respons bundle membawa `X-Robots-Tag: noindex, nofollow` agar tidak terindeks sebagai duplikat konten. Ubah lewat filter `okf_robots_header` (kembalikan string kosong untuk mematikan).
- Header `Update URI` menunjuk ke repo ini, sehingga WordPress tidak menawarkan update dari plugin lain di wordpress.org yang kebetulan bernama sama.
- Belum ditangani: post berpassword dan konten khusus member ikut terekspor sebagai konten publik.

## Discoverability (opsional)

Centang **Tayangkan /llms.txt** di setting untuk menyajikan `https://situs.com/llms.txt` berisi penunjuk ke bundle OKF, agar agent pihak ketiga menemukannya. Hanya berlaku bila bundle publik (API key kosong); jangan aktifkan bila plugin SEO lain sudah menyajikan llms.txt.

## Perintah WP-CLI

```
wp okf rebuild    # bangun ulang seluruh bundle
wp okf validate   # cek setiap file punya frontmatter + field `type` (jalankan setelah rebuild sebagai smoke test)
```

## Riwayat perubahan (`log.md`)

Bila dinyalakan, `log.md` mencatat satu baris setiap dokumen ditulis, dipindah, atau dihapus saat post disimpan, dan **satu baris ringkasan** per rebuild penuh (bukan satu baris per dokumen). Ukurannya dibatasi 256 KB, dan log dari versi lama otomatis dimigrasi dan dipangkas.

## Hook untuk developer

```php
// Ubah/tambah field frontmatter (mis. dari ACF)
add_filter( 'okf_frontmatter', fn( array $fm, WP_Post $post ) => $fm, 10, 2 );

// Kecualikan post tertentu dari bundle
add_filter( 'okf_exclude_post', fn( bool $exclude, WP_Post $post ) => $exclude, 10, 2 );
```

## Catatan teknis

- File fisik ditulis ke `wp-content/uploads/okf/`; URL `/okf/*` disajikan lewat rewrite rule + loader tipis (portabel untuk Apache & nginx, sekaligus menangani API key).
- Konversi HTML → Markdown memakai `DOMDocument` bawaan PHP — tanpa Composer. Bila butuh fidelitas lebih, ganti `OKF_Markdown` dengan `league/html-to-markdown`.
- Hanya konten berstatus `publish` yang pernah masuk bundle.
