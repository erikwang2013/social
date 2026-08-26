# Penerimaan Garis Dasar Admin (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

Status garis dasar dan titik masuk transformasi untuk open-admin (webman v2 + konsol admin Flutter).

## Versi saat ini dan status runtime

| Item | Nilai |
|---|---|
| Framework | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| Dependensi | `composer install` berhasil, 69 paket |
| .env | **Tidak ada** (repositori tidak memiliki `.env` maupun `.env.example`; buat sendiri secara lokal sesuai MySQL/Redis) |
| Titik masuk migrasi | Tidak ada (`think`/`artisan` tidak tersedia; webman tidak memiliki migrasi bawaan, M0 tidak memiliki tugas migrasi) |
| Pengujian | `vendor/bin/phpunit`: 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky — tidak sepenuhnya hijau** |

## Modul yang diaktifkan (dikonfirmasi di README)

- **Autentikasi JWT**: login/refresh/logout, captcha klik, penguncian akun (5 kegagalan → kunci 15 menit), batas sesi bersamaan (≤3 token per pengguna)
- **RBAC**: pohon peran/izin, otorisasi dengan granularitas method.path
- **Audit operasi**: kueri log + identifikasi 8 sumber platform
- **Manajemen file**: unggah / ekspor Excel / ekspor PDF (termasking)
- **i18n**: peralihan Tionghoa/Inggris (Accept-Language / ?lang=)
- Lainnya: dasbor (cache Redis), konfigurasi sistem, health check/metrics/OpenAPI 3.0, proteksi keamanan 18 lapis

## Rincian kegagalan pengujian (semuanya celah proyek yang sudah ada, bukan dari perubahan ini)

| Grup pengujian | Kegagalan | Alasan |
|---|---|---|
| `EnvConfigTest` (5 item) | 4 failure + 1 error | Pengujian menegaskan `.env`/`.env.example` harus ada dan nilai getenv untuk `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` dll. harus terisi; repositori tidak menyertakan contoh env |
| `CaptchaTest` (4 item) | 3 error + 1 failure (plus 1 risky tanpa asersi) | Captcha klik bergantung pada penyimpanan Redis, tidak tersedia secara lokal |
| `BackendEnhancementTest` (2 item) | 2 failure | Menegaskan sumber data `user` mengandung searchable dan middleware mengandung cors/rate_limit — pergeseran antara konfigurasi dan asersi pengujian |

Langkah lokal untuk kembali hijau: buat `.env` sesuai kunci konfigurasi di `config/` (lengkapi kunci yang diandalkan EnvConfigTest), sediakan MySQL + Redis (untuk CaptchaTest), lalu penanggung jawab memutuskan dua pergeseran konfigurasi di BackendEnhancementTest.

## Status kesiapan gRPC (T3)

- Paket Composer terinstal: `grpc/grpc 1.82.0`, `google/protobuf 5.35` (`--no-plugins` menghindari bug pemuatan ganda plugin security-php)
- Stub PHP dibuat: `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` dll., termasuk tiga set kontrak: infra/user)
- **Ekstensi PHP grpc belum terinstal**: pecl tanpa izin tulis dan sudo butuh kata sandi; `sudo pecl install grpc` diperlukan sebelum menjalankan klien gRPC

## Titik masuk transformasi (delapan item baru dari §3.4 dokumen desain)

1. Workbench moderasi konten: peninjauan berdampingan bilingual untuk postingan/komentar/gambar, template alasan penolakan multibahasa, sanksi pengguna
2. Antrean pemrosesan laporan
3. Meja permintaan GDPR (tiket ekspor/hapus)
4. Integrasi dasbor data dengan bee_tsdb
5. Manajemen entri i18n (CRUD bersama untuk empat klien)
6. Manajemen pustaka hadiah (SKU, harga, efek, nama multibahasa)
7. Konfigurasi provider live (strategi routing, urutan peralihan)
8. Peninjauan permohonan penarikan dana

**Titik integrasi gRPC**: stub kontrak sisi admin berada di `admin/generated/` (memakai ulang `Social/Admin/V1` untuk probe + pesan bisnis mendatang); panggilan ke service melalui `Social\User\V1\UserServiceClient` dan ke infrastructure melalui `Social\Infra\V1\InfraServiceClient`; rantai probe dengan service/infrastructure dijelaskan di `service/README.grpcs.md` dan probe integrasi T10.
