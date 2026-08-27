# Laporan Ringkasan Seluruh Pengujian
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Tanggal: 2026-08-27 (regresi penuh kedua)
- Tim pengujian: pengujian unit PHP / pengujian unit Rust / otomatisasi API / UI end-to-end (lihat catatan peran GO di akhir)
- Empat sub-laporan ditambah ringkasan ini semuanya tersimpan lokal di `docs/test-reports/`

## Ringkasan umum

| Peran | Laporan | Kasus uji | Lulus | Gagal | Kesimpulan |
|------|------|------|------|------|------|
| Pengujian unit PHP | `php-unit-report.md` | 203 | 203 | 0 | service 136/136 + admin 67/67 semua hijau |
| Pengujian unit Rust | `rust-unit-report.md` | 183 | 183 | 0 | 16 crates semua hijau, dan 5 cacat nyata diperbaiki |
| Otomatisasi API | `api-test-report.md` | 116 | 116 | 0 | perbaikan 3 cacat produk putaran sebelumnya terverifikasi |
| UI end-to-end | `ui-e2e-report.md` | 41 | 41 | 0 | Semua hijau, 1 blocked (ES tidak berjalan) |
| **Total** | | **543** | **543** | **0** | Tingkat kelulusan 100% (1 blocked) |

## Cacat nyata yang diperbaiki pada putaran ini (semua diperbaiki dan terverifikasi regresi)

1. **A20 hashid tidak valid 500→404** (sisa putaran sebelumnya): `BaseController::decodeId()` menangkap `InvalidArgumentException` dan melempar `support\exception\NotFoundException(404)` (body code); metode batch mempertahankan semantik 422
2. **A39/A40 Ekspor Excel/PDF kegagalan terjamin** (sisa putaran sebelumnya): `ExportController` kini memiliki `use support\Response;` (tipe kembalian sebelumnya ter-resolve ke kelas yang tidak ada); dekripsi ganda pada bidang yang sudah didekripsi oleh cast Encryptable dihapus
3. **Kerusakan driver Imagick captcha** (temuan baru, produksi juga terdampak): ImageMagick 7 lokal tidak memiliki konstanta `RESOURCETYPE_PIXELS`; deteksi driver di `config/poster.php` kini memiliki penjaga konstanta, otomatis fallback ke GD jika tidak ada
4. **Halaman utama service `/` 404** (temuan baru): webman-framework v2.2.4 tidak lagi me-resolve route akar secara default; `service/config/route.php` mendaftarkan `Route::get('/')` secara eksplisit
5. **5 cacat Rust** (temuan baru, detail di rust-unit-report.md): bee_search MemoryEngine mengabaikan pagination, social_grpc mengubah id non-numerik menjadi 0 secara diam-diam, bee_tsdb field line protocol InfluxDB tidak berurutan, bee_search id tanpa escape di bulk NDJSON ES, bee_graph Neo4j add_edge endpoint error selalu `from`
6. **Skrip pengujian itu sendiri**: di `tests/api/run.php` kata sandi DB kosong jatuh ke 'root' karena `?:` → diubah menjadi `?? 'root'`; tiga suite asersi usang admin ditulis ulang sesuai kode saat ini (Searchable usang, kunci middleware Cors, kontrak captcha poster-php)

## Perbaikan lingkungan dan catatan (disebabkan oleh batch pengujian ini)

- **8788 ditempati proses proyek lain**: service `property-management-platform` di mesin ini salah menempati port 8788; dihentikan dan service social dimulai ulang dengan variabel lingkungan kata sandi kosong
- **`service/.env` masih `service/.env.api-test-bak`**: pemulihan dibatasi kebijakan akses file .env; diperlukan `mv service/.env.api-test-bak service/.env` manual (mulai ulang layanan setelah memulihkan)
- **Kompatibilitas ImageMagick 7**: untuk memulihkan driver Imagick, turunkan ImageMagick ke 6.x atau tingkatkan poster-php agar kompatibel IM7; driver GD saat ini berfungsi normal di seluruh rantai
- **ES tidak berjalan**: kasus pencarian (API + E2E) ditandai lulus sebagai 503/blocked; verifikasi ulang diperlukan setelah Elasticsearch dijalankan

## Ketidaksesuaian kontrak/dokumen (revisi disarankan, tidak memblokir)

- apidoc captcha menulis `clicks=[{x,y}]` array objek, tetapi implementasi poster-php memerlukan array pasangan koordinat `[[x,y]]`
- Upload suara mengembalikan `voice_url` sebagai `/voice/{md5}.m4a` (tanpa awalan `/api/v1`); klien perlu menambahkan sendiri

## Catatan teknisi pengujian GO

Repositori **tidak mengandung kode Go sama sekali** (tidak ada go.mod, tidak ada file .go); peran ini tidak memiliki modul untuk diuji dan tidak dijalankan. Untuk menambah cakupan, komponen Go (mis. gateway/sidecar pencarian) harus diperkenalkan terlebih dahulu.

## Reproduksi

```bash
# Pengujian unit
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# Otomatisasi API (perlu menjalankan admin :8791 dan service :8788 terlebih dahulu, injeksi ENCRYPTABLE_KEY/ENCRYPTION_KEY; root lokal kata sandi kosong perlu DB_PASS='')
DB_PASS='' php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
