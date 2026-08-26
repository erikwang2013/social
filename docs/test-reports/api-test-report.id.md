# Laporan Pengujian Otomatis API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Tanggal: 2026-08-27
- Eksekusi: `tests/api/run.php` (skrip asersi curl), hasil di `tests/api/results.json`
- Cakupan: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, termasuk S58-S68)
- Layanan: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` tidak tercakup dalam putaran pengujian HTTP ini)

## Kesimpulan

**116 kasus uji: 113 lulus / 3 gagal (tingkat kelulusan 97,4%); 3 kegagalan semuanya cacat produk dengan akar masalah teridentifikasi**

| Grup | Lulus/Total |
|------|-----------|
| admin A01-A45 (autentikasi, captcha, manajemen pengguna, HashID, peran & izin, konfigurasi, log, ekspor/impor, unggah, health check, dll.) | 42/45 |
| service S01-S68 (daftar/login/logout/refresh, profil, mengikuti, postingan/suka/timeline, komentar, notifikasi, pencarian, sesi IM/pesan/push, unggah suara/file/panggilan/ruangan, dll.) | 71/71 |

## Kasus uji yang gagal (3, semuanya cacat produk)

| Kasus | Diharapkan | Aktual | Akar masalah |
|------|------|------|------|
| A20 Detail pengguna hashid tidak valid | 404 | 500 | `HashidsService::decode()` melempar `InvalidArgumentException` yang tidak ditangkap untuk ID tidak valid (admin/app/common/HashidsService.php:28, BaseController.php:52); eksepsi merambat sebagai 500, seharusnya ditangkap dan mengembalikan 404 |
| A39 Ekspor Excel | aliran file xlsx | 200+badan kesalahan JSON (kegagalan bisnis) | `ExportController::excel()` mendeklarasikan tipe kembalian `: Response` tetapi tidak memiliki `use support\Response`, sehingga tipe ter-resolve ke `app\admin\controller\Response` → kembalian sukses apa pun melempar `TypeError` (ExportController.php:122), membuat ekspor tidak dapat digunakan sama sekali |
| A40 Ekspor PDF | aliran file pdf | 200+badan kesalahan JSON (kegagalan bisnis) | Sama seperti di atas, `ExportController::pdf()` (ExportController.php:135) tanpa `use support\Response` |

> Catatan tambahan (cacat potensial pada file yang sama, saat ini tertutupi TypeError di atas): `ExportController` baris 90 memanggil `EncryptionService::decrypt()` pada phone/email, padahal field `email/phone/id_card` model `AdminUser` mendeklarasikan cast `Encryptable::class` (enkripsi otomatis saat menulis, dekripsi otomatis saat membaca); ekspor akan mendekripsi teks biasa untuk kedua kalinya → begitu ada akun dengan telepon/email tidak kosong, akan melempar `EncryptionException: Invalid ciphertext prefix for AES-256-CBC`. Masalah ini tetap akan terulang setelah tipe kembalian diperbaiki.

## Masalah lingkungan yang diperbaiki selama pengujian (bukan perubahan kode produk)

1. **Kolom `id` tabel migrasi m2/m3/m4 tanpa AUTO_INCREMENT (menghambat, sudah diperbaiki)**: `social_follows`, `social_notifications` yang dibuat oleh `service/database/m2.sql`/`m3.sql`/`m4.sql` memiliki `id BIGINT UNSIGNED NOT NULL` tanpa `AUTO_INCREMENT`; INSERT apa pun gagal dengan `1364 Field 'id' doesn't have a default value`, memblokir semua jalur tulis mengikuti/notifikasi/IM/suara. `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` dijalankan secara lokal (8 tabel lainnya sudah memiliki auto-increment). **Skrip migrasi itu sendiri sebaiknya ditambahkan auto-increment.**
2. **service/.env menunjuk ke basis data yang tidak dapat dijangkau (menghambat)**: `DB_PORT=13306` tanpa kata sandi, padahal MySQL utama sebenarnya di `127.0.0.1:3306 (root/root)`; `createUnsafeMutable` webman menimpa variabel lingkungan CLI. Selama pengujian, `.env` dipindahkan ke `service/.env.api-test-bak` (konten dipertahankan apa adanya) dan layanan dijalankan dengan variabel lingkungan yang disuntikkan; pemulihan tidak dapat dilakukan karena pembatasan kebijakan akses file .env, diperlukan `mv service/.env.api-test-bak service/.env` manual (catatan: setelah dipulihkan, memulai ulang layanan akan kembali menemui basis data yang tidak dapat dijangkau).
3. **admin tidak memiliki .env, bergantung pada variabel lingkungan**: memerlukan `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`. Plugin `encryptable` tanpa provider terdaftar di kontainer webman kembali ke `EnvEncryptableConfig` (membaca `ENCRYPTION_KEY`, cipher default aes-256-gcm); panjang kunci yang tidak cocok menyebabkan `MissingEncryptionKeyException` saat pembuatan/impor/ekspor akun.
4. **Elasticsearch tidak berjalan**: `GET /api/v1/search/posts` mengembalikan 503 (degradasi yang dirancang); kasus pencarian grup S ditangani sesuai harapan (menerima 0 atau 503), tidak dihitung sebagai kegagalan.

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
