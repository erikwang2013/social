# Referensi API
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Ringkasan

open-admin dibangun di atas webman v2 dan menyediakan API JSON RESTful. Semua endpoint admin memerlukan autentikasi JWT dan pemeriksaan izin RBAC; endpoint publik dirutekan ke controller ber-version melalui header versi API.

- **URL dasar**: `http://localhost:8787`
- **Versi API**: dikontrol melalui header permintaan `API-Version: v1` (default v1 jika tidak ada)
- **Bahasa**: dialihkan melalui header `Accept-Language` atau parameter `?lang=zh_CN|en` (default zh_CN), terdeteksi otomatis oleh middleware Locale

> **Ringkasan endpoint**: Autentikasi(5) | Dasbor(1) | Pengguna(7) | Peran(4) | Izin(4) | Konfigurasi(4) | Log(1) | Pusat Profil(3) | Impor/Ekspor(3) | Unggah(1) | Operasional(4: health/metrics/docs/security.txt) | Total 37 endpoint
- **Autentikasi**: `Authorization: Bearer <token>` (JWT)
- **Format respons**: `{ "code": 0, "message": "success", "data": {...} }`
- **Endpoint dokumentasi**: `GET /api/docs` mengembalikan spesifikasi JSON OpenAPI 3.0

### Persyaratan Permintaan

- Hanya metode `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` yang diizinkan; penggunaan metode HTTP lain (seperti TRACE, CONNECT, PATCH) akan mengembalikan 405
- Semua permintaan `POST` / `PUT` harus menyetel `Content-Type: application/json` (kecuali unggahan berkas), jika tidak akan mengembalikan 415
- Ukuran body permintaan tidak boleh melebihi 10MB, jika tidak akan mengembalikan 413
- SecurityFilter memindai semua input permintaan untuk XSS, injeksi SQL, path traversal, dan injeksi perintah; jika terdeteksi, mengembalikan 403
- 5 kali gagal login berturut-turut akan memicu penguncian akun (15 menit); selama terkunci, permintaan login mengembalikan 429
- Satu pengguna dapat memegang maksimal 3 token valid sekaligus; jika melebihi, token terlama otomatis masuk blacklist

## 2. Kode Kesalahan

| code | Arti | Skenario pemicu |
|------|------|---------|
| 0 | Berhasil | |
| 400 | Kesalahan parameter permintaan | Format permintaan tidak benar |
| 401 | Tidak terautentikasi | Token hilang / kedaluwarsa / ada di blacklist |
| 403 | Tanpa izin / diblokir keamanan | Izin RBAC tidak cukup / SecurityFilter terpicu |
| 404 | Sumber daya tidak ada | Target kueri/perbarui/hapus tidak ada |
| 405 | Metode permintaan tidak diizinkan | Hanya GET/POST/PUT/DELETE/OPTIONS/HEAD yang diizinkan; metode non-standar langsung ditolak |
| 413 | Body permintaan terlalu besar | Content-Length melebihi 10MB |
| 415 | Tipe media tidak didukung | Content-Type permintaan POST/PUT bukan JSON dan bukan unggahan berkas |
| 422 | Validasi parameter gagal | Kolom wajib hilang, format tidak sesuai, validasi bisnis tidak lolos |
| 429 | Terlalu banyak permintaan | RateLimit terpicu / akun terkunci (5 kali gagal login berturut-turut, terkunci 15 menit) |
| 500 | Kesalahan internal server | |

## 3. Endpoint Publik

Semua endpoint publik dipasang di bawah grup `/api` dan didistribusikan ke controller ber-version melalui middleware `ApiVersion` berdasarkan header `API-Version` (mis. `app\api\v1\controller\AuthController`).

### 3.1 Pemeriksaan Kesehatan

```
GET /health
```

- **Autentikasi**: tidak diperlukan
- **Rate limit**: tidak ada

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "app": "open-admin",
    "version": "1.0",
    "php": "8.3.0",
    "database": "ok",
    "redis": "ok",
    "elasticsearch": "ok",
    "timestamp": 1716249600
  }
}
```

Nilai `database`, `redis`, `elasticsearch`: `"ok"` | `"unavailable"`. `elasticsearch` mengembalikan `"unavailable"` jika ES tidak dapat dijangkau; jika status kesehatan klaster bukan green/yellow, nilai status aktual dikembalikan (mis. `"red"`).

### 3.2 Dokumentasi API

```
GET /api/docs
```

- **Autentikasi**: tidak diperlukan
- **Rate limit**: default global (60 kali/menit)
- **Respons**: spesifikasi JSON OpenAPI 3.0.3, berisi definisi semua endpoint, parameter, dan Schema

### 3.3 Buat Captcha

```
POST /api/captcha/generate
```

- **Autentikasi**: tidak diperlukan
- **Header permintaan**: `API-Version: v1` (wajib)
- **Rate limit**: default global (60 kali/menit)

**Body permintaan**:
```json
{
  "difficulty": "medium"
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| difficulty | string | Tidak | `easy` / `medium` / `hard`, default `medium` |

**Contoh respons** — tipe klik (`type: "click"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "abc123def456",
    "type": "click",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "targets": [
        { "order": 1, "text": "A", "x": 120, "y": 85 },
        { "order": 2, "text": "B", "x": 310, "y": 42 }
      ]
    }
  }
}
```

**Contoh respons** — tipe geser (`type: "slider"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "def456abc789",
    "type": "slider",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "x": 120,
      "y": 60,
      "puzzle_w": 50,
      "puzzle_h": 50,
      "puzzle": "data:image/png;base64,iVBORw0KGgo..."
    }
  }
}
```

**Contoh respons** — tipe putar (`type: "rotate"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "ghi789abc012",
    "type": "rotate",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "angle": 45
    }
  }
}
```

| Kolom | Tipe | Keterangan |
|------|------|------|
| key | string | Identitas captcha, dikirim kembali saat verifikasi |
| type | string | Tipe captcha: `click` / `slider` / `rotate` |
| image | string | Gambar data URI base64 |
| extra | object | Data tambahan terkait tipe (lihat di bawah) |

**Penjelasan `extra` per tipe**:

| type | Kolom extra | Tipe | Keterangan |
|------|-----------|------|------|
| click | targets | array | Target klik, berisi `order`(urutan) `text`(teks petunjuk) `x` `y`(koordinat) |
| slider | x, y | int | Koordinat sudut kiri atas celah (berdasarkan kanvas 300×200) |
| slider | puzzle_w, puzzle_h | int | Lebar dan tinggi gambar puzzle |
| slider | puzzle | string | Gambar puzzle data URI base64 |
| rotate | angle | int | Sudut rotasi yang benar (0-359), perlu diputar `360-angle` agar gambar tegak |

### 3.4 Verifikasi Captcha

```
POST /api/captcha/verify
```

- **Autentikasi**: tidak diperlukan
- **Header permintaan**: `API-Version: v1` (wajib)
- **Rate limit**: default global (60 kali/menit)

**Body permintaan** — tipe klik (`type: "click"`):
```json
{
  "key": "abc123def456",
  "type": "click",
  "clicks": [
    { "x": 120, "y": 85 },
    { "x": 310, "y": 42 }
  ]
}
```

**Body permintaan** — tipe geser (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**Body permintaan** — tipe putar (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| key | string | Ya | Key captcha, dikembalikan oleh generate |
| type | string | Ya | Tipe captcha, harus sama dengan `type` yang dikembalikan oleh generate |
| clicks | varian | Ya | Data jawaban, format berubah sesuai type (lihat di bawah) |

**Penjelasan `clicks` per tipe**:

| type | Tipe clicks | Keterangan | Toleransi kesalahan |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | Array koordinat klik, sesuai urutan order | radius 18px |
| slider | `int` | Offset sumbu X slider | ±4px |
| rotate | `int` | Sudut rotasi (0-359) | ±5° |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

Setelah verifikasi lolos, backend menulis `captcha_verified:{key}` ke Redis (TTL 300 detik), dan endpoint login mengizinkan berdasarkan ini.
Saat verifikasi gagal, `code` adalah 422, `message` adalah `"验证失败，请重试"`, dan `data.valid` adalah `false`.

### 3.5 Masuk

```
POST /api/auth/login
```

- **Autentikasi**: tidak diperlukan
- **Header permintaan**: `API-Version: v1` (wajib)
- **Rate limit**: 10 kali/menit (per IP + jalur)

**Body permintaan**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| Kolom | Tipe | Wajib | Aturan validasi | Keterangan |
|------|------|------|---------|------|
| username | string | Ya | min:3, max:50 | Nama pengguna |
| password | string | Ya | min:6, max:32 (teks biasa) | Dienkripsi AES-256-CBC-HMAC lalu dienkode Base64 (kompatibel teks biasa) |
| captcha_key | string | Ya | | Key captcha (harus lulus verifikasi `/api/captcha/verify` terlebih dahulu) |

### Protokol Enkripsi Kata Sandi

Menggunakan **enkripsi asimetris RSA-2048**; kunci publik disimpan di kode frontend (aman untuk diekspos), kunci privat hanya dipegang server.

```
Alur enkripsi (klien):
  Kunci publik RSA (PEM) → enkripsi PKCS1v1.5 → enkode Base64 → kirim

Alur dekripsi (server, fallback berjenjang):
  1. Dekripsi kunci privat RSA → berhasil dan UTF-8 valid → gunakan hasil dekripsi
  2. Dekripsi AES-256-CBC-HMAC → berhasil → gunakan hasil dekripsi (kompatibilitas klien lama)
  3. Fallback teks biasa → gunakan input mentah langsung
```

Kunci publik ditanam di aplikasi frontend, tidak perlu ditransmisikan melalui jaringan. Kunci privat hanya disimpan di `RSA_PRIVATE_KEY` di `.env` dan tidak boleh bocor.

> Enkripsi simetris AES adalah skema kompatibilitas versi lama; akan dihapus setelah semua klien bermigrasi ke RSA.

**Contoh respons**:
```json
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "a1b2c3d4",
      "username": "admin",
      "real_name": "管理员"
    }
  }
}
```

| Kolom | Tipe | Keterangan |
|------|------|------|
| access_token | string | Token akses JWT |
| refresh_token | string | Token refresh JWT |
| expires_in | int | Masa berlaku token akses (detik), default 7200 |
| user.id | string | ID pengguna terenkripsi hashid |
| user.username | string | Nama pengguna |
| user.real_name | string | Nama asli |

**Kemungkinan kesalahan**:
- 422: Validasi parameter gagal (kolom wajib hilang, format tidak sesuai)
- 422: Selesaikan verifikasi captcha terlebih dahulu (captcha_key belum lulus `/api/captcha/verify`)
- 401: Nama pengguna atau kata sandi salah
- 403: Akun telah dinonaktifkan
- 429: Akun telah dikunci, coba lagi dalam 15 menit (dipicu oleh 5 kali gagal login berturut-turut)

### 3.6 Pendaftaran

```
POST /api/auth/register
```

- **Autentikasi**: tidak diperlukan
- **Header permintaan**: `API-Version: v1` (wajib)
- **Rate limit**: 5 kali/menit (per IP + jalur)

**Body permintaan**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| Kolom | Tipe | Wajib | Aturan validasi | Keterangan |
|------|------|------|---------|------|
| username | string | Ya | min:3, max:50 | Nama pengguna (unik) |
| password | string | Ya | min:6, max:32 (teks biasa) | Dienkripsi AES-256-CBC-HMAC lalu dienkode Base64 |
| real_name | string | Ya | max:50 | Nama asli |
| captcha_key | string | Ya | | Key captcha (harus lulus verifikasi `/api/captcha/verify` terlebih dahulu) |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "注册成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "e5f6g7h8",
      "username": "newuser",
      "real_name": "新用户"
    }
  }
}
```

Setelah registrasi berhasil, token JWT langsung dikembalikan; status pengguna aktif secara default (status=1).

### 3.7 Perbarui Token

```
POST /api/auth/refresh
```

- **Autentikasi**: tidak diperlukan
- **Header permintaan**: `API-Version: v1` (wajib)
- **Rate limit**: default global (60 kali/menit)

**Body permintaan**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| refresh_token | string | Ya | refresh_token yang diperoleh saat login/registrasi |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200
  }
}
```

Pembaruan yang berhasil sekaligus mengembalikan access_token dan refresh_token baru; token lama otomatis tidak berlaku. Pembaruan juga memperbarui waktu login terakhir dan IP pengguna.

**Kemungkinan kesalahan**:
- 422: Token refresh tidak ada
- 401: Token refresh tidak valid atau kedaluwarsa

### 3.8 Metrik Pemantauan Prometheus

```
GET /metrics
```

- **Autentikasi**: tidak diperlukan
- **Rate limit**: tidak ada
- **Format respons**: format teks Prometheus (`text/plain; version=0.0.4`)

Endpoint metrik pemantauan Prometheus publik, untuk diambil oleh Grafana/Prometheus.

**Contoh respons**:
```
# HELP openadmin_http_requests_total Total number of HTTP requests.
# TYPE openadmin_http_requests_total gauge
openadmin_http_requests_total 15234

# HELP openadmin_active_users Number of active users.
# TYPE openadmin_active_users gauge
openadmin_active_users 156

# HELP openadmin_db_connection_status Database connection status (1=ok, 0=fail).
# TYPE openadmin_db_connection_status gauge
openadmin_db_connection_status 1

# HELP openadmin_redis_connection_status Redis connection status (1=ok, 0=fail).
# TYPE openadmin_redis_connection_status gauge
openadmin_redis_connection_status 1

# HELP openadmin_memory_usage_bytes Memory usage in bytes.
# TYPE openadmin_memory_usage_bytes gauge
openadmin_memory_usage_bytes 18874368
```

| Nama metrik | Tipe | Keterangan |
|------|------|------|
| `openadmin_http_requests_total` | gauge | Total permintaan HTTP kumulatif |
| `openadmin_active_users` | gauge | Jumlah pengguna aktif saat ini (login dalam 24 jam) |
| `openadmin_db_connection_status` | gauge | Status koneksi database, 1=normal, 0=abnormal |
| `openadmin_redis_connection_status` | gauge | Status koneksi Redis, 1=normal, 0=abnormal |
| `openadmin_memory_usage_bytes` | gauge | Penggunaan memori proses PHP saat ini (bytes) |

## 4. Dasbor

Semua endpoint admin dipasang di bawah grup `/admin` dan melewati tiga middleware: `AdminAuth` (autentikasi JWT), `AdminPermission` (pemeriksaan izin RBAC), `OperationLog` (pencatatan operasi).

### 4.1 Data Dasbor

```
GET /admin/dashboard
```

- **Autentikasi**: JWT + RBAC
- **Cache**: Redis 5 menit

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "stats": [
      {
        "label": "用户总数",
        "value": "1280",
        "icon": "people",
        "color": "#1677FF",
        "trend": 12.5
      },
      {
        "label": "今日新增",
        "value": "23",
        "icon": "person_add",
        "color": "#52C41A"
      },
      {
        "label": "活跃用户",
        "value": "156",
        "icon": "bolt",
        "color": "#FA8C16"
      },
      {
        "label": "操作日志",
        "value": "432",
        "icon": "description",
        "color": "#722ED1"
      }
    ],
    "trends": {
      "dates": ["2026-04-22", "2026-04-23", "..."],
      "series": [
        { "name": "累计用户", "data": [1200, 1210, "..."], "color": "#1677FF" },
        { "name": "操作日志", "data": [45, 52, "..."], "color": "#52C41A" }
      ]
    },
    "distribution": {
      "user_status": [
        { "name": "启用", "value": 1250 },
        { "name": "禁用", "value": 30 }
      ]
    },
    "recent_logs": [
      {
        "id": "hashid...",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "user_name": "admin",
        "created_at": "2026-05-21 10:30:00"
      }
    ]
  }
}
```

| Kolom stats | Tipe | Keterangan |
|------|------|------|
| label | string | Nama metrik |
| value | string | Nilai metrik (tipe string) |
| icon | string | Nama ikon Material |
| color | string | Nilai warna kartu |
| trend | float? | Tingkat pertumbuhan harian (persentase); hanya "total pengguna" yang memiliki kolom ini |

| Kolom trends | Tipe | Keterangan |
|------|------|------|
| dates | array{string} | Urutan tanggal 30 hari terakhir |
| series | array{object} | Data garis tren, masing-masing berisi name (nama), data (array nilai), color (warna) |

## 5. Manajemen Pengguna

Semua `id` yang dikembalikan oleh endpoint manajemen pengguna adalah string terenkripsi hashid. Kolom kata sandi telah dikecualikan dari respons. Nomor ponsel dan email ditampilkan dengan masking di endpoint daftar, dan dikembalikan sebagai teks biasa di endpoint detail (kolom terenkripsi database didekripsi otomatis oleh trait Encryptable).

### 5.1 Daftar Pengguna

```
GET /admin/user
```

- **Autentikasi**: JWT + RBAC

**Parameter kueri**:

| Parameter | Tipe | Wajib | Nilai default | Keterangan |
|------|------|------|------|------|
| page | int | Tidak | 1 | Nomor halaman |
| limit | int | Tidak | 15 | Jumlah per halaman |
| keyword | string | Tidak | | Kata kunci pencarian, cocok dengan nama pengguna dan nama asli |
| status | int | Tidak | | Filter status, 0=dinonaktifkan, 1=aktif |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "a1b2c3d4",
        "username": "admin",
        "real_name": "管理员",
        "phone": "138****5678",
        "email": "a***@example.com",
        "status": 1,
        "last_login_at": "2026-05-21 10:30:00",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 100,
    "page": 1,
    "limit": 15
  }
}
```

| Kolom | Tipe | Keterangan |
|------|------|------|
| id | string | ID pengguna terenkripsi hashid |
| username | string | Nama pengguna |
| real_name | string | Nama asli |
| phone | string | Nomor ponsel dengan masking (`138****5678`) |
| email | string | Email dengan masking (`a***@example.com`) |
| status | int | 1=aktif, 0=dinonaktifkan |
| last_login_at | string | Waktu login terakhir (datetime) |
| created_at | string | Waktu dibuat (datetime) |

### 5.2 Buat Pengguna

```
POST /admin/user
```

- **Autentikasi**: JWT + RBAC

**Body permintaan**:
```json
{
  "username": "newuser",
  "password": "123456",
  "real_name": "新用户",
  "phone": "13800138000",
  "email": "newuser@example.com",
  "status": 1
}
```

| Kolom | Tipe | Wajib | Aturan validasi | Keterangan |
|------|------|------|---------|------|
| username | string | Ya | min:3, max:50 | Nama pengguna (unik) |
| password | string | Ya | min:6, max:32 | Kata sandi (disimpan sebagai bcrypt) |
| real_name | string | Ya | max:50 | Nama asli |
| phone | string | Tidak | | Nomor ponsel (disimpan terenkripsi Encryptable) |
| email | string | Tidak | | Email (disimpan terenkripsi Encryptable) |
| status | int | Tidak | in:0,1 | Status, default 1 (aktif) |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "e5f6g7h8",
    "username": "newuser",
    "real_name": "新用户",
    "phone": "13800138000",
    "email": "newuser@example.com",
    "status": 1,
    "created_at": "2026-05-21 12:00:00"
  }
}
```

**Kemungkinan kesalahan**:
- 422: Nama pengguna sudah ada
- 422: Validasi parameter gagal (kolom wajib hilang)

### 5.3 Detail Pengguna

```
GET /admin/user/{id}
```

- **Autentikasi**: JWT + RBAC
- **Parameter jalur**: `{id}` adalah ID pengguna terenkripsi hashid

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "管理员",
    "phone": "13800138000",
    "email": "admin@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00",
    "last_login_ip": "192.168.1.1",
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-05-20 08:00:00"
  }
}
```

Di endpoint detail, `phone` dan `email` dikembalikan sebagai teks biasa (tersimpan terenkripsi di database, didekripsi otomatis oleh cast Encryptable), tanpa masking. `password` dan `id_card` tidak pernah ada dalam respons.

**Kemungkinan kesalahan**:
- 404: Pengguna tidak ada

### 5.4 Perbarui Pengguna

```
PUT /admin/user/{id}
```

- **Autentikasi**: JWT + RBAC
- **Parameter jalur**: `{id}` adalah ID pengguna terenkripsi hashid

**Body permintaan**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| real_name | string | Tidak | Nama asli, biarkan kosong untuk mempertahankan nilai lama |
| password | string | Tidak | Kata sandi baru; string kosong atau tidak dikirim berarti tidak diubah |
| phone | string | Tidak | Nomor ponsel |
| email | string | Tidak | Email |
| status | int | Tidak | 0=dinonaktifkan, 1=aktif |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13900139000",
    "email": "new@example.com",
    "status": 1,
    "updated_at": "2026-05-21 12:30:00"
  }
}
```

**Kemungkinan kesalahan**:
- 404: Pengguna tidak ada

### 5.5 Hapus Pengguna

```
DELETE /admin/user/{id}
```

- **Autentikasi**: JWT + RBAC
- **Parameter jalur**: `{id}` adalah ID pengguna terenkripsi hashid
- **Operasi sensitif**: memerlukan konfirmasi ulang kata sandi

**Body permintaan**:
```json
{
  "password": "admin_password"
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| password | string | Ya | Kata sandi pengguna yang sedang login (konfirmasi ulang) |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Mengeksekusi soft delete (Eloquent SoftDeletes), data ditandai deleted_at tanpa penghapusan fisik.

**Kemungkinan kesalahan**:
- 404: Pengguna tidak ada
- 422: Operasi sensitif memerlukan konfirmasi kata sandi (password kosong)
- 422: Verifikasi kata sandi gagal (kata sandi tidak cocok)

### 5.6 Hapus Pengguna Massal

```
POST /admin/user/batch/destroy
```

- **Autentikasi**: JWT + RBAC
- **Operasi sensitif**: memerlukan konfirmasi ulang kata sandi

**Body permintaan**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| ids | array{string} | Ya | Array ID pengguna terenkripsi hashid |
| password | string | Ya | Kata sandi pengguna yang sedang login (konfirmasi ulang) |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

Mengeksekusi soft delete; `data.count` adalah jumlah yang benar-benar dihapus.

**Kemungkinan kesalahan**:
- 422: Pilih pengguna yang akan dihapus (ids kosong)
- 422: ID tidak valid (dekode hashid gagal)
- 422: Verifikasi kata sandi gagal

### 5.7 Aktifkan/Nonaktifkan Pengguna Massal

```
POST /admin/user/batch/status
```

- **Autentikasi**: JWT + RBAC

**Body permintaan**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| ids | array{string} | Ya | Array ID pengguna terenkripsi hashid |
| status | int | Ya | 0=dinonaktifkan, 1=aktif |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

message berubah dinamis berdasarkan status menjadi `"批量启用成功"` atau `"批量禁用成功"`.

**Kemungkinan kesalahan**:
- 422: Pilih pengguna (ids kosong)
- 422: Nilai status tidak valid (status bukan 0 atau 1)

## 6. Manajemen Peran

### 6.1 Daftar Peran

```
GET /admin/role
```

- **Autentikasi**: JWT + RBAC

**Parameter kueri**:

| Parameter | Tipe | Wajib | Nilai default | Keterangan |
|------|------|------|------|------|
| page | int | Tidak | 1 | Nomor halaman |
| limit | int | Tidak | 15 | Jumlah per halaman |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "r1r2r3r4",
        "name": "超级管理员",
        "slug": "super_admin",
        "description": "拥有所有权限",
        "status": 1,
        "users_count": 3,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 5,
    "page": 1,
    "limit": 15
  }
}
```

| Kolom | Tipe | Keterangan |
|------|------|------|
| id | string | ID peran terenkripsi hashid |
| name | string | Nama peran |
| slug | string | Identitas peran (unik, digunakan untuk pemeriksaan izin) |
| description | string | Deskripsi peran |
| status | int | 1=aktif, 0=dinonaktifkan |
| users_count | int | Jumlah pengguna dengan peran ini |

### 6.2 Buat Peran

```
POST /admin/role
```

- **Autentikasi**: JWT + RBAC

**Body permintaan**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| Kolom | Tipe | Wajib | Aturan validasi | Keterangan |
|------|------|------|---------|------|
| name | string | Ya | max:50 | Nama peran |
| slug | string | Ya | max:50 | Identitas peran |
| description | string | Tidak | | Deskripsi peran, default string kosong |
| status | int | Tidak | | Status, default 1 |
| permission_ids | array{int} | Tidak | | Array ID izin (ID INT asli, bukan hashid) |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "编辑员",
    "slug": "editor",
    "description": "内容编辑角色",
    "status": 1
  }
}
```

### 6.3 Perbarui Peran

```
PUT /admin/role/{id}
```

- **Autentikasi**: JWT + RBAC

**Body permintaan**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| name | string | Tidak | Nama peran |
| description | string | Tidak | Deskripsi |
| status | int | Tidak | 0=dinonaktifkan, 1=aktif |
| permission_ids | array{int} | Tidak | Array ID izin; jika dikirim, izin peran disinkronkan (ditimpa) |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "内容编辑",
    "slug": "editor",
    "description": "更新后的描述",
    "status": 1
  }
}
```

### 6.4 Hapus Peran

```
DELETE /admin/role/{id}
```

- **Autentikasi**: JWT + RBAC
- **Operasi sensitif**: memerlukan konfirmasi ulang kata sandi

**Body permintaan**:
```json
{
  "password": "admin_password"
}
```

**Contoh respons**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Saat menghapus, relasi peran dengan semua izin dan pengguna dilepas otomatis, lalu catatan peran dihapus secara fisik.

## 7. Manajemen Izin

Izin menggunakan struktur pohon (parent_id self-reference) dan dibagi menjadi tiga tipe. Endpoint daftar mengembalikan pohon izin lengkap.

### 7.1 Pohon Izin

```
GET /admin/permission
```

- **Autentikasi**: JWT + RBAC

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "id": "p1p2p3p4",
      "parent_id": "0",
      "name": "用户管理",
      "slug": "/admin/user",
      "type": 1,
      "icon": "people",
      "path": "/user",
      "sort": 1,
      "created_at": "2026-01-01 00:00:00",
      "children": [
        {
          "id": "p5p6p7p8",
          "parent_id": "p1p2p3p4",
          "name": "用户列表",
          "slug": "/admin/user/index",
          "type": 2,
          "icon": "",
          "path": "/user/index",
          "sort": 1
        }
      ]
    }
  ]
}
```

| Kolom | Tipe | Keterangan |
|------|------|------|
| id | string | Terenkripsi hashid |
| parent_id | string | hashid izin induk, "0" berarti node akar |
| name | string | Nama izin |
| slug | string | Identitas izin (identitas rute/tombol) |
| type | int | 1=menu, 2=tombol, 3=API |
| icon | string | Ikon menu (nama ikon Material) |
| path | string | Jalur rute frontend |
| sort | int | Nilai urutan (ascending) |
| children | array? | Daftar izin anak (rekursif); tidak disertakan jika tidak ada node anak |

### 7.2 Buat Izin

```
POST /admin/permission
```

- **Autentikasi**: JWT + RBAC

**Body permintaan**:
```json
{
  "parent_id": 0,
  "name": "系统设置",
  "slug": "/admin/config",
  "type": 1,
  "icon": "settings",
  "path": "/config",
  "sort": 99
}
```

| Kolom | Tipe | Wajib | Aturan validasi | Keterangan |
|------|------|------|---------|------|
| parent_id | int | Tidak | | ID izin induk (tipe INT asli), default 0 |
| name | string | Ya | max:50 | Nama izin |
| slug | string | Ya | max:100 | Identitas izin |
| type | int | Ya | in:1,2,3 | 1=menu, 2=tombol, 3=API |
| icon | string | Tidak | | Ikon menu, default kosong |
| path | string | Tidak | | Jalur rute frontend, default kosong |
| sort | int | Tidak | | Nilai urutan, default 0 |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "p9p0a1b2",
    "parent_id": "0",
    "name": "系统设置",
    "slug": "/admin/config",
    "type": 1,
    "icon": "settings",
    "path": "/config",
    "sort": 99
  }
}
```

### 7.3 Perbarui Izin

```
PUT /admin/permission/{id}
```

- **Autentikasi**: JWT + RBAC

**Body permintaan**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| name | string | Tidak | Nama izin |
| icon | string | Tidak | Ikon |
| path | string | Tidak | Jalur rute |
| sort | int | Tidak | Nilai urutan |

### 7.4 Hapus Izin

```
DELETE /admin/permission/{id}
```

- **Autentikasi**: JWT + RBAC
- **Operasi sensitif**: memerlukan konfirmasi ulang kata sandi

**Body permintaan**:
```json
{
  "password": "admin_password"
}
```

**Contoh respons**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Saat menghapus, semua izin anak dihapus berjenjang (`parent_id` = catatan dengan ID izin saat ini), sekaligus melepas relasi dengan semua peran.

## 8. Konfigurasi Sistem

Konfigurasi sistem unik berdasarkan kombinasi `group` + `key`.

### 8.1 Daftar Konfigurasi

```
GET /admin/config
```

- **Autentikasi**: JWT + RBAC

**Parameter kueri**:

| Parameter | Tipe | Wajib | Nilai default | Keterangan |
|------|------|------|------|------|
| page | int | Tidak | 1 | Nomor halaman |
| limit | int | Tidak | 15 | Jumlah per halaman |
| group | string | Tidak | | Filter berdasarkan grup konfigurasi |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "c1c2c3c4",
        "group": "system",
        "key": "site_name",
        "value": "开放管理后台",
        "type": "string",
        "description": "站点名称",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 20,
    "page": 1,
    "limit": 15
  }
}
```

| Kolom | Tipe | Keterangan |
|------|------|------|
| id | string | hashid |
| group | string | Grup konfigurasi (mis. `system`, `email`, `storage`) |
| key | string | Kunci konfigurasi |
| value | string | Nilai konfigurasi |
| type | string | Petunjuk tipe nilai (`string`, `integer`, `boolean`, `json`, dll.) |
| description | string | Deskripsi konfigurasi |

### 8.2 Buat Konfigurasi

```
POST /admin/config
```

- **Autentikasi**: JWT + RBAC

**Body permintaan**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| Kolom | Tipe | Wajib | Aturan validasi | Keterangan |
|------|------|------|---------|------|
| group | string | Ya | max:100 | Grup konfigurasi |
| key | string | Ya | max:100 | Kunci konfigurasi (unik dalam grup yang sama) |
| value | string | Ya | | Nilai konfigurasi |
| type | string | Tidak | | Tipe nilai, default `string` |
| description | string | Tidak | | Deskripsi konfigurasi, default kosong |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "c5c6c7c8",
    "group": "email",
    "key": "smtp_host",
    "value": "smtp.example.com",
    "type": "string",
    "description": "SMTP 服务器地址"
  }
}
```

**Kemungkinan kesalahan**:
- 422: Item konfigurasi sudah ada (group + key sama)

### 8.3 Perbarui Konfigurasi

```
PUT /admin/config/{id}
```

- **Autentikasi**: JWT + RBAC

**Body permintaan**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| value | string | Tidak | Perbarui nilai konfigurasi |
| type | string | Tidak | Perbarui tipe nilai |
| description | string | Tidak | Perbarui teks deskripsi |

### 8.4 Hapus Konfigurasi

```
DELETE /admin/config/{id}
```

- **Autentikasi**: JWT + RBAC
- **Operasi sensitif**: memerlukan konfirmasi ulang kata sandi

**Body permintaan**:
```json
{
  "password": "admin_password"
}
```

Menghapus catatan konfigurasi secara fisik.

## 9. Log Operasi

Log operasi adalah antarmuka hanya-baca; middleware `OperationLog` menulis secara otomatis pada setiap permintaan POST/PUT/DELETE, dengan kolom penyimpanan termasuk `user_id`, `action`, `method`, `path`, `ip`, `source`, `input`.

### 9.1 Daftar Log Operasi

```
GET /admin/log
```

- **Autentikasi**: JWT + RBAC

**Parameter kueri**:

| Parameter | Tipe | Wajib | Nilai default | Keterangan |
|------|------|------|------|------|
| page | int | Tidak | 1 | Nomor halaman |
| limit | int | Tidak | 15 | Jumlah per halaman |
| user_id | int | Tidak | | Filter presisi berdasarkan ID pengguna (tipe INT asli) |
| action | string | Tidak | | Filter presisi berdasarkan aksi operasi |
| path | string | Tidak | | Filter fuzzy berdasarkan jalur permintaan |
| start_date | string | Tidak | | Tanggal mulai (format Y-m-d) |
| end_date | string | Tidak | | Tanggal akhir (format Y-m-d) |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "l1l2l3l4",
        "user_name": "admin",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "source": "web",
        "input": "{\"username\":\"admin\"}",
        "created_at": "2026-05-21 10:30:00"
      }
    ],
    "total": 500,
    "page": 1,
    "limit": 15
  }
}
```

| Kolom | Tipe | Keterangan |
|------|------|------|
| id | string | hashid |
| user_name | string | Nama pengguna operasi (diambil melalui relasi user; operasi tanpa login menampilkan "系统") |
| action | string | Deskripsi aksi operasi |
| method | string | Metode HTTP (POST/PUT/DELETE) |
| path | string | Jalur permintaan |
| ip | string | IP klien |
| source | string | Sumber permintaan |
| input | string | String JSON parameter permintaan (tidak termasuk berkas) |
| created_at | string | Waktu operasi (datetime) |

## 10. Pusat Profil

Endpoint pusat profil hanya memerlukan autentikasi JWT (tidak memerlukan pemeriksaan izin RBAC — middleware `AdminPermission` harus memasukkannya ke whitelist).

### 10.1 Perbarui Informasi Pribadi

```
PUT /admin/profile
```

- **Autentikasi**: JWT

**Body permintaan**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| real_name | string | Tidak | Nama asli |
| phone | string | Tidak | Nomor ponsel (disimpan terenkripsi Encryptable) |
| email | string | Tidak | Email (disimpan terenkripsi Encryptable) |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13800138000",
    "email": "me@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00"
  }
}
```

Dalam respons, `phone` dan `email` dikembalikan sebagai teks biasa; `password` dan `id_card` telah dihapus.

### 10.2 Ubah Kata Sandi

```
PUT /admin/profile/password
```

- **Autentikasi**: JWT

**Body permintaan**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| Kolom | Tipe | Wajib | Aturan validasi | Keterangan |
|------|------|------|---------|------|
| old_password | string | Ya | | Kata sandi saat ini |
| new_password | string | Ya | min:6, max:32 | Kata sandi baru |

**Contoh respons**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**Kemungkinan kesalahan**:
- 422: Isi kata sandi lama dan kata sandi baru
- 422: Kata sandi lama salah
- 422: Panjang kata sandi baru 6-32 karakter

### 10.3 Keluar

```
POST /admin/profile/logout
```

- **Autentikasi**: JWT

**Body permintaan**: tidak ada (tanpa requestBody, token dibaca dari header Authorization)

**Contoh respons**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

Logika keluar: dekode JWT untuk mendapatkan sisa masa berlaku (exp - now), tulis hash md5 token tersebut ke blacklist Redis `jwt_blacklist:{md5}`, TTL = sisa masa berlaku. Token dalam blacklist dicegat di middleware `AdminAuth` dan mengembalikan 401.

Tanpa token, mengembalikan 401. Token kedaluwarsa/tidak valid (dekode melempar pengecualian) tetap dianggap berhasil keluar.

## 11. Impor/Ekspor

### 11.1 Ekspor Excel

```
POST /admin/export/excel
```

- **Autentikasi**: JWT + RBAC
- **Tipe respons**: unduhan berkas (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Body permintaan**:
```json
{
  "table": "admin_user",
  "columns": ["username", "real_name", "phone", "status", "created_at"],
  "conditions": {
    "status": 1
  },
  "title": "用户列表导出"
}
```

| Kolom | Tipe | Wajib | Nilai default | Keterangan |
|------|------|------|------|------|
| table | string | Tidak | `admin_user` | Nama tabel ekspor. Didukung: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | Tidak | | Array nama kolom yang diekspor; kosong berarti mengekspor semua kolom tabel |
| conditions | object | Tidak | `{}` | Kondisi filter, pasangan key-value; digunakan untuk WHERE jika nilai tidak kosong |
| title | string | Tidak | `数据导出` | Judul Excel (ditampilkan sebagai nama Sheet) |

**Tabel dan kolom yang didukung**:

| table | Kolom tersedia |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

Kolom sensitif `phone`, `email`, `id_card` otomatis dimasking saat ekspor. Batas data 10000 baris. Baris pertama Excel dibekukan dan filter otomatis diaktifkan.

### 11.2 Ekspor PDF

```
POST /admin/export/pdf
```

- **Autentikasi**: JWT + RBAC
- **Tipe respons**: unduhan berkas (`application/pdf`, A4 lanskap)

**Body permintaan**:
```json
{
  "type": "dashboard",
  "title": "管理仪表盘报告",
  "data": {
    "stats": [
      { "label": "用户总数", "value": "1280" }
    ]
  }
}
```

Atau mode tabel:
```json
{
  "type": "table",
  "title": "用户列表",
  "data": {
    "columns": ["用户名", "真实姓名", "状态"],
    "rows": [
      ["admin", "管理员", "启用"],
      ["editor", "编辑员", "启用"]
    ]
  }
}
```

| Kolom | Tipe | Wajib | Nilai default | Keterangan |
|------|------|------|------|------|
| type | string | Tidak | `table` | Tipe ekspor: `table` / `dashboard` |
| title | string | Tidak | `数据导出` | Judul PDF |
| data | object | Tidak | `{}` | Data ekspor |

Saat `type=dashboard`, `data` harus berisi array `stats` (dirender sebagai kartu); saat `type=table`, `data` harus berisi array `columns` dan `rows`.

Template PDF menyertakan informasi hak cipta dan stempel waktu ekspor.

### 11.3 Impor Pengguna (Excel)

```
POST /admin/import/users
```

- **Autentikasi**: JWT + RBAC
- **Tipe permintaan**: `multipart/form-data` (unggahan berkas)

**Kolom formulir**:

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| file | file | Ya | Format `.xlsx` atau `.xls` |

**Persyaratan kolom Excel**:

| Nama kolom | Wajib | Keterangan |
|------|------|------|
| username | Ya | Nama pengguna (unik) |
| password | Ya | Kata sandi (disimpan sebagai hash bcrypt) |
| real_name | Ya | Nama asli |
| phone | Tidak | Nomor ponsel |
| email | Tidak | Email |
| status | Tidak | Status, default 1 |

Baris 1 adalah judul kolom (tidak peka huruf besar/kecil); data dimulai dari baris 2.

**Contoh respons**:
```json
{
  "code": 0,
  "message": "导入完成",
  "data": {
    "total": 10,
    "success": 8,
    "failed": 2,
    "errors": [
      { "row": 3, "reason": "用户名为空" },
      { "row": 7, "reason": "用户名 zhangsan 已存在" }
    ]
  }
}
```

| Kolom | Tipe | Keterangan |
|------|------|------|
| total | int | Total baris (tidak termasuk baris judul) |
| success | int | Jumlah berhasil diimpor |
| failed | int | Jumlah gagal |
| errors | array | Detail kegagalan, masing-masing berisi row (nomor baris Excel) dan reason (alasan kegagalan) |

## 12. Unggah Berkas

```
POST /admin/upload
```

- **Autentikasi**: JWT + RBAC
- **Tipe permintaan**: `multipart/form-data`

**Kolom formulir**:

| Kolom | Tipe | Wajib | Keterangan |
|------|------|------|------|
| file | file | Ya | Berkas yang diunggah |

**Tipe berkas yang diizinkan**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**Ukuran berkas maksimum**: 10MB

**Contoh respons**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

Berkas disimpan dalam direktori per tanggal di `public/upload/{Y-m-d}/`, nama berkas adalah `md5(uniqid) + ekstensi asli`. `url` adalah jalur relatif terhadap root situs.

**Kemungkinan kesalahan**:
- 422: Pilih berkas (tidak ada yang diunggah)
- 422: Tipe berkas tidak didukung
- 422: Ukuran berkas tidak boleh melebihi 10MB
- 500: Unggahan berkas gagal (berkas tidak valid)

## 13. Header Respons

Semua endpoint (disuntikkan di lapisan middleware global) menyertakan header respons berikut:

| Header | Keterangan |
|----|------|
| `X-RateLimit-Limit` | Batas rate limit (jumlah) |
| `X-RateLimit-Remaining` | Sisa jumlah permintaan |
| `X-RateLimit-Reset` | Stempel waktu reset jendela rate limit |
| `Retry-After` | Hanya dikembalikan saat rate limit terpicu, detik yang disarankan untuk menunggu |
| `X-Content-Type-Options` | `nosniff` (default webman, melarang MIME sniffing) |
| `X-Frame-Options` | `DENY` (disediakan oleh middleware CORS/konfigurasi dasar webman) |

Detail rate limit:
- Batas global default: 60 kali/menit / IP+jalur
- Endpoint login `/api/auth/login`: 10 kali/menit
- Endpoint registrasi `/api/auth/register`: 5 kali/menit
- Menggunakan algoritma sliding window atomik Redis (Lua ZSET), menghindari kondisi balapan TOCTOU
- Jika Redis tidak tersedia, fail open (lolos), tidak memblokir permintaan

## 14. Alur Autentikasi

Urutan autentikasi lengkap:

```
1. Klien meminta POST /api/captcha/generate
   (Header permintaan: API-Version: v1)
    ↓
   Server mengembalikan: key + type(click|slider|rotate) + gambar base64 + extra(data terkait tipe)
   
2. Pengguna menyelesaikan operasi captcha (klik/geser/putar), klien mengumpulkan jawaban
   
3. Klien meminta POST /api/captcha/verify
   (Header permintaan: API-Version: v1, Content-Type: application/json)
   Body permintaan: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // Array koordinat
   - type=slider: clicks = 120                   // Offset X
   - type=rotate: clicks = 315                   // Sudut rotasi
    ↓
   Server:
   a. Baca data captcha:key dari penyimpanan (TTL 300 detik)
   b. Validasi jawaban berdasarkan type (click: jarak Euclidean ≤18px / slider: ±4px / rotate: ±5°)
   c. Validasi lolos → tulis `captcha_verified:{key}` = 1 ke Redis (TTL 300 detik)
   d. Validasi gagal → kembalikan 422, hitung +1, setelah 3 kali key dibatalkan
    ↓
   Server mengembalikan: { valid: true/false }

4. Klien meminta POST /api/auth/login
   (Header permintaan: API-Version: v1, Content-Type: application/json)
   Body permintaan: { username, password(terenkripsi), captcha_key }
    ↓
   Server:
   a. Validasi parameter → 422
   b. Periksa apakah captcha_verified:{key} ada → 422
   c. Hapus captcha_verified:{key} (penggunaan sekali)
   d. Dekripsi kata sandi: EncryptionService::decrypt(password) → teks biasa
   e. Validasi kredensial pengguna (password_verify) → 401
   f. Periksa status akun → 403/429
   g. Terbitkan JWT (access + refresh) → 200
   h. Perbarui last_login_at / last_login_ip
    ↓
   Klien menyimpan: access_token, refresh_token, expires_in

5. Permintaan berikutnya membawa JWT
   Header permintaan: Authorization: Bearer <access_token>
    ↓
Middleware AdminAuth:
   a. Ekstrak token Bearer
   b. Periksa blacklist (Redis jwt_blacklist:{md5}) → 401
   c. Dekode JWT, validasi kedaluwarsa → 401
   d. Setel $request->adminId = nilai sub
    ↓
Middleware AdminPermission:
   a. Parse identitas izin untuk rute sumber daya
   b. Kueri peran pengguna → izin peran, lakukan pencocokan
   c. Tanpa izin → 403
    ↓
Controller memproses permintaan
    ↓
Respons + header X-RateLimit-*

6. Perbarui sebelum Access Token kedaluwarsa
   Klien meminta POST /api/auth/refresh
   Body permintaan: { refresh_token: "..." }
    ↓
   Server mendekode refresh_token → terbitkan access + refresh baru
    ↓
   Klien memperbarui token lokal

7. Keluar
   Klien meminta POST /admin/profile/logout
   Header permintaan: Authorization: Bearer <access_token>
    ↓
   Server:
   a. Dekode JWT untuk mendapatkan sisa TTL
   b. Tulis ke blacklist Redis: jwt_blacklist:{md5(token)} = 1, TTL = sisa masa berlaku
   c. Kembalikan sukses
```

### Struktur JWT

- **access_token**: `{ sub: <user_id>, username: "<name>" }`, TTL default 7200 detik (dikontrol oleh konfigurasi JWT `default_expire`)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`, TTL default 1209600 detik (dikontrol oleh konfigurasi JWT `refresh_expire`, yaitu 14 hari)

### Manajemen Keamanan

- Kata sandi disimpan sebagai hash `PASSWORD_BCRYPT`
- Lapisan transport kata sandi menggunakan enkripsi AES-256-CBC-HMAC (klien mengenkripsi → server mendekripsi), kompatibel dengan fallback teks biasa
- Kolom sensitif (phone, email, id_card) menggunakan `erikwang2013/encryptable` untuk enkripsi/dekripsi transparan di lapisan database
- ID di lapisan API menggunakan `erikwang2013/hashids` untuk transmisi terenkripsi, menghindari paparan urutan snowflake ID asli
- SecurityFilter memindai XSS, injeksi SQL, path traversal, injeksi perintah secara global; IP yang sama 5 kali/60 detik masuk blacklist sementara 15 menit
- Operasi sensitif (menghapus pengguna, peran, izin, konfigurasi) memerlukan konfirmasi ulang kata sandi pengguna yang sedang login
- Batas sesi konkuren: maksimal 3 token valid per pengguna; saat perangkat ke-4 login, token terlama dipaksa masuk blacklist
- Penguncian akun: 5 kali gagal login berturut-turut memicu penguncian akun 15 menit; selama terkunci mengembalikan 429

## 15. Deployment dan Operasional

### Docker Compose

Direktori root proyek menyediakan `docker-compose.yml` yang mengorkestrasi 5 layanan (Nginx, aplikasi webman, MySQL, Redis, Elasticsearch). PHP dibangun melalui `Dockerfile` (berbasis `php:8.3-cli`, dengan OPcache diaktifkan).

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` mendefinisikan pipeline integrasi berkelanjutan GitHub Actions:
- Pemeriksaan sintaks `php -l`
- Pengujian unit PHPUnit
- Analisis statis `flutter analyze`

### Cadangan Database

Direktori `database/backup/` menyediakan skrip cadangan dan pemulihan:
- `backup.sh` — cadangan kompresi mysqldump + gzip, otomatis membersihkan berkas cadangan lama lebih dari 30 hari
- `restore.sh` — pemulihan interaktif, menampilkan cadangan yang ada untuk dipilih pengguna

### Konfigurasi Keamanan Nginx

Untuk deployment produksi, lihat `docs/nginx-security.conf` untuk penguatan keamanan reverse proxy.
