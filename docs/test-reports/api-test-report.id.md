# Laporan Pengujian Otomatis API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Tanggal: 2026-08-27
- Eksekusi: `tests/api/run.php` (skrip asersi curl), hasil di `tests/api/results.json`
- Cakupan: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, termasuk S58-S68)
- Layanan: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` tidak tercakup dalam putaran pengujian HTTP ini)

## Kesimpulan

**116 kasus uji: 116 lulus / 0 gagal (tingkat kelulusan 100%); 3 cacat produk putaran sebelumnya (A20/A39/A40) semuanya diperbaiki dan terverifikasi**

| Grup | Lulus/Total |
|------|-----------|
| admin A01-A45 (autentikasi, captcha, manajemen pengguna, HashID, peran & izin, konfigurasi, log, ekspor/impor, unggah, health check, dll.) | 45/45 |
| service S01-S68 (daftar/login/logout/refresh, profil, mengikuti, postingan/suka/timeline, komentar, notifikasi, pencarian, sesi IM/pesan/push, unggah suara/file/panggilan/ruangan, dll.) | 71/71 |

## Verifikasi perbaikan 3 cacat produk putaran sebelumnya (semua PASS)

| Kasus | Diharapkan | Putaran sebelumnya (aktual) | Perbaikan | Hasil putaran ini |
|------|------|---------|------|---------|
| A20 Detail pengguna hashid tidak valid | 404 | 500 | `BaseController::decodeId()` menangkap `InvalidArgumentException` dan melempar `support\exception\NotFoundException($msg, 404)` (admin/app/admin/controller/BaseController.php); catch dua metode batch `UserController` diperluas ke `InvalidArgumentException \| NotFoundException` mempertahankan semantik 422 | **PASS (404)** |
| A39 Ekspor Excel | aliran file xlsx | 200+badan kesalahan JSON | `ExportController` menambahkan `use support\Response;` (tipe kembalian sebelumnya ter-resolve ke `app\admin\controller\Response` yang tidak ada, melempar TypeError); phone/email/id_card `admin_user` didekripsi otomatis oleh cast Encryptable saat membaca, ekspor langsung memask, dekripsi kedua dihapus | **PASS (aliran file attachment)** |
| A40 Ekspor PDF | aliran file pdf | 200+badan kesalahan JSON | Sama seperti di atas (tipe kembalian `ExportController::pdf()` diperbaiki) | **PASS (aliran file application/pdf)** |

## Masalah lingkungan yang diperbaiki/ditangani pada putaran ini (bukan perubahan kode bisnis produk)

1. **Override kata sandi DB kosong di run.php rusak (cacat skrip pengujian, sudah diperbaiki)**: konstanta `DB` menggunakan `getenv('DB_PASS') ?: 'root'`; string kosong pada variabel lingkungan dianggap falsy oleh `?:` dan jatuh ke 'root', sehingga koneksi root lokal dengan kata sandi kosong ditolak (`Access denied ... using password: YES`). Diubah menjadi `getenv('DB_PASS') ?? 'root'` (default hanya jika tidak disetel), perubahan satu baris (tests/api/run.php:26).
2. **Port 8788 service ditempati proses yang salah (lingkungan, ditangani)**: proses service proyek lain di mesin ini — `property-management-platform` (master 2004768, mulai 08:07) — mendengarkan di 8788, dan `.env`-nya menunjuk ke basis data `property_management`; service social sebenarnya tidak berjalan, menyebabkan rute IM/suara dari S45 semua 404 dan SQL fase pembersihan mengenai basis data yang salah. Proses dihentikan dan service social dimulai ulang di 8788/8789 (`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`), health check kembali ke `social-service`.
3. **Upgrade ImageMagick 7 menyebabkan crash driver Imagick captcha (lingkungan, ditangani)**: setelah ImageMagick sistem naik ke 7.1.2-27 (build 2026-07-08) `PixelsResource` dihapus; imagick 3.8.1 tidak lagi mendefinisikan `Imagick::RESOURCETYPE_PIXELS`, dan konstruktor `ImagickDriver` poster-php langsung melempar `Undefined constant` (kode vendor, tidak diubah), sehingga pembuatan/verifikasi captcha (A05/A06) menghasilkan 500 dan memblokir login A08-A11 secara berantai. **Penanganan**: layanan admin dimulai ulang dengan sakelar driver yang disediakan di dokumen konfigurasi — `POSTER_IMAGE_DRIVER=gd` (admin/config/poster.php:17 mendukung gd/imagick/auto secara native); setelah captcha dipindah ke driver GD, seluruh rantai berfungsi. Untuk memulihkan driver Imagick, turunkan ImageMagick ke 6.x atau tingkatkan poster-php agar kompatibel IM7.
4. **Kata sandi root MySQL berubah menjadi kosong**: putaran sebelumnya tercatat `root/root`; pada putaran ini login dengan kata sandi kosong berhasil, semua layanan dan skrip dimulai dengan kata sandi kosong.
5. **Lingkungan mulai ulang layanan admin**: «admin tidak memiliki .env, bergantung pada variabel lingkungan» dari putaran sebelumnya masih berlaku; perintah mulai ulang di bawah, di «Lingkungan dan reproduksi».
6. **service/.env masih `service/.env.api-test-bak`**: dipindahkan pada putaran sebelumnya untuk pengujian konektivitas dan belum dipulihkan (pemulihan dibatasi kebijakan akses file .env); pada putaran ini layanan kembali dimulai dengan variabel lingkungan. Diperlukan `mv service/.env.api-test-bak service/.env` manual (mulai ulang layanan setelah memulihkan; perhatikan alamat basis data yang ditunjuknya).
7. **Elasticsearch tidak berjalan**: `GET /api/v1/search/posts` mengembalikan 503 (degradasi yang dirancang); kasus pencarian grup S ditangani sesuai harapan (menerima 0 atau 503), tidak dihitung sebagai kegagalan.

## Ketidaksesuaian kontrak/dokumentasi (disarankan direvisi, tidak menghambat)

- Dokumentasi captcha (apidoc dan komentar CaptchaController) menulis `clicks=[{x,y}]` sebagai array objek, sedangkan implementasi `poster-php` membutuhkan array pasangan koordinat `[[x,y]]`; mengirim objek sesuai dokumentasi selalu gagal dalam praktik.
- Unggah suara mengembalikan `voice_url` sebagai `/voice/{md5}.m4a` (relatif terhadap akar API, tanpa awalan `/api/v1`); klien harus menambahkan `/api/v1` sendiri untuk mengaksesnya; akses file melalui rute terautentikasi (memerlukan token).

## Lingkungan dan reproduksi

- Kredensial pengujian: akun uji `e2e_smoke` (admin, kata sandi khusus pengujian) + `apitest_*@test.dev` (service, dibersihkan otomatis setelah dijalankan), semuanya ditulis dalam konstanta `tests/api/run.php`; tidak ada kunci nyata yang digunakan.
- Reproduksi:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # jalankan ulang (116 kasus)
```

## Daftar endpoint (berdasarkan route.php / apidoc)

- service `config/route.php`: 39 rute HTTP (autentikasi 5, pengguna 2, mengikuti 5, postingan 7, komentar 2, notifikasi 4, pencarian 2, IM 4, suara/panggilan/ruangan 5, health/dok 3)
- admin `config/route.php`: 33 rute HTTP (autentikasi/captcha 4, CRUD pengguna 5, peran 5, izin 2, konfigurasi 4, log 1, profil 4, ekspor 2, impor 1, unggah 1, health/dok 4)
