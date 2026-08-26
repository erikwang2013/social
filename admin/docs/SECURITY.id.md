# Dokumen Desain Arsitektur Keamanan

**语言 / Languages:** [中文](SECURITY.md) · [English](SECURITY.en.md) · [한국어](SECURITY.ko.md) · [Русский](SECURITY.ru.md) · [Deutsch](SECURITY.de.md) · [Français](SECURITY.fr.md) · [Español](SECURITY.es.md) · [Português](SECURITY.pt.md) · [हिन्दी](SECURITY.hi.md) · [العربية](SECURITY.ar.md) · [বাংলা](SECURITY.bn.md) · [Bahasa Indonesia](SECURITY.id.md) · [日本語](SECURITY.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Gambaran umum pertahanan berlapis (Defense in Depth)

Sistem mengadopsi model pertahanan berlapis 7 lapisan, menyaring permintaan berbahaya lapis demi lapis dari luar ke dalam, memastikan bahwa meskipun satu lapisan gagal, garis pertahanan berikutnya tetap berfungsi.

Seluruh rantai middleware dieksekusi dalam urutan berikut (lihat `config/middleware.php`):

```
请求 → Cors → Locale(Accept-Language) → SecurityMiddleware (erikwang2013/security-php, 31种检测器) → RateLimit → [路由组中间件: AdminAuth → AdminPermission → OperationLog] → Controller
```

| Lapisan | Middleware / mekanisme | Tujuan perlindungan |
|----|--------|---------|
| 1 | SecurityMiddleware (erikwang2013/security-php) | 31 deteksi serangan + validasi metode HTTP + batas ukuran body permintaan + validasi Content-Type + CSRF + daftar hitam eskalasi serangan IP |
| 2 | Cors | Keamanan lintas origin + injeksi header respons keamanan |
| 3 | RateLimit | Pembatasan laju jendela geser Redis, anti brute force |
| 4 | AdminAuth | Autentikasi JWT + logout daftar hitam |
| 5 | AdminPermission | Otorisasi RBAC dengan granularitas method.path |
| 6 | OperationLog | Audit operasi + pelacakan sumber klien |
| 7 | Enkripsi data | Pengaburan ID Hashids + enkripsi DB Encryptable + enkripsi transmisi EncryptionService |

Tiga lapisan frontend (Flutter) memiliki validasi input independennya sendiri; backend tidak mempercayai apa pun, setiap lapisan bertahan secara mandiri.

---

## 2. Mesin deteksi serangan

## 2. 攻击检测引擎 (erikwang2013/security-php)

Deteksi serangan telah dimigrasikan dari SecurityMiddleware sendiri ke paket keamanan khusus `erikwang2013/security-php` v1.1+, yang menyediakan **31 detektor**, mencakup 5 kategori serangan besar.

### 2.1 Klasifikasi detektor

**Serangan injeksi (11):** XSS, injeksi SQL, injeksi perintah, injeksi NoSQL, injeksi LDAP, injeksi XPath, JNDI/Log4Shell, SSI server-side include, injeksi GraphQL, injeksi template SSTI

**Serangan protokol dan permintaan (9):** SSRF, XXE, injeksi header respons HTTP, serangan header Host, Request Smuggling, Open Redirect, bypass CORS, pembajakan WebSocket, DNS Rebinding

**Validasi lapisan protokol HTTP (6):** validasi metode HTTP (405), batas ukuran body permintaan (413), validasi Content-Type (415), pemeriksaan Origin CSRF, daftar hitam eskalasi serangan IP, deteksi kebocoran data sensitif

**Serangan data dan serialisasi (5):** deserialisasi PHP, injeksi formula CSV, injeksi header email, serangan JWT (analisis terstruktur), JS Prototype Pollution

**Serangan file dan path (2):** path traversal, unggah file berbahaya

### 2.2 Mode penanganan

Setiap detektor mendukung dua mode secara independen:
- `block` — langsung memblokir saat serangan terdeteksi, mengembalikan kode status yang dikonfigurasi
- `log` — hanya mencatat log tanpa memblokir (`header_injection`, `ssti`, `nosql_injection` secara default dalam mode log untuk mencegah false positive)

### 2.3 Daftar hitam eskalasi serangan IP

IP yang sama memicu 5 deteksi serangan dalam 60 detik → diblokir otomatis selama 15 menit. Backend penyimpanan opsional: Redis (terdistribusi), File (JSON mesin tunggal) atau Cache (file independen konkurensi tinggi); konfigurasi saat ini menggunakan penyimpanan Redis.

### 2.4 Log keamanan

Lokasi file: `runtime/logs/security.log` (rotasi otomatis, 10MB/file)

---

## 4. Header respons keamanan

Semua header diinjeksikan di middleware `Cors`, ditambahkan ke setiap respons melalui `$response->withHeaders()`.

| Header | Nilai | Fungsi |
|----|-----|------|
| Access-Control-Allow-Origin | `*` | Mengizinkan origin mana pun lintas domain (skenario panel admin intranet) |
| Access-Control-Allow-Methods | `GET,POST,PUT,DELETE,OPTIONS` | Kumpulan metode yang diizinkan |
| Access-Control-Allow-Headers | `Authorization,Content-Type,API-Version` | Header khusus yang diizinkan |
| Access-Control-Max-Age | `86400` | Cache permintaan preflight 24 jam |
| X-Content-Type-Options | `nosniff` | Melarang MIME sniffing browser |
| X-Frame-Options | `DENY` | Melarang semua penyematan iframe, anti clickjacking |
| X-XSS-Protection | `1; mode=block` | Mengaktifkan filter XSS bawaan browser dan memblokir rendering halaman |
| Referrer-Policy | `strict-origin-when-cross-origin` | Origin sama mengirim URL lengkap, lintas origin hanya mengirim domain |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | Menonaktifkan API kamera/mikrofon/geolokasi di seluruh situs |

Permintaan preflight OPTIONS langsung mengembalikan respons kosong 204, tidak masuk ke rantai middleware berikutnya.

### 4.2 Content-Security-Policy (CSP)

Diinjeksikan di middleware Cors bersama header keamanan lainnya, memberikan pertahanan berlapis dengan membatasi sumber daya yang boleh dimuat dan dieksekusi browser.

| Header | Nilai | Fungsi |
|----|-----|------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | Membatasi sumber skrip/style/gambar/koneksi/frame/formulir dll. |
| X-Permitted-Cross-Domain-Policies | `none` | Melarang pemuatan file kebijakan lintas domain seperti Adobe Flash/PDF |

Poin kunci kebijakan CSP:
- `default-src 'self'`: secara default hanya mengizinkan sumber daya origin yang sama
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'`: mengizinkan skrip origin sama + skrip inline (wajib untuk Flutter Web) + eval (wajib untuk debug Flutter Web)
- `frame-ancestors 'none'`: melarang penyematan iframe oleh halaman mana pun, jaminan ganda dengan X-Frame-Options: DENY
- `base-uri 'self'`: membatasi tag `<base>` hanya menunjuk ke origin yang sama
- `form-action 'self'`: membatasi formulir hanya mengirim ke origin yang sama

---

## 5. Kebijakan pembatasan laju

### Algoritma

Jendela geser Redis Sorted Set + skrip Lua atomik, operasi utama:

```lua
-- 1. 清理窗口外的旧记录
redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, windowStart)
-- 2. 检查当前窗口计数
local count = redis.call('ZCARD', KEYS[1])
-- 3. 超限则返回 {0, count}，未超限则 ZADD 并返回 {1, count+1}
if count >= limit then return {0, count} end
redis.call('ZADD', KEYS[1], now, now . '.' . random)  -- 随机后缀避免同毫秒覆盖
redis.call('EXPIRE', KEYS[1], window + 10)
```

Skrip Lua dieksekusi dalam single thread di server Redis, **secara alami atomik**, menghilangkan kondisi balapan TOCTOU (Time-of-check to Time-of-use).

### Konfigurasi pembatasan laju

| Rute | Batas | Jendela | Skenario |
|------|------|------|------|
| Default (semua rute) | 60 kali/menit | 60 detik | API umum |
| `/api/auth/login` | 10 kali/menit | 60 detik | Login (anti brute force) |
| `/api/auth/register` | 5 kali/menit | 60 detik | Registrasi (anti registrasi massal) |

### Header respons

Saat batas laju terpicu, mengembalikan HTTP 429 dengan body JSON:
```json
{"code": 429, "message": "请求过于频繁，请稍后再试", "data": []}
```

Semua respons (termasuk respons normal) membawa header berikut:

| Header | Deskripsi |
|----|------|
| X-RateLimit-Limit | Jumlah maksimum permintaan yang diizinkan di jendela saat ini |
| X-RateLimit-Remaining | Jumlah permintaan tersisa di jendela saat ini |
| X-RateLimit-Reset | Timestamp Unix reset jendela |
| Retry-After | Hanya dikirim saat pembatasan laju; detik tunggu yang disarankan |

### Strategi degradasi

Saat Redis bermasalah (timeout koneksi, tidak tersedia, dll.) berlaku **fail-open**:

```php
try {
    $result = Redis::eval($lua, ...);
} catch (\Throwable $e) {
    return $handler($request); // Redis down, 放行所有请求
}
```

Lebih baik kehilangan perlindungan pembatasan laju sementara daripada memblokir permintaan bisnis normal.

### 5.4 Mekanisme penguncian akun

Selain pembatasan laju, antarmuka login menambahkan mekanisme **penguncian akun** untuk mencegah brute force yang ditargetkan pada pengguna tertentu.

**Proses penguncian**:

```
登录失败 → Redis INCR account_lockout:{userId} TTL=900s
连续 5 次失败 → Redis SETEX account_locked:{userId} 900 1
            → 返回 429 "账号已被锁定，请15分钟后再试"
            → 清除计数器 DEL account_lockout:{userId}
```

**Perilaku selama masa penguncian**:

Selama masa penguncian, semua permintaan login langsung mengembalikan 429 tanpa validasi kata sandi, sepenuhnya memblokir upaya brute force.

**Konstanta konfigurasi**:

| Konstanta | Nilai | Arti |
|------|-----|------|
| MAX_LOGIN_ATTEMPTS | 5 | Jumlah maksimum kegagalan berurutan |
| LOCKOUT_DURATION | 900 | Durasi penguncian (detik), yaitu 15 menit |

Catatan: penguncian akun berbasis `userId`, bukan IP, sehingga mengganti IP tidak bisa melewati penguncian. Berlapis dengan pembatasan laju IP (10 kali/menit) membentuk perlindungan ganda:
- Tingkat IP: pembatasan 10 kali/menit mencegah brute force terdistribusi
- Tingkat akun: penguncian setelah 5 kegagalan mencegah brute force yang ditargetkan

---

## 6. Autentikasi dan otorisasi

### 6.1 Autentikasi JWT

Diimplementasikan oleh middleware AdminAuth, dipasang pada grup rute yang memerlukan autentikasi.

**Konfigurasi parameter** (`config/plugin/erikwang2013/jwt/jwt`, diinjeksikan dari `.env`):

| Parameter | Nilai | Deskripsi |
|------|-----|------|
| Algoritma | HS256 | Tanda tangan simetris HMAC-SHA256 |
| Kunci | `JWT_SECRET` | Diinjeksikan dari variabel lingkungan; wajib diganti di produksi |
| TTL access_token | 7200 detik (2 jam) | `JWT_TTL` |
| TTL refresh_token | 1209600 detik (14 hari) | `JWT_REFRESH_TTL` |
| Issuer | `open-admin` | `JWT_ISSUER` |
| Audience | `open-admin` | `JWT_AUDIENCE` |

**Ekstraksi Token**: diekstrak dari header `Authorization: Bearer <token>`, menghapus prefiks `Bearer ` untuk mendapatkan JWT asli.

**Proses autentikasi**:
1. Token kosong → langsung 401 `{"code": 401, "message": "未登录"}`
2. Cek daftar hitam Redis `jwt_blacklist:{md5(token)}` → terdeteksi → 401 `Token已失效，请重新登录`
3. JWT decode → gagal (kedaluwarsa/tanda tangan tidak cocok) → 401 `Token已过期或无效`
4. Berhasil → menginjeksikan `$request->adminId` dan `$request->adminUsername`

**Mekanisme daftar hitam**: saat pengguna logout, `md5(token)` ditulis ke Redis dengan TTL sama dengan sisa masa berlaku JWT. Saat Redis gagal, pemeriksaan daftar hitam dilewati (fail-open); dalam kondisi ini token yang sudah logout dapat digunakan dalam waktu singkat, tetapi masa berlaku JWT yang pendek (2 jam) menjadi perlindungan cadangan.

### 6.2 Batas sesi konkuren

Untuk mencegah penyalahgunaan token bocor di banyak perangkat, sistem membatasi jumlah token valid yang dapat dimiliki satu pengguna secara bersamaan.

**Logika pembatasan**:

```
登录成功 → 签发新 Token
         → 查询当前用户有效 Token 数量: Redis SCARD user_tokens:{userId}
         → 若数量 >= 3（MAX_CONCURRENT_SESSIONS）:
            → 按创建时间升序排列，移除最旧的 Token:
              Redis SREM user_tokens:{userId} <oldest_token_id>
              Redis SETEX jwt_blacklist:{md5(oldest_token)} TTL remaining
         → 将新 Token 加入集合: Redis SADD user_tokens:{userId} <new_token_id>
            Redis SET user_token:{token_id} {userId} EX {TTL}
```

**Konstanta konfigurasi**:

| Konstanta | Nilai | Arti |
|------|-----|------|
| MAX_CONCURRENT_SESSIONS | 3 | Jumlah maksimum token konkuren per pengguna |

**Skenario terlempar keluar**: saat pengguna login di perangkat ke-4, token perangkat ke-1 dipaksa masuk daftar hitam; permintaan berikutnya mengembalikan 401 "Token已失效，请重新登录".

Saat logout, token saat ini dihapus dari kumpulan. Saat token kedaluwarsa secara alami, kunci Redis otomatis kedaluwarsa, dan anggota kumpulan berkurang.

### 6.3 Model izin RBAC

Diimplementasikan oleh middleware AdminPermission.

**Model data**: hubungan tiga lapis User -> Role -> Permission

- `erik_admin_user` (tabel pengguna)
- `erik_admin_user_role` (tabel hubungan pengguna-role)
- `erik_admin_role` (tabel role)
- `erik_admin_role_permission` (tabel hubungan role-izin)
- `erik_admin_permission` (tabel izin)

**Jenis izin**:
| type | Arti | Contoh |
|------|------|------|
| 1 | Izin menu | Mengontrol visibilitas navigasi kiri |
| 2 | Izin tombol | Mengontrol tombol operasi dalam halaman (tambah/edit/hapus) |
| 3 | Izin API | Mengontrol panggilan antarmuka backend |

Format pengenal izin API: `{method}.{path}`

Contohnya:
- `post.admin/user` — membuat pengguna
- `put.admin/user` — mengedit pengguna
- `delete.admin/user` — menghapus pengguna
- `get.admin/user` — melihat daftar pengguna

**Proses otorisasi**:
1. `$request->adminId` kosong → lewati (rute tanpa autentikasi awal terkonfigurasi)
2. Ambil pengguna → role (lewati role nonaktif dengan `status=0`) → daftar izin
3. Super admin (`slug = '*'`) → langsung lewati
4. Bangun `strtolower(method) . '.' . trim(path, '/')` → bandingkan dengan daftar izin
5. Tidak cocok → 403 `{"code": 403, "message": "无权限访问"}`

**Konfirmasi sekunder**: BaseController menyediakan metode `confirmPassword()`; operasi sensitif (menghapus pengguna, ekspor data, dll.) mewajibkan input kata sandi saat ini di lapisan Controller, mencegah operasi tidak sah setelah pembajakan sesi.

---

## 7. Log audit

### 7.1 Log operasi

Middleware OperationLog mencatat log operasi secara otomatis untuk permintaan POST / PUT / DELETE. Permintaan GET tidak dicatat.

**Kolom yang dicatat**:

| Kolom | Sumber | Deskripsi |
|------|------|------|
| id | SnowflakeService::generate() | ID unik global |
| user_id | `$request->adminId` | ID operator; 0 jika belum login |
| action | `$request->method()` | Setara dengan method |
| method | `$request->method()` | POST / PUT / DELETE |
| path | `$request->path()` | Path permintaan |
| ip | `$request->getRealIp()` | IP asli klien |
| source | detectSource() | Platform sumber klien |
| input | body permintaan (JSON ter-masking) | Data yang dikirim operasi |
| created_at | `date('Y-m-d H:i:s')` | Waktu operasi |

**Filter kolom sensitif**: memindai body permintaan secara rekursif; nilai kolom berikut diganti dengan `***`:

`password`, `old_password`, `new_password`, `new_password_confirmation`, `token`, `secret`, `access_token`, `refresh_token`

**Deteksi sumber klien** (`detectSource()`): sesuai prioritas:

1. Pertama membaca header khusus `X-Client-Platform` (dideklarasikan eksplisit oleh klien native)
2. Degradasi ke inferensi dari string User-Agent (urutan deteksi metode `detectSource()`):

| Platform | Kata kunci UA |
|------|----------|
| iPadOS | `iPad` |
| macOS | `Macintosh`, `Mac OS` |
| Windows | `Windows` |
| Linux | `Linux` |
| iOS | `iPhone`, `iOS`, `CFNetwork` |
| Android | `Android` |
| HarmonyOS | `HarmonyOS`, `OpenHarmony` |
| Web | Nilai default fallback |

**Toleransi kesalahan**: kegagalan penulisan log tidak memblokir permintaan bisnis (`catch (\Throwable)` menelan secara diam-diam).

### 7.2 Log keamanan

**Lokasi file**: `runtime/logs/security.log`

**Konten yang dicatat**:
- Log pemblokiran serangan: kategori serangan, IP, path, kolom, sumber, potongan payload (200 karakter pertama)
- Notifikasi pemblokiran IP: IP yang diblokir, jumlah pemicuan

Izin log adalah `FILE_APPEND | LOCK_EX`, menjamin penulisan aman di bawah konkurensi.

---

## 8. Perlindungan data

Sistem mengadopsi strategi perlindungan data tiga lapis, sesuai dengan tiga tahap aliran data.

### 8.1 Lapisan transmisi — EncryptionService

`EncryptionService` menggunakan paket `erikwang2013/encryption` untuk mengenkripsi/mendekripsi kolom sensitif dalam permintaan/respons API.

**Detail teknis**:
- Algoritma: `aes-256-cbc-hmac` (dengan tanda tangan HMAC bawaan anti manipulasi)
- Kunci: variabel lingkungan `ENCRYPTION_KEY`, otomatis diselaraskan ke 32 byte
- Penggunaan: transmisi kolom seperti nomor telepon dan nomor KTP antara klien dan API

**Metode utilitas masking**:
- `maskPhone('13812341234')` → `138****1234`
- `maskEmail('abc@example.com')` → `a***@example.com` (nama pengguna lebih dari 2 karakter) atau `a**@example.com`

### 8.2 Lapisan penyimpanan — Encryptable Cast

Model `AdminUser` menggunakan cast Eloquent `Erikwang2013\Encryptable\Encryptable`, pada kolom terkait:

- `email` → cast ke Encryptable, enkripsi/dekripsi otomatis
- `phone` → cast ke Encryptable, enkripsi/dekripsi otomatis
- `id_card` → cast ke Encryptable, enkripsi/dekripsi otomatis

Saat menulis ke database otomatis dienkripsi menjadi ciphertext; saat membaca otomatis didekripsi menjadi plaintext. Tipe kolom penyimpanan adalah `VARCHAR(500)`, ciphertext disimpan dalam bentuk base64.

**Sistem kunci**: menggunakan `ENCRYPTABLE_KEY` yang independen dari enkripsi lapisan transmisi (`ENCRYPTION_KEY`); kebocoran satu kunci tidak menonaktifkan lapisan lainnya.

Rotasi kunci: variabel lingkungan `ENCRYPTION_PREVIOUS_KEYS` mendukung daftar kunci historis (dipisah koma); saat membaca data lama, mencoba dekripsi dengan kunci historis; saat menulis, mengenkripsi ulang dengan kunci saat ini.

### 8.3 Lapisan presentasi — Pengaburan ID dan masking

**Pengaburan ID Hashids**: `HashidsService` menggunakan paket `erikwang2013/hashids`.

- ID BIGINT database yang dikembalikan API eksternal dienkode menjadi string hash (misal `xK3mN9qR2pL7wV8b`)
- Klien mengirim string hash dalam permintaan; backend otomatis mendekode ke ID asli
- Salt `HASHIDS_SALT` diinjeksikan dari variabel lingkungan; salt berbeda menghasilkan hasil enkode/dekode yang sepenuhnya berbeda
- Panjang minimum hash 16 karakter, menggunakan charset alfanumerik 62 karakter
- BaseController menyediakan metode kemudahan `encodeId()`, `decodeId()`, `encodeIds()`

**Masking ekspor**: pada ekspor Excel/PDF (ExportController), kolom sensitif dimasking secara seragam:
- Nomor telepon: `138****1234`
- Email: `a***@example.com`
- KTP: ditutup total menjadi `********`

---

## 9. Manajemen kunci

Semua kunci diinjeksikan melalui variabel lingkungan `.env`; file konfigurasi membaca dengan `getenv()` dan memiliki nilai default fallback bawaan (hanya aman di lingkungan pengembangan).

| Variabel lingkungan | Penggunaan | Paket | Kebutuhan produksi |
|----------|------|-----|---------|
| JWT_SECRET | Kunci tanda tangan JWT | erikwang2013/jwt-webman | String acak 64+ karakter |
| JWT_ALGORITHM | Algoritma tanda tangan JWT | sama | Pertahankan HS256 |
| HASHIDS_SALT | Salt enkode ID | erikwang2013/hashids | String acak |
| SNOWFLAKE_DATACENTER_ID | ID pusat data (0-31) | erikwang2013/snowflake-php | Pertahankan default di pusat data tunggal |
| ENCRYPTION_KEY | Kunci enkripsi lapisan transmisi API | erikwang2013/encryption | String acak 32 byte |
| ENCRYPTABLE_KEY | Kunci enkripsi lapisan penyimpanan DB | erikwang2013/encryptable | String acak 32 byte, berbeda dari kunci transmisi |

**Persyaratan keamanan**:
- File `.env` sudah masuk `.gitignore`; dilarang keras di-commit ke repositori
- `.env.example` adalah file template publik, tidak berisi kunci asli
- Di produksi **wajib** mengganti semua kunci default dengan string acak
- Disarankan membuat kunci dengan `openssl rand -base64 32`

### Isolasi penyimpanan kunci

| Lapisan | Kunci konfigurasi | Variabel lingkungan kunci |
|----|--------|-------------|
| Enkripsi transmisi | `config/encryption.php` → `key` | `ENCRYPTION_KEY` |
| Enkripsi penyimpanan | `config/encryptable.php` → `key` | `ENCRYPTABLE_KEY` |
| Pengaburan ID | `config/hashids.php` → `connections.main.salt` | `HASHIDS_SALT` |
| Tanda tangan JWT | `config/plugin/erikwang2013/jwt/jwt` | `JWT_SECRET` |

---

## 10. security.txt (RFC 9116)

Sistem menyediakan endpoint informasi kontak keamanan di `/.well-known/security.txt` yang sesuai standar RFC 9116, memudahkan peneliti keamanan menemukan saluran pelaporan dengan cepat saat menemukan kerentanan.

**Cara akses**:

```
GET /.well-known/security.txt
```

**Isi respons**:

```text
Contact: mailto:security@erik.xyz
Expires: 2027-05-20T00:00:00.000Z
Preferred-Languages: zh, en
Canonical: https://erik.xyz/.well-known/security.txt
Policy: https://erik.xyz/security-policy
```

**Penjelasan kolom**:

| Kolom | Deskripsi |
|------|------|
| Contact | Kontak pelaporan kerentanan keamanan |
| Expires | Waktu kedaluwarsa file; perlu diperbarui berkala |
| Preferred-Languages | Bahasa komunikasi yang disukai |
| Canonical | URL kanonik file ini |
| Policy | Tautan kebijakan keamanan/kebijakan pengungkapan kerentanan |

Endpoint ini tidak tunduk pada middleware pembatasan laju atau autentikasi; siapa pun dapat mengaksesnya langsung.

---

## 11. Konfigurasi keamanan Nginx

Proyek menyediakan `docs/nginx-security.conf` sebagai konfigurasi referensi penguatan keamanan proxy terbalik Nginx di lingkungan produksi.

**Tindakan keamanan yang disertakan**:

| Item konfigurasi | Fungsi |
|--------|------|
| `server_tokens off` | Menyembunyikan nomor versi Nginx |
| `client_max_body_size 10m` | Membatasi ukuran body permintaan, bersinergi dengan SecurityMiddleware (erikwang2013/security-php) |
| `limit_req_zone` | Pembatasan frekuensi permintaan di tingkat Nginx |
| `limit_conn_zone` | Pembatasan koneksi konkuren |
| `add_header` header keamanan | Menambahkan X-XSS-Protection dan header keamanan lain di tingkat Nginx |
| `if ($request_method)` | Menolak metode HTTP non-standar di tingkat Nginx |
| Konfigurasi SSL/TLS | Konfigurasi TLS 1.2/1.3 modern, menonaktifkan cipher suite lemah |
| Menyembunyikan header backend | `proxy_hide_header` menghapus header sensitif seperti versi webman |

**Cara penggunaan**: gabungkan konfigurasi dari `docs/nginx-security.conf` ke blok server Nginx Anda, sesuaikan dengan domain dan path sertifikat yang sebenarnya.

---

## 12. Model ancaman

### 12.1 Ancaman yang dilindungi

| Jenis ancaman | Vektor serangan | Lapisan pertahanan |
|----------|---------|---------|
| Penyalahgunaan metode HTTP | Serangan TRACE/TRACK XST, proxy tunnel CONNECT, probing metode WebDAV | Detektor http_method SecurityMiddleware, daftar putih 405 |
| Brute force yang ditargetkan | Upaya kata sandi berulang pada pengguna tertentu | Penguncian akun (5 kegagalan mengunci 15 menit) + RateLimit (login 10/menit) + Captcha |
| Brute force | Upaya username/password terdistribusi dari banyak IP | RateLimit (login 10/menit) + Captcha |
| XSS cross-site scripting | `<script>`, onerror, javascript: | SecurityMiddleware (erikwang2013/security-php) (5 mode) + header respons X-XSS-Protection + CSP |
| Injeksi SQL | UNION SELECT, OR 1=1, bypass komentar | SecurityMiddleware (erikwang2013/security-php) (6 mode) + kueri terparameterisasi Eloquent ORM |
| CSRF cross-site request forgery | Situs jahat mengirim permintaan atas nama pengguna | Validasi Origin/Referer SecurityMiddleware (erikwang2013/security-php) |
| Path traversal | `../../etc/passwd` | Mode path traversal SecurityMiddleware (erikwang2013/security-php) + daftar putih ekstensi UploadController |
| Injeksi perintah | `;ls`, `` `whoami` ``, `$(cat ...)` | SecurityMiddleware (erikwang2013/security-php) (4 mode) |
| Pembajakan sesi | Pencurian token JWT | Masa berlaku JWT pendek (2 jam) + logout daftar hitam + konfirmasi kata sandi sekunder untuk operasi sensitif |
| Enumerasi ID | Menebak ID numerik untuk memperkirakan volume data | Pengaburan Hashids menjadi string acak |
| Kebocoran data | Pencurian DB / man-in-the-middle / kebocoran log | Enkripsi/masking tiga lapis + filter kolom sensitif OperationLog |
| Serangan DoS | Body permintaan raksasa / permintaan frekuensi tinggi | Batas body 10MB + RateLimit 60/menit + daftar hitam IP |
| Eskalasi hak akses | Pengguna berhak rendah mengakses antarmuka admin | Otorisasi RBAC dengan granularitas method.path |
| Serangan unggah file | Ekstensi ganda shell.php.png | Deteksi file berbahaya SecurityMiddleware (erikwang2013/security-php) |

### 12.2 Keterbatasan yang diketahui

| Keterbatasan | Cakupan dampak | Tindakan mitigasi |
|------|---------|---------|
| Perlindungan CSRF hanya efektif untuk browser | Klien non-browser (curl, Postman, aplikasi seluler) dapat melewati pemeriksaan Origin/Referer | Klien non-browser secara alami tidak terkena CSRF; bergantung pada autentikasi JWT menggantikan Cookie |
| Saat Redis tidak tersedia, pembatasan laju dan daftar hitam terdegradasi ke fail-open | Penyerang dapat melewati pembatasan laju dan pemblokiran frekuensi tinggi | Pantau ketersediaan Redis dengan peringatan; daftar hitam IP mendukung tiga backend (file/redis/cache) yang dapat terdegradasi |
| Tidak ada mesin WAF independen | Deteksi berbasis regex, bukan mesin aturan WAF khusus | Di produksi disarankan memasang Nginx ModSecurity atau Cloudflare WAF di depan |
| JWT stateless tidak dapat dinonaktifkan secara proaktif | Token tidak dapat dicabut dari server sebelum kedaluwarsa (selain daftar hitam) | Daftar hitam + TTL pendek 2 jam mengurangi jendela risiko |
| Endpoint admin tanpa pembatasan laju khusus | Antarmuka admin berbagi batas default 60/menit dengan antarmuka biasa | Frekuensi operasi admin secara alami rendah; belum perlu dibedakan |
| Batas backtracking PCRE | Paket menyematkan batas backtracking 1.000.000 + pemulihan finally; input sangat kompleks tetap berisiko performa | Batas ukuran body permintaan (10MB) sebagai cadangan |
