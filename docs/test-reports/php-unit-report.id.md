# Laporan Pengujian Unit PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Tanggal: 2026-08-27
- Eksekusi: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Cakupan: admin/ (panel admin webman) + service/ (layanan utama webman)

## Ringkasan hasil

| Proyek | Kasus uji | Asersi | Hasil |
|------|------|------|------|
| service | 159 | 408 | ✅ Semua lulus (OK) |
| admin | 67 | 180 | ✅ Semua lulus (OK) |

## Catatan lingkungan

- MySQL 127.0.0.1:3306 (root, tanpa kata sandi); database `social` (social_*) dan `open_admin` (erik_*) sudah dibuat dan diisi data (peran super_admin, 39 izin)
- Redis 127.0.0.1:6379 berjalan (penyimpanan captcha `poster:captcha:*`); Elasticsearch tidak dijalankan (health check menurun menjadi unavailable, tidak dianggap gagal)
- service berjalan di 8788, admin di 8791
- Baik service maupun admin tidak memiliki `.env` (repositori telah menghapus env yang tidak sengaja di-commit, commit e5379fc); aplikasi berjalan dengan fallback `getenv('X') ?: nilai default` di `config/*.php`
- **Ekstensi Imagick dimuat tetapi konstanta `RESOURCETYPE_PIXELS` hilang** (build mesin ini hanya memiliki set konstanta RESOURCETYPE_* yang baru); konstruktor ImagickDriver milik poster-php mereferensikan konstanta itu dan langsung crash

## service (159/159 semua hijau)

- Konsisten dengan baseline batch sebelumnya; mencakup: autentikasi/middleware/JWT, pengguna, postingan, komentar, mengikuti, notifikasi, sinkronisasi pencarian, IM, ruangan, panggilan (CallCenter/CallState), suara, relasi model, penanganan aksi (WS)
- M5 menambahkan modul live (LiveCenter: buat/detail/danmaku/tautan mikrofon/tutup), 23 kasus, tanpa regresi

## admin (batch sebelumnya 49/60 → batch ini 67/67 semua hijau)

### Perbaikan: cacat kode nyata (1 tempat)

| Lokasi | Akar masalah | Perbaikan |
|------|------|------|
| `config/poster.php` | `image.driver` default `auto`; DriverFactory memilih ImagickDriver saat ekstensi Imagick terdeteksi, tetapi Imagick mesin ini tidak memiliki konstanta `RESOURCETYPE_PIXELS` → pembuatan captcha/poster langsung 500 (layanan online juga terpengaruh) | Guard konstanta ditambahkan pada deteksi driver: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`; otomatis fallback ke GD saat konstanta tidak ada |

### Perbaikan: asersi usang (diperbarui setelah mencocokkan kode saat ini)

| File pengujian | Kasus | Akar masalah | Koreksi |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 gagal + 1 error) | Mengasersi keberadaan `.env`/`.env.example` dan nilai getenv; tetapi repositori telah menghapus file env dan tidak dapat dibangun ulang | Ditulis ulang sebagai kontrak "berjalan tanpa .env": setiap kunci `getenv()` wajib memiliki fallback `?:`, konfigurasi default menunjuk ke layanan lokal (127.0.0.1:3306/open_admin), tipe konfigurasi penting benar |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser tidak lagi memakai trait Searchable (kini menggunakan `Erikwang2013\Encryptable\Encryptable` untuk enkripsi/dekripsi bidang transparan; `toSearchableArray()` dipertahankan) | Diubah menjadi mengasersi trait Encryptable; asersi toSearchableArray memang sudah lulus, dipertahankan |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` kini memakai format kunci grup global `'@'`; array tingkat atas tidak lagi berisi kelas middleware secara langsung | Asersi diubah untuk memeriksa `$middlewares['@']` berisi Cors dan RateLimit |
| CaptchaTest | semua 7 kasus (semula 6 error + 1 gagal) | Dua kali usang: (a) konstanta Imagick hilang (sudah diperbaiki oleh poster.php); (b) asersi berdasarkan kontrak poster-php lama — `extra.targets` (dengan x/y) berubah menjadi `extra.texts` (hanya text+order), koordinat hanya tersimpan di lapisan penyimpanan; format klik berubah dari `['x'=>, 'y'=>]` menjadi pasangan angka `[x, y]` | Ditulis ulang sesuai kontrak saat ini: struktur/jumlah tingkat kesulitan (2/3/4)/validasi bidang; klik yang benar membaca koordinat dari Redis (`poster:captcha:{key}` → `data.targets`) untuk verifikasi, klik salah gagal, setelah melebihi max_attempts (3) key dikonsumsi/dihapus, keunikan key |

### Pengujian baru (1 file, 12 kasus)

`tests/AdminControllerTest.php` (dengan header hak cipta), mencakup:

- **BaseController::decodeId** (perilaku 404 yang baru saja diperbaiki): putaran encode/decode konsisten; hashid tidak valid melempar `support\exception\NotFoundException` dengan code=404; encodeIds hanya menulis ulang bidang ID
- **RoleController**: update peran super_admin mengembalikan 403 (data DB nyata)
- **PermissionController::buildTree**: pohon izin bersarang (2 tingkat) + semua id node di-hashid-kan
- **ConfigController**: group/key/value kurang → validasi 422; hashid tidak valid → 404
- **ExportController**: ekspor `admin_user` mencantumkan bidang sensitif phone/email/id_card (tabel lain kosong); HTML PDF melakukan escape judul/nilai sel dengan htmlspecialchars (perlindungan XSS) dan menyertakan pernyataan hak cipta

### Catatan yang diketahui

- Request webman yang dikonstruksi dalam pengujian diteruskan sebagai pesan HTTP mentah (buffer) — parameter konstruktor Request workerman adalah buffer; hanya mengirim method/uri tidak dapat mem-parsing body POST; lihat komentar di AdminControllerTest
- Kasus klik benar captcha membaca target tersimpan dari Redis; bila Redis tidak tersedia, kasus tersebut markTestSkipped dan tidak memengaruhi hasil rangkaian

## Belum tercakup / untuk ditambahkan

- Enkripsi/dekripsi Encryptable pada model admin, middleware OperationLog/AdminPermission dan jalur cache RBAC masih kekurangan unit test; disarankan dicakup oleh pengujian API atau batch berikutnya
- Jalur service yang bergantung pada layanan eksternal (ES/gRPC) tetap hanya validasi tingkat unit via stub; tingkat integrasi dicakup oleh pengujian API
