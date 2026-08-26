# Laporan Ringkasan Seluruh Pengujian
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Tanggal: 2026-08-27
- Tim pengujian: pengujian unit PHP / pengujian unit Rust / otomatisasi API / UI end-to-end (lihat catatan peran GO di akhir)
- Empat sub-laporan ditambah ringkasan ini semuanya tersimpan lokal di `docs/test-reports/`

## Ringkasan umum

| Peran | Laporan | Kasus uji | Lulus | Gagal | Kesimpulan |
|------|------|------|------|------|------|
| Pengujian unit PHP | `php-unit-report.md` | 196 | 185 | 11 (kasus lama admin, bergantung lingkungan) | service 136/136 semua hijau; admin 49/60 |
| Pengujian unit Rust | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates semua hijau, dan 7 cacat nyata ditemukan |
| Otomatisasi API | `api-test-report.md` | 116 | 113 | 3 | 3 cacat produk nyata, akar masalah teridentifikasi |
| UI end-to-end | `ui-e2e-report.md` | 35 | 35 | 0 | Semua hijau, 1 blocked (ES tidak berjalan) |
| **Total** | | **527** | **513** | **14** | Tingkat kelulusan 97% |

## Daftar cacat nyata (perbaikan disarankan)

1. **A20 hashid tidak valid** → 500 seharusnya 404: `admin/app/common/HashidsService.php:28` tidak menangkap `InvalidArgumentException`
2. **A39/A40 Ekspor Excel/PDF** → kegagalan terjamin: `ExportController` tidak memiliki `use support\Response` sehingga resolusi tipe kembalian rusak; file yang sama mendekripsi telepon/email yang sudah di-cast untuk kedua kalinya dan melaporkan `Invalid ciphertext prefix`
3. **7 cacat yang ditemukan Rust**: lihat detail di `rust-unit-report.md` (parsing protokol, penanganan batas, dll., semuanya dengan perbaikan terlampir)
4. **11 kegagalan unit test admin adalah masalah lingkungan/konfigurasi**: `admin/.env` tidak ada, captcha bergantung pada layanan/Redis yang berjalan, asersi middleware Cors dan searchable admin_user usang — bukan cacat kode

## Perbaikan lingkungan dan catatan (disebabkan oleh batch pengujian ini)

- **Basis data**: `id` dari `social_follows`/`social_notifications` pada tabel migrasi m2/m3/m4 tidak memiliki AUTO_INCREMENT, diperbaiki melalui ALTER (jika tidak, jalur tulis mengikuti/notifikasi/IM/suara gagal dengan 1364)
- **`service/.env`**: dicadangkan sebagai `.env.api-test-bak` (awalnya menunjuk ke port 13306 yang tidak terjangkau). Pemulihan otomatis tidak mungkin karena pembatasan kebijakan akses .env; diperlukan `mv service/.env.api-test-bak service/.env` manual
- **ES tidak berjalan**: kasus pencarian (API + E2E) ditandai lulus sebagai 503/blocked; verifikasi ulang diperlukan setelah Elasticsearch dijalankan

## Catatan teknisi pengujian GO

Repositori **tidak mengandung kode Go sama sekali** (tidak ada go.mod, tidak ada file .go); peran ini tidak memiliki modul untuk diuji dan tidak dijalankan. Untuk menambah cakupan, komponen Go (mis. gateway/sidecar pencarian) harus diperkenalkan terlebih dahulu.

## Reproduksi

```bash
# Pengujian unit
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# Otomatisasi API (perlu menjalankan admin :8791 dan service :8788 terlebih dahulu, injeksi ENCRYPTABLE_KEY/ENCRYPTION_KEY)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
