# Konsol Admin Terbuka — Dokumen Desain

**语言 / Languages:** [中文](DESIGN.md) · [English](DESIGN.en.md) · [한국어](DESIGN.ko.md) · [Русский](DESIGN.ru.md) · [Deutsch](DESIGN.de.md) · [Français](DESIGN.fr.md) · [Español](DESIGN.es.md) · [Português](DESIGN.pt.md) · [हिन्दी](DESIGN.hi.md) · [العربية](DESIGN.ar.md) · [বাংলা](DESIGN.bn.md) · [Bahasa Indonesia](DESIGN.id.md) · [日本語](DESIGN.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

> Untuk diagram arsitektur Mermaid yang detail, lihat [ARCHITECTURE.md](ARCHITECTURE.md) (dirender otomatis di GitHub/GitLab/VS Code).

## 1. Arsitektur sistem

> **Daftar fitur**: Autentikasi (login/register/refresh/logout + penguncian akun + batas sesi) | Dasbor (cache Redis) | CRUD pengguna + batch + impor | Peran dan izin (RBAC) | Konfigurasi sistem | Audit operasi (8 sumber platform) | File (unggah + ekspor + masking) | Keamanan (pertahanan 18 lapis) | Operasional (health/metrics/docs/Docker/CI)

```
┌──────────────────────────────────────────────────────────────┐
│                        客户端层                               │
│  ┌──────────────────────┐  ┌──────────────────────────────┐  │
│  │  Flutter Web (PC)    │  │  HarmonyOS ArkTS (Mobile)    │  │
│  │  管理后台 (桌面风格)   │  │  客户端 (手机/平板/2in1)      │  │
│  └──────────┬───────────┘  └──────────────┬───────────────┘  │
└─────────────┼──────────────────────────────┼─────────────────┘
              │        HTTPS / JSON          │
              │   Authorization: Bearer JWT  │
┌─────────────┼──────────────────────────────┼─────────────────┐
│             ▼                              ▼                  │
│  ┌──────────────────────────────────────────────────────┐    │
│  │                   API 网关层                          │    │
│  │  AdminAuth(认证) → AdminPermission(授权) → Controller │    │
│  └──────────────────────────┬───────────────────────────┘    │
│                             │                                  │
│  ┌──────────────────────────┼───────────────────────────┐    │
│  │              业务逻辑层 (Controller/Service)           │    │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐ │    │
│  │  │Dashboard │ │  User    │ │  Role    │ │ Export  │ │    │
│  │  │Controller│ │Controller│ │Controller│ │Controller│ │    │
│  │  └────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬────┘ │    │
│  └───────┼────────────┼─────────────┼────────────┼──────┘    │
│          │            │             │            │            │
│  ┌───────┼────────────┼─────────────┼────────────┼──────┐    │
│  │       ▼            ▼             ▼            ▼       │    │
│  │                   Model 层                            │    │
│  │  ┌──────────────────────────────────────────────┐    │    │
│  │  │  Snowflake ID ← encryptable → Encryption     │    │    │
│  │  │  (主键生成)     (DB字段加密)   (API传输加密)    │    │    │
│  │  └──────────────────────────────────────────────┘    │    │
│  └──────────────────────────┬───────────────────────────┘    │
│                             │                                  │
│  ┌──────────────────────────┼───────────────────────────┐    │
│  │              数据存储层                                │    │
│  │  ┌──────────┐  ┌──────────────┐  ┌──────────┐        │    │
│  │  │  MySQL   │  │ Elasticsearch│  │  Redis   │        │    │
│  │  │ (主存储)  │  │ (全文检索)    │  │ (缓存)   │        │    │
│  │  └──────────┘  └──────────────┘  └──────────┘        │    │
│  └──────────────────────────────────────────────────────┘    │
│                       webman v2                               │
└──────────────────────────────────────────────────────────────┘
```

## 2. Arsitektur backend

### 2.1 Desain berlapis

| Lapisan | Direktori | Tanggung jawab |
|------|------|------|
| Rute | `config/route.php` | Pemetaan URL ke kontroler, pengikatan middleware, rute berversi |
| Middleware | `app/middleware/` | Interceptasi serangan (SecurityFilter), pembatasan laju (RateLimit), autentikasi (JWT), otorisasi (RBAC), versi API (ApiVersion) |
| Kontroler | 14: Dashboard/User/Role/Permission/Config/Log/Profile/Export/Import/Upload/Health/Docs (sisi admin) + Captcha/Auth (API v1) | Validasi parameter permintaan, pemanggilan logika bisnis, pemformatan respons |
| Layanan bisnis | `app/service/` | Logika bisnis yang dapat digunakan ulang (dicadangkan) |
| Model data | `app/model/` | Pemetaan ORM, relasi, enkripsi/dekripsi kolom |
| Utilitas umum | `app/common/` | Layanan Hashids, Snowflake, Encryption |

### 2.2 Siklus hidup permintaan

```
客户端请求
  │
  ▼
webman HTTP Server (workerman)
  │
  ▼
Route 匹配
  │
  ▼
中间件链:
  Locale ──────────────► Accept-Language / ?lang= 语言检测
  │
  ▼
SecurityFilter ──────► HTTP方法检查 → 405 (仅允许 GET/POST/PUT/DELETE/OPTIONS/HEAD)
  │                     XSS/SQL注入/路径遍历/命令注入/CSRF 攻击拦截 (403)
  ▼
RateLimit ───────────► Redis 滑动窗口限流
  │ (失败返回 429 + Retry-After 头)
  ▼
ApiVersion ─────────► API-Version 头校验，注入 $request->apiVersion
  │ (失败返回 400)
  ▼
AdminAuth ──────────► JWT 验证，注入 $request->adminId
  │ (失败返回 401)
  ▼
AdminPermission ────► RBAC 权限校验（Redis 60s 缓存）
  │ (失败返回 403)
  ▼
OperationLog ───────► 操作日志记录 (POST/PUT/DELETE)，自动检测来源端
  │
  ▼
Controller::method()
  │
  ├─► 参数验证 (validator)
  ├─► 敏感操作确认 (confirmPassword)
  ├─► decodeId() — hashid → BIGINT
  ├─► Model 操作 (自动 encryptable 加解密)
  ├─► encodeId() — BIGINT → hashid
  └─► Response JSON
```

### 2.3 Siklus hidup ID

```
生成 (Snowflake) → 存储 (MySQL BIGINT) → 传输 (Hashids 编码) → 外部 (hash 字符串)
                                                                    │
                            HashidsService::decode() ←──────────────┘
```

### 2.4 Sistem enkripsi data

```
传输层 (encryption)     — AES-256-CBC，独立密钥
存储层 (encryptable)    — AES-128-ECB，独立密钥，Model $casts 自动处理
展示层 (mask)           — 手机号: 138****1234，邮箱: a***@example.com
```

## 3. Desain basis data

### 3.1 Relasi ER

```
erik_admin_user ──┬── erik_admin_user_role ──┬── erik_admin_role
  (用户)           │    (用户-角色关联)         │     (角色)
                  │                          │
                  │                    erik_admin_role_permission
                  │                     (角色-权限关联)
                  │                          │
                  │                          ▼
                  │                    erik_admin_permission
                  │                      (权限/菜单)
                  │
                  ▼
           erik_operation_log
             (操作日志)

erik_system_config (系统配置) — 独立表
```

### 3.2 Struktur tabel inti

| Nama tabel | Jumlah kolom | Deskripsi |
|------|-------|------|
| `erik_admin_user` | 14 | Pengguna admin, phone/email/id_card disimpan terenkripsi, mendukung soft delete |
| `erik_admin_role` | 7 | Peran, slug unik |
| `erik_admin_permission` | 10 | Pohon izin (parent_id referensi diri), type: 1=menu 2=tombol 3=API |
| `erik_admin_user_role` | 2 | Tabel perantara many-to-many pengguna-peran |
| `erik_admin_role_permission` | 2 | Tabel perantara many-to-many peran-izin |
| `erik_system_config` | 8 | Konfigurasi pasangan kunci-nilai, group+key unik gabungan |
| `erik_operation_log` | 9 | Log audit operasi (termasuk kolom source asal) |

### 3.3 Standar kunci utama

- Tipe: `BIGINT UNSIGNED NOT NULL`
- Karakteristik: **non-auto-increment**, dihasilkan algoritma Snowflake di lapisan aplikasi
- Keunggulan: unik global, ramah terdistribusi, kenaikan tren baik untuk indeks, tidak membocorkan volume bisnis
- Konfigurasi: datacenter_id(0-31) + worker_id(0-31), mendukung 1024 node konkuren

## 4. Desain API

### 4.1 Standar URL

```
公开接口:  /api/captcha/{generate|verify}
           /api/auth/{login|register|refresh}

管理端:   /admin/{resource}[/{hashid}]
          /admin/export/{excel|pdf}

资源路由:
  GET    /admin/user          → 列表
  POST   /admin/user          → 创建
  GET    /admin/user/{hashid} → 详情
  PUT    /admin/user/{hashid} → 更新
  DELETE /admin/user/{hashid} → 删除（需密码确认）

系统配置:  /admin/config[/{hashid}]
操作日志:  /admin/log
个人中心:  /admin/profile[/password|/logout]
导入:     /admin/import/users
上传:     /admin/upload
批量:     /admin/user/batch/{destroy|status}
文档:     /api/docs     (OpenAPI 3.0)
健康:     /health
```

### 4.2 Strategi versi API

Versi API dikontrol melalui header permintaan, **tidak tercermin di path URL**:

```http
API-Version: v1
```

| Mekanisme | Deskripsi |
|------|------|
| Versi default | `v1` saat header `API-Version` tidak dibawa |
| Validasi | Middleware `ApiVersion` memvalidasi; versi yang tidak didukung mengembalikan 400 |
| Rute | Fungsi bantu `v()` menyelesaikan kelas kontroler secara dinamis sesuai versi |
| Direktori | Kontroler diorganisir per versi: `app/api/{version}/controller/` |

Contoh perluasan — menambah API v2:
1. Buat `app/api/v2/controller/AuthController.php`
2. Tambahkan `'v2'` ke konstanta `SUPPORTED` middleware `ApiVersion`
3. Definisi rute tidak perlu diubah

```bash
# 使用 v1
curl -H "API-Version: v1" /api/auth/login

# 使用 v2
curl -H "API-Version: v2" /api/auth/login

# 不传，默认 v1
curl /api/auth/login
```

### 4.3 Strategi pembatasan laju

Berbasis algoritma jendela geser Redis Sorted Set, dieksekusi dengan skrip Lua atomik:

| Antarmuka | Batas |
|------|------|
| Default | 60 kali/menit/IP/rute |
| POST /api/auth/login | 10 kali/menit |
| POST /api/auth/register | 5 kali/menit |

Saat melebihi batas mengembalikan 429, header respons menyertakan X-RateLimit-Limit / Remaining / Reset / Retry-After.

### 4.4 Respons terpadu

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

| code | Arti | Skenario pemicu |
|------|------|---------|
| 0 | Berhasil | Respons normal |
| 400 | Kesalahan parameter | Format permintaan tidak benar |
| 401 | Belum terautentikasi | Token hilang/kedaluwarsa/tidak valid |
| 403 | Tanpa izin | Peran pengguna tidak memiliki izin yang diperlukan |
| 404 | Tidak ada | Sumber daya tidak ditemukan |
| 422 | Gagal validasi | Parameter formulir tidak sesuai aturan / gagal konfirmasi kata sandi |
| 500 | Kesalahan server | Pengecualian tak terduga |

### 4.5 Alur autentikasi (dengan captcha klik)

```
客户端                               服务端
  │                                    │
  │  ① POST /api/captcha/generate     │ captcha_create('click')
  │◄── {key, image(base64), targets}  │
  │                                    │
  │  ② 用户点击图中文字位置              │
  │                                    │
  │  ③ POST /api/auth/login           │
  │     {username, password,          │
  │      captcha_key, clicks}         │
  │────────────────────────────────►  │
  │                                    │ ① captcha_verify()
  │                                    │ ② password_verify()
  │                                    │ ③ jwt()->create()
  │◄── {access_token, refresh_token}  │
  │                                    │
  │  ④ GET /admin/dashboard           │
  │     Authorization: Bearer xxx     │
  │────────────────────────────────►  │ AdminAuth → AdminPermission
  │◄── 200 {dashboard data}           │
```

### 4.6 Model izin (RBAC)

```
  用户 ──┬── 角色 ──┬── 权限
  User     Role      Permission
                 │
                 ├── type=1: 菜单 (控制侧边栏可见)
                 ├── type=2: 按钮 (控制页面内操作)
                 └── type=3: API  (控制接口访问)

  权限标识格式: {method}.{path}
  例: get.admin/user  post.admin/user  delete.admin/user
  超级管理员标识: * (跳过所有权限检查)
```

### 4.7 Konfirmasi sekunder untuk operasi sensitif

Operasi sensitif seperti menghapus pengguna, peran, izin, perlu mengirim kata sandi pengguna saat ini di body permintaan untuk memverifikasi ulang identitas:

```
客户端                           服务端
  │                                │
  │  DELETE /admin/user/{hashid}  │
  │  { password: "******" }       │
  │────────────────────────────►  │
  │                                │ confirmPassword(adminId, password)
  │                                │ → 密码错误返回 422
  │                                │ → 密码正确继续执行
  │◄── 200 { code: 0 }           │
```

Frontend menampilkan dialog konfirmasi sebelum memicu operasi hapus, mengumpulkan kata sandi pengguna lalu mengirim permintaan.

## 5. Desain frontend

### 5.1 Panel admin Flutter Web

```
┌────────────────────────────────────────────────┐
│  Header (56px)                                 │
│  ☰ 菜单按钮           🔔 消息  👤 管理员  ▼    │
├──────────┬─────────────────────────────────────┤
│ Sidebar  │  Content Area                       │
│ (64/240) │                                     │
│          │  ┌──────────────┐ ┌──────────┐     │
│ 📊 仪表盘│  │ 统计卡片×4    │ │ 趋势图   │     │
│ 👥 用户  │  └──────────────┘ └──────────┘     │
│ 🔒 角色  │  ┌──────┐ ┌────────────────┐       │
│ ⚙ 配置  │  │饼图  │ │ 最近操作日志    │       │
│ 📋 日志  │  └──────┘ └────────────────┘       │
└──────────┴─────────────────────────────────────┘
```

Fitur: sidebar dapat dilipat, tema ganda Material 3, tabel data kepadatan tinggi, dialog popup, interaksi hover mouse

### 5.2 Sisi seluler HarmonyOS

Rute halaman:

| Halaman | Rute | Deskripsi |
|------|------|------|
| LoginPage | `pages/LoginPage` | Nama pengguna + kata sandi + captcha klik untuk login |
| DashboardPage | `pages/DashboardPage` | Kartu statistik + operasi terbaru |
| UserListPage | `pages/UserListPage` | Daftar pengguna, pencarian + tarik ke bawah refresh + geser ke atas muat |
| UserDetailPage | `pages/UserDetailPage` | Tambah/edit/lihat/hapus (konfirmasi AlertDialog) |
| ProfilePage | `pages/ProfilePage` | Pusat pribadi, logout (konfirmasi AlertDialog) |

Alur data: Page ← DataService ← ApiService (JWT Bearer) ← HTTP ← webman

## 6. Desain keamanan

### 6.1 Pertahanan berlapis

| Lapisan | Tindakan |
|------|------|
| Pembatasan metode | SecurityFilter daftar putih metode HTTP, hanya GET/POST/PUT/DELETE/OPTIONS/HEAD, metode non-standar mengembalikan 405 |
| Interceptasi serangan | Middleware SecurityFilter, deteksi dan interceptasi XSS/injeksi SQL/path traversal/injeksi perintah/CSRF |
| Verifikasi manusia-mesin | Captcha klik (Click Captcha), verifikasi wajib di login/registrasi |
| Penguncian akun | 5 kali login gagal berurutan mengunci akun 15 menit; selama penguncian mengembalikan 429 |
| Batas sesi | Maksimal 3 token konkuren per pengguna; saat melebihi, token tertua otomatis masuk daftar hitam |
| Pembatasan laju | Middleware RateLimit, jendela geser Redis, atomik Lua |
| CSP | Header Content-Security-Policy membatasi sumber daya, mencegah XSS dan injeksi data |
| Konfirmasi operasi | Operasi sensitif seperti hapus memerlukan konfirmasi sekunder kata sandi pengguna saat ini |
| Transmisi | HTTPS + JWT Bearer Token |
| ID antarmuka | Hashids mengenkripsi, tidak dapat menebak ID asli dari luar |
| Body permintaan | Enkripsi AES-256-CBC kolom sensitif |
| Basis data | Kunci utama BIGINT (tidak membocorkan auto-increment) |
| Basis data | Enkripsi AES-128-ECB kolom sensitif saat penyimpanan |
| Autentikasi | JWT HS256, kedaluwarsa 2 jam + refresh token |
| Otorisasi | RBAC, kontrol izin granularitas method.path |
| Audit | OperationLog mencatat semua operasi (termasuk deteksi otomatis kolom source asal) |

### 6.2 Manajemen kunci

```
JWT_SECRET          → 环境变量注入，64位随机字符串
HASHIDS_SALT        → 唯一盐值，泄漏后需全局更换
ENCRYPTION_KEY      → API 传输加密密钥，32字节
ENCRYPTABLE_KEY     → DB 存储加密密钥，与传输密钥独立
SCOUT_HOSTS         → ES 地址，内网部署
```

### 6.3 Perlindungan data sensitif

| Skenario | Kolom | Tindakan |
|------|------|------|
| Tampilan daftar | phone | Masking: 138****1234 |
| Tampilan daftar | email | Masking: a***@example.com |
| Lihat detail | phone/email | Memerlukan antarmuka dekripsi |
| Ekspor Excel | phone/email | Ekspor setelah masking |
| Ekspor PDF | semua kolom | Masking + watermark hak cipta yang tidak dapat dihapus |
| Penyimpanan | phone/email/id_card | Dienkripsi encryptable menjadi ciphertext |

## 7. Desain ekspor

### 7.1 Ekspor Excel

```
请求: POST /admin/export/excel { table, columns, conditions, title }
  → fetchExportData() 查询数据 (limit 10000)
  → 脱敏敏感字段
  → PhpSpreadsheet 构建（蓝底白字表头 + 冻结首行 + 自动筛选）
  → 写入 runtime/tmp/ → download 响应
```

### 7.2 Ekspor PDF

```
请求: POST /admin/export/pdf { type: table|dashboard, title, data }
  → buildPdfHtml() HTML + 内联CSS + 页头版权 + 页脚不可移除版权
  → Dompdf 渲染 A4 横向
  → 写入 runtime/tmp/ → download 响应
```

## 8. Arsitektur deployment

### 8.1 Topologi yang disarankan

```
Nginx (:443 HTTPS) → webman worker × N (:8787) → MySQL + ES + Redis
                    静态文件: Flutter Web build/
```

### 8.2 Docker Compose (lingkungan produksi yang disarankan)

`docker-compose.yml` di direktori root proyek mengorkestrasi semua layanan dari topologi di atas:

| Layanan | Image/build | Port | Deskripsi |
|------|----------|------|------|
| `nginx` | nginx:alpine | 80, 443 | Proxy terbalik + file statis + Gzip |
| `app` | Dibangun dengan `Dockerfile` lokal | 8787 | PHP 8.3 + OPcache + webman |
| `mysql` | mysql:8.0 | 3306 | Basis data utama, persistensi data dengan volume |
| `redis` | redis:7-alpine | 6379 | Cache / pembatasan laju / captcha |
| `elasticsearch` | elasticsearch:8.x | 9200 | Pencarian teks lengkap |

Sebelum memulai, ganti kunci `JWT_SECRET`, `HASHIDS_SALT`, `ENCRYPTION_KEY` dll. di `docker-compose.yml` dengan string acak.

```bash
cp .env.docker .env
docker-compose up -d
```

### 8.3 CI/CD

Integrasi berkelanjutan GitHub Actions didefinisikan di `.github/workflows/ci.yml`:
- Pemeriksaan sintaks PHP (`php -l`)
- Pengujian unit PHPUnit
- Analisis statis Flutter (`flutter analyze`)

### 8.4 Cadangan basis data

`database/backup/backup.sh` — cadangan mysqldump + gzip, otomatis membersihkan cadangan lama lebih dari 30 hari.
`database/backup/restore.sh` — pemilihan interaktif dan pemulihan cadangan.

### 8.5 Pemantauan

Endpoint `GET /metrics` (`MetricsController`) mengekspos 5 metrik gauge dalam format teks Prometheus: total permintaan HTTP, jumlah pengguna aktif, status koneksi basis data/Redis, penggunaan memori.

### 8.6 Kebutuhan lingkungan

| Komponen | Versi minimum | Konfigurasi yang disarankan |
|------|---------|---------|
| PHP | 8.3+ | 8.3+ OPcache diaktifkan |
| MySQL | 8.0+ | 8.0+ replikasi master-slave |
| Elasticsearch | 7.x | 8.x klaster 3 node |
| Redis | 6.x | 7.x mode sentinel |
| Nginx | 1.20+ | Proxy terbalik + gzip + SSL |
| Flutter SDK | 3.41+ | Versi stabil terbaru |
| HarmonyOS | API 12 | DevEco Studio 5.x |
