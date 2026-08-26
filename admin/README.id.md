# Admin Terbuka (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Sistem admin full-stack berbasis webman v2 + Flutter.

> [English version](README_EN.md) | [Diagram Arsitektur](docs/ARCHITECTURE.md) | [Dokumen Desain](docs/DESIGN.md) | [Arsitektur Keamanan](docs/SECURITY.md) | [Referensi API](docs/API.md)

## Daftar Fitur

| Domain | Fitur | Keterangan |
|--------|------|------|
| 🔐 Autentikasi | Login/Refresh Token/Logout | Klik captcha + JWT + blacklist |
| | Kunci akun | 5 kali gagal terkunci 15 menit |
| | Batas sesi bersamaan | Maksimal 3 token aktif per pengguna |
| 📊 Dasbor | Statistik real-time/grafik tren/grafik distribusi/aktivitas terbaru | Cache Redis 5 menit |
| 👥 Manajemen pengguna | CRUD + hapus massal/aktif-nonaktif | Soft delete + konfirmasi ulang kata sandi |
| | Impor massal Excel | Validasi per baris + laporan kesalahan |
| 🔒 Peran & izin | CRUD peran + pohon izin | Otorisasi granular RBAC method.path |
| ⚙ Konfigurasi sistem | CRUD key-value | Manajemen grup |
| 📋 Audit operasi | Kueri log + deteksi asal perangkat | Otomatis mengenali 8 platform |
| 📁 Manajemen file | Unggah/Ekspor Excel/Ekspor PDF | Data sensitif otomatis disembunyikan |
| 🛡 Pertahanan keamanan | 18 lapisan pertahanan berlapis | XSS/Injeksi SQL/Traversal path/Injeksi perintah/CSRF/Pembatasan rate/CSP... |
| 🏥 Operasi & pemeliharaan | Health check/metrics/dokumen API/security.txt | Prometheus + OpenAPI 3.0 + dokumen interaktif hg/apidoc |
| 🌐 Internasionalisasi | Beralih Tionghoa/Inggris | Header Accept-Language / parameter ?lang= |

## Tumpukan Teknologi

| Lapisan | Teknologi | Keterangan |
|---|------|------|
| Framework backend | webman v2 (workerman) | Framework PHP proses menetap berperforma super tinggi |
| Versi PHP | 8.3+ | |
| Database | MySQL 8.0+ | Prefiks tabel `erik_`, kunci utama BIGINT non-auto-increment |
| Mesin pencari | Elasticsearch | Sinkronisasi & pencarian melalui `webman-scout` |
| Frontend admin | Flutter 3.x | Web bergaya admin PC (`apps/flutter/`) |
| Seluler | HarmonyOS ArkTS | Klien asli HarmonyOS (`apps/harmonyos/`), mendukung ponsel/tablet/2-in-1 |

## Dependensi Inti

| Paket | Kegunaan |
|---|------|
| `erikwang2013/snowflake-php` | Algoritma Snowflake menghasilkan kunci utama BIGINT unik global |
| `erikwang2013/hashids` | Enkripsi/dekripsi ID di lapisan API, menyembunyikan ID database asli |
| `erikwang2013/jwt-webman` | Penerbitan & verifikasi token autentikasi JWT |
| `erikwang2013/encryption` | Enkripsi/dekripsi data sensitif di lapisan transmisi antarmuka |
| `erikwang2013/encryptable` | Enkripsi/dekripsi otomatis bidang sensitif di lapisan penyimpanan database |
| `erikwang2013/webman-scout` | Sinkronisasi data Elasticsearch & pencarian full-text |
| `erikwang2013/season` | Data bendera negara |
| `erikwang2013/poster-php` | Pembuatan & verifikasi captcha klik + pembuatan poster |
| `phpoffice/phpspreadsheet` | Ekspor Excel |
| `barryvdh/laravel-dompdf` | Ekspor PDF (berbasis Dompdf) |

## Struktur Proyek

```
open-admin/
├── app/
│   ├── admin/controller/       # 管理端控制器
│   │   ├── DashboardController.php # 仪表盘（Redis缓存）
│   │   ├── UserController.php      # 用户 CRUD + 批量操作
│   │   ├── RoleController.php      # 角色 CRUD
│   │   ├── PermissionController.php# 权限 CRUD
│   │   ├── ConfigController.php    # 系统配置 CRUD
│   │   ├── LogController.php       # 操作日志查询
│   │   ├── ProfileController.php   # 个人中心 + 登出
│   │   ├── ExportController.php    # Excel/PDF 导出
│   │   ├── ImportController.php    # Excel 导入用户
│   │   ├── UploadController.php    # 文件上传
│   │   ├── HealthController.php    # 健康检查
│   │   ├── DocsController.php      # OpenAPI 文档
│   │   └── BaseController.php      # 基础控制器
│   ├── api/
│   │   └── v1/controller/          # API v1 控制器（版本由请求头 API-Version 控制）
│   │       ├── CaptchaController.php # 点击验证码
│   │       └── AuthController.php    # 登录/刷新令牌
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php  # ID 编解码
│   │   ├── SnowflakeService.php# Snowflake ID 生成
│   │   └── EncryptionService.php # 数据加解密 + 脱敏
│   ├── middleware/             # 中间件
│   │   ├── Cors.php            # 跨域
│   │   ├── SecurityFilter.php  # 攻击检测拦截（HTTP方法限制/XSS/SQL注入/路径遍历/命令注入/CSRF）
│   │   ├── RateLimit.php       # Redis 限流（滑动窗口 + 响应头）
│   │   ├── ApiVersion.php      # API 版本校验
│   │   ├── AdminAuth.php       # JWT 认证 + 黑名单
│   │   ├── AdminPermission.php # RBAC 权限校验
│   │   └── OperationLog.php    # 操作日志自动记录（含来源端检测）
│   └── model/                  # 数据模型
├── apps/
│   ├── flutter/                # Flutter Web 管理后台（PC 风格）
│   │   └── lib/app/
│   │       ├── pages/          # 5 个完整页面（仪表盘/用户/角色/配置/日志/个人中心）
│   │       ├── services/       # ApiService（JWT 拦截器）+ AuthService（Token 持久化）
│   │       └── layouts/        # 响应式管理后台布局（侧边栏+顶栏+内容区）
│   └── harmonyos/              # HarmonyOS 原生客户端（Token 无感刷新）
├── config/                     # 配置文件（含中文注释）
│   ├── route.php               # 路由 + API 版本策略
│   ├── middleware.php           # 全局中间件注册
│   └── ...                     # 各组件配置
├── database/migrations/        # SQL 迁移文件（含权限种子数据）
├── public/                     # 公共入口
├── runtime/                    # 运行时文件
└── vendor/                     # Composer 依赖
```

## Persyaratan Lingkungan

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (hanya untuk pengembangan frontend)
- Elasticsearch >= 7.x (opsional, diperlukan untuk fungsi pencarian)

## Memulai Cepat

### 1. Instal Dependensi

```bash
composer install
```

### 2. Konfigurasi Variabel Lingkungan

Salin dan ubah variabel lingkungan (opsional; jika tidak dikonfigurasi, gunakan nilai default di `config/*.php`):

```bash
cp .env.example .env
```

Item konfigurasi utama:

| Variabel lingkungan | Keterangan | Nilai default |
|---------|------|--------|
| `JWT_SECRET` | Kunci penandatanganan JWT | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Salt Hashids | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | Kunci enkripsi API | Nilai default 32 byte |
| `SNOWFLAKE_DATACENTER_ID` | ID pusat data (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | ID node pekerja (0-31) | `1` |
| `SCOUT_HOSTS` | Alamat ES | `http://localhost:9200` |

**Di lingkungan produksi, wajib mengubah semua kunci menjadi string acak.**

### 3. Instalasi Sekali Klik

Setelah layanan dimulai, buka wizard instalasi di browser untuk menyelesaikan inisialisasi database dan pembuatan admin:

```bash
php start.php start
```

Secara default mendengarkan di `http://0.0.0.0:8787` (port dapat diubah di `config/server.php`).

Buka **`http://localhost:8787/install`** di browser, isi sesuai wizard:

| Langkah | Isi |
|------|------|
| ① Konfigurasi database | Alamat host, port, nama database, nama pengguna, kata sandi |
| ② Pengaturan admin | Nama pengguna & kata sandi admin (default admin / admin888) |

Klik "Mulai Instalasi" untuk otomatis membuat tabel, menanam data izin, membuat akun admin, dan menulis konfigurasi database ke `.env`.

> Setelah instalasi selesai, file kunci `runtime/install.lock` dibuat. Hapus file ini untuk menginstal ulang.

### 4. Login

Akses `http://localhost:8787` dan login dengan akun admin yang diatur saat instalasi.

### 5. Menjalankan Frontend (Opsional)

**Admin Flutter (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**Klien HarmonyOS (ponsel):**

Buka direktori `apps/harmonyos/` dengan DevEco Studio, sambungkan perangkat asli atau emulator untuk menjalankannya.

### 6. Deployment Sekali Klik Docker Compose (Direkomendasikan untuk Produksi)

Proyek menyediakan orkestrasi Docker lengkap dengan 5 layanan: Nginx, PHP (webman app), MySQL, Redis, Elasticsearch.

```bash
# 1. 配置 Docker 环境变量
cp .env.docker .env

# 2. 启动所有服务
docker-compose up -d

# 3. 浏览器访问安装向导完成初始化
# http://localhost:8787/install  (填入数据库和管理员信息)
# 或手动执行 SQL 迁移（进入 app 容器）:
# docker-compose exec app mysql -h mysql -u root -p < database/migrations/open_admin.sql

# 4. 访问
# http://localhost:8787  (webman)
# http://localhost:8080  (Nginx 反向代理)
```

- `Dockerfile`: PHP 8.3 + OPcache + Composer, berbasis `php:8.3-cli`
- `docker-compose.yml`: orkestrasi 5 layanan, isolasi jaringan, persistensi volume data
- `.env.docker`: variabel lingkungan khusus Docker


## Konvensi Database

- **Prefiks tabel**: `erik_`
- **Kunci utama**: Semua kunci utama tabel adalah `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT dilarang**
- **Pembuatan ID**: ID kunci utama dibuat oleh `SnowflakeService::generate()` di lapisan aplikasi, unik secara terdistribusi
- **Bidang wajib**: Setiap tabel harus berisi `id`, `created_at`, `updated_at`
- **Soft delete**: tabel yang membutuhkan soft delete menambahkan `deleted_at DATETIME DEFAULT NULL`
- **Bidang sensitif**: nomor ponsel, email, nomor KTP, dll. dienkripsi/dekripsi otomatis oleh plugin `encryptable`; bidang database menggunakan `VARCHAR(500)` untuk menyimpan ciphertext

## Referensi API

Spesifikasi API lengkap (format respons terpadu, kode kesalahan bisnis, penanganan ID, versi API, pembatasan rate, arsitektur middleware, alur autentikasi & captcha) serta daftar semua antarmuka, lihat **[Dokumen Referensi API](docs/API.md)**.

## Catatan Frontend

### Admin Flutter (gaya PC)

- **Tata letak**: sidebar (dapat dilipat 64px/240px) + bilah atas + area konten, tiga breakpoint responsif (ponsel/tablet/desktop)
- **Halaman**: login, dasbor, manajemen pengguna, peran & izin, konfigurasi sistem, log operasi, profil
- **Manajemen status**: GetX (`ApiService` singleton + persistensi Token `AuthService`)
- **Dasbor**: kartu statistik, grafik garis tren (fl_chart), diagram lingkaran, log operasi terbaru
- **Ekspor**: ekspor Excel/PDF; PDF berisi informasi hak cipta yang tidak dapat dihapus
- **Operasi massal**: hapus massal multi-pilih, aktif/nonaktif massal
- **Tema**: tema ganda Material 3 terang/gelap

### Seluler HarmonyOS

- **Halaman**: login, dasbor, daftar/detail pengguna, profil
- **Autentikasi**: JWT Bearer + refresh Token tanpa rasa pada 401, otomatis dialihkan ke halaman login jika refresh gagal
- **Penyimpanan**: Token dikelola melalui AppStorage

## Aturan Pengembangan

- Referensi fungsi/kelas global tanpa garis miring terbalik di depan, gunakan impor `use` secara seragam
- Semua file PHP harus menyertakan pernyataan hak cipta di bagian atas
- Semua file konfigurasi harus menyertakan komentar penjelasan dalam bahasa Tionghoa
- Kunci utama database harus dibuat oleh snowflake di lapisan aplikasi; auto-increment dilarang
- Semua ID di parameter dan respons lapisan API harus dienkripsi/dekripsi melalui hashids
- Middleware AdminPermission menggunakan Redis untuk cache izin pengguna (TTL=60s), menghilangkan bottleneck kueri N+1

## Deployment

### Docker Compose (Direkomendasikan)

Direktori akar proyek menyediakan `docker-compose.yml` yang mengorkestrasi 5 layanan:

| Layanan | Image | Port |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | dibangun dari `Dockerfile` lokal | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

Image PHP dibangun melalui `Dockerfile`, image dasar `php:8.3-cli`, OPcache diaktifkan.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

Pipeline integrasi berkelanjutan GitHub Actions: `.github/workflows/ci.yml`

- Pemeriksaan sintaks PHP (`php -l`)
- Pengujian unit PHPUnit
- Analisis statis Flutter (`flutter analyze`)

### Backup Database

Direktori `database/backup/`:

- `backup.sh` — backup mysqldump + gzip, otomatis membersihkan backup lama lebih dari 30 hari
- `restore.sh` — pemulihan interaktif, mencantumkan backup yang tersedia untuk dipilih

### Konfigurasi Keamanan Nginx

Untuk deployment produksi, lihat `docs/nginx-security.conf` untuk memperkuat keamanan reverse proxy.

## Open source tidak mudah — dukungan dipersilakan

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
