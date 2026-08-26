# Laporan Pengujian Unit PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Tanggal: 2026-08-27
- Eksekusi: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Cakupan: admin/ (panel admin webman) + service/ (layanan utama webman)

## Ringkasan hasil

| Proyek | Kasus uji | Asersi | Hasil |
|------|------|------|------|
| service | 136 | 348 | ✅ Semua lulus (OK) |
| admin | 60 | 136 | ⚠️ 49 lulus / 4 error / 7 gagal |

## service (semua hijau)

- File pengujian baru (batch ini): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest, dll.; setelah digabung dengan 24 file pengujian yang ada, total 136 kasus, semuanya lulus
- Modul yang dicakup: autentikasi/middleware/JWT, pengguna, postingan, komentar, mengikuti, notifikasi, sinkronisasi pencarian, IM, ruangan, panggilan (CallCenter/CallState), suara, relasi model, penanganan aksi (WS)

### Perbaikan: hang acak pada rangkaian pengujian (penting)

- Gejala: pada proses penuh, proses tiba-tiba membeku secara acak; menjalankan satu file/subset saja lulus
- Akar masalah: `new Worker()` di `ActionHandlerTest::setUp` mendaftarkan instance ke **registri statis** `Worker::$workers`; setelah itu, `CallCenter::start` mana pun melihat "ada Worker" lalu memanggil `Timer::add` → `pcntl_alarm(1)` memasang timer SIGALRM, dan proses hang saat keluar
- Perbaikan: setUp mengambil snapshot registri, tearDown memulihkannya (`ReflectionProperty` menulis kembali `workers`/`pidMap`)
- Lokasi: `service/tests/ActionHandlerTest.php`

## admin (49/60; kegagalan semuanya pengujian yang sudah ada sebelumnya dan merupakan masalah lingkungan/konfigurasi)

| Kasus uji | Alasan kegagalan | Kategori |
|------|----------|------|
| EnvConfigTest (4 gagal + 1 error) | `admin/.env` tidak ada; asersi getenv/dotenv gagal | Lingkungan pengujian tanpa .env |
| CaptchaTest (3 error + 1 gagal + 1 risky) | Captcha bergantung pada layanan/Redis yang berjalan; lingkungan unit test mengembalikan null | Ketergantungan lingkungan |
| BackendEnhancementTest (2 gagal) | Mengasersi keberadaan `app/middleware/Cors` dan admin_user berisi searchable — konfigurasi saat ini tidak cocok dengan asersi | Asersi konfigurasi usang |

Catatan: admin/tests semuanya file lama yang sudah ada sebelumnya; tidak ada file unit test admin baru yang ditambahkan pada batch ini (fokus pada service).

## Belum tercakup / untuk ditambahkan

- Modul admin (model/middleware/view) belum memiliki unit test
- Jalur service yang bergantung pada layanan eksternal (ES/gRPC) hanya mendapatkan validasi tingkat unit via stub; cakupan tingkat integrasi disarankan melalui pengujian API
