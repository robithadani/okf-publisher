# OKF Publisher

Plugin WordPress yang mengekspor konten situs menjadi bundle [Open Knowledge Format v0.1](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) — direktori markdown + YAML frontmatter yang bisa dibaca aplikasi/agent AI mana pun.

## Instalasi

1. Salin folder `okf-publisher/` ke `wp-content/plugins/`, lalu aktifkan.
2. Buka **Settings → OKF**: pilih post type & taksonomi, isi deskripsi situs, simpan.
3. Klik **Rebuild Sekarang** (atau `wp okf rebuild` untuk situs besar).
4. Bundle tersaji di `https://situs.com/okf/index.md`.

Setelah build awal, bundle ter-update otomatis setiap kali konten disimpan, di-unpublish, atau dihapus.

## Konsumsi oleh aplikasi AI

Beri aplikasi AI klien satu URL: `https://situs.com/okf/index.md`. Agent membaca index, lalu menelusuri link markdown antar-dokumen. Bila API key diisi di setting, konsumen wajib mengirim header `X-OKF-Key: <key>`.

## Perintah WP-CLI

```
wp okf rebuild    # bangun ulang seluruh bundle
wp okf validate   # cek setiap file punya frontmatter + field `type` (jalankan setelah rebuild sebagai smoke test)
```

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
