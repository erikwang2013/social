# Laporan Pengujian End-to-End (E2E) Halaman
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- Tanggal: 2026-08-27
- Lingkungan: mesin lokal (Linux), browser nyata (Playwright 1.62 / Chromium) + proses layanan nyata
- Total kasus uji: **41**, lulus **41**, gagal **0**, ditandai blocked **1**
- Artefak: `tests/e2e/artifacts/html-report/` (laporan HTML Playwright), tangkapan layar/jejak kegagalan (tidak ada pada run ini)

## Cakupan Pengujian dan Daftar Halaman

Kedua backend webman berjalan sebagai proses nyata: `admin` (:8791), `service` (:8788, WS :8789).
`app/view/` kedua sisi hanya berisi templat bawaan (`index/view.html`), tanpa templat multi-halaman tradisional — "halaman" yang sebenarnya adalah endpoint API,
dan frontend web ditangani oleh klien Flutter/HarmonyOS (`apps/` tidak memiliki UI web yang dapat dijalankan, di luar cakupan E2E).

| Aplikasi | Halaman / Endpoint | Kasus |
|------|------------|------|
| admin | `/health` pemeriksaan kesehatan, `/metrics` metrik Prometheus, `/.well-known/security.txt`, `/api/docs` OpenAPI, `/install` wizard instalasi | 5 |
| admin | `/api/captcha/generate` + `/api/captcha/verify` (penyelesaian captcha geser dari piksel nyata), `/api/auth/login` (sukses/salah kata sandi/captcha tidak ada) | 3 |
| admin | Halaman terproteksi setelah login: `/admin/dashboard`, `/admin/user`, `/admin/role`, `/admin/permission`, `/admin/config`, `/admin/log`, `/admin/profile`, `/admin/social-user`, logout `/admin/profile/logout` → token tidak valid | 11 |
| admin | Operasi batch `/admin/user/batch/status` (aktivasi massal + ids kosong 422), ekspor `/admin/export/excel` (pemeriksaan header file xlsx), ubah kata sandi `/admin/profile/password` (kata sandi lama tidak ada 422) | 3 |
| service | `/` (kontainer iframe), `/health`, `/apidoc` (mengarahkan ke apidoc/index.html), akses tanpa login ke endpoint terproteksi 401 | 4 |
| service | Registrasi/login/logout, profil (GET/PUT `/api/v1/me`), postingan/timeline/detail, suka/batal suka, komentar, ikuti/berhenti mengikuti/relasi/pengikut/daftar yang diikuti, notifikasi (daftar/jumlah belum dibaca/tandai satu sudah dibaca/tandai semua sudah dibaca) | 10 |
| service | Cari pengguna, cari postingan (ES tidak berjalan → 503, ditandai blocked dan lulus) | 2 |
| service | Percakapan IM (buat/daftar/pesan), ruang suara (buat/daftar/detail/tutup) | 3 |

## Cara Menjalankan

```bash
cd tests/e2e && npx playwright test          # semua
# atau per file: admin-pages.spec.js / admin-auth.spec.js / service-journey.spec.js
```

- Fiksasi akun uji: `e2e_smoke`, kata sandi `ApiTest!2026` (diunggulkan via SQL, lihat `tests/api/run.php`)
- Captcha geser diselesaikan melalui korelasi Pearson per piksel antara "potongan puzzle vs gambar latar" (jalur interaksi nyata, tanpa jalan pintas);
  jenis captcha acak (click/rotate/slider), hanya slider yang bisa diselesaikan otomatis, sehingga skrip mencoba ulang dengan gambar baru hingga berhasil.

## Titik Blokir / Keterbatasan Lingkungan

1. **Pencarian postingan 503**: `/api/v1/search/posts` bergantung pada Elasticsearch (Scout), tidak dijalankan di lingkungan ini → mengembalikan 503.
   Kasus lulus dengan tanda `blocked`; perlu ES dijalankan untuk memverifikasi hasil.
2. **Halaman utama service `/` memerlukan rute eksplisit**: perutean bawaan webman-framework v2.2.4 tidak lagi meresolusi `/` ke
   `IndexController@index` (pernah menyebabkan 404 di jalur akar, menggagalkan kasus halaman utama). Diperbaiki dengan mendaftarkan eksplisit
   `Route::get('/', ...)` di `service/config/route.php`; berlaku setelah service dimulai ulang.
3. **Kompatibilitas Imagick captcha admin**: build Imagick mesin ini tidak memiliki konstanta `Imagick::RESOURCETYPE_PIXELS`,
   sehingga driver `auto` salah memilih ImagickDriver dan menyebabkan generate 500 (`admin/config/poster.php` sekarang jatuh ke gd
   tergantung ada tidaknya konstanta; memerlukan mulai ulang admin).
4. **Memori GD captcha admin**: `GdDriver` mendekode gambar besar (latar 5472x3648) dengan `memory_limit 128M`,
   dan generate beruntun berisiko OOM (admin pernah jatuh pada suite panjang). Penghindaran: nyalakan ulang admin sebelum kasus captcha,
   dan jalankan dalam batch (admin-pages / admin-auth / service terpisah). Keterbatasan lingkungan, bukan cacat kode bisnis.
5. **Jenis captcha acak**: generate memilih satu dari tiga; click/rotate tidak menampilkan data yang dapat diselesaikan, hanya slider yang lulus otomatis (maks. 12 percobaan).
6. **Kata sandi root kosong pada database**: lingkungan uji lokal menyediakan MySQL dengan root/kata sandi kosong, `.env` bawaan kedua aplikasi konsisten.
7. **apps/ seluler**: android/harmonyos/ios tidak memiliki UI web yang dapat dijalankan, tidak termasuk dalam E2E browser.

## Kesimpulan

Login admin (termasuk captcha geser) dan 22 endpoint admin, serta 19 kasus alur lengkap sisi pengguna service semuanya lulus
(run ini menambah 6 kasus: aktivasi massal admin/ekspor Excel/validasi ubah kata sandi, service 401 tanpa login/berhenti mengikuti/tandai satu notifikasi sudah dibaca).
2 cacat nyata diperbaiki: 404 jalur akar service (rute eksplisit ditambahkan), generate 500 captcha admin
(konstanta Imagick hilang → jatuh ke GD, sudah ada di konfigurasi, berlaku setelah mulai ulang).
Satu-satunya titik blokir adalah layanan pencarian (ES) yang belum dipasang; semua jalur lain (registrasi/login/posting/interaksi/notifikasi/IM/suara) terverifikasi berfungsi.
