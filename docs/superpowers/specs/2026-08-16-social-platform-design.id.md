# Desain Keseluruhan Platform Sosial (Social Platform Design)

**语言 / Languages:** [中文](2026-08-16-social-platform-design.md) · [English](2026-08-16-social-platform-design.en.md) · [한국어](2026-08-16-social-platform-design.ko.md) · [Русский](2026-08-16-social-platform-design.ru.md) · [Deutsch](2026-08-16-social-platform-design.de.md) · [Français](2026-08-16-social-platform-design.fr.md) · [Español](2026-08-16-social-platform-design.es.md) · [Português](2026-08-16-social-platform-design.pt.md) · [हिन्दी](2026-08-16-social-platform-design.hi.md) · [العربية](2026-08-16-social-platform-design.ar.md) · [বাংলা](2026-08-16-social-platform-design.bn.md) · [Bahasa Indonesia](2026-08-16-social-platform-design.id.md) · [日本語](2026-08-16-social-platform-design.ja.md)

- Tanggal: 2026-08-16
- Status: Terkonfirmasi, menunggu implementasi
- Lingkup: komunitas konten pendek teks+gambar + pesan instan (IM) + live streaming/voice + ekonomi virtual, multibahasa, global multi-region

## 1. Tujuan dan Lingkup

Membangun platform sosial konten pendek teks+gambar + IM, dengan live streaming (video + danmaku + co-hosting), voice (pesan suara/panggilan 1v1/voice chat room), dan ekonomi virtual hadiah. Mendukung UI multibahasa, terjemahan konten, kepatuhan multi-region, dan deployment global multi-region. Pengembangan native paralel di tiga platform: iOS / Android / HarmonyOS.

## 2. Ringkasan Sistem

```
                    ┌─────────────────────────────────────────────┐
                    │   iOS (SwiftUI) │ Android (Kotlin+Compose)  │
                    │            HarmonyOS (ArkTS)                │
                    └───────┬─────────────────────────┬───────────┘
                            │  HTTPS / WSS（多区域就近接入）
                  ┌─────────▼──────────┐   ┌──────────────────────┐
                  │  CDN + 区域接入层   │   │ 厂商推送 APNs/FCM/华为 │
                  └─────────┬──────────┘   └──────────────────────┘
              ┌─────────────▼─────────────┐
              │   service (webman v2)     │──gRPC──▶ infrastructure
              │  业务单体：认证/资料/动态/ │          (bee-rust)
              │  点赞/评论/关注/IM 网关/   │  gRPC      搜索/推荐/图/
              │  翻译调度/审核/直播/语音/  │  ▲        时序/热数据
              │  虚拟经济                 │  │ gRPC
              └─────────────┬─────────────┘  │
                  ┌─────────▼─────────┐      │
                  │ MySQL + Redis     │      │
                  │ S3 对象存储       │      │
                  └───────────────────┘      │
              ┌──────────────────────────────┴──┐
              │   admin (open-admin 改造, webman) │
              │  审核台/举报/GDPR/看板/词条/礼物库/ │
              │  直播配置/提现审核                 │
              └──────────────────────────────────┘

  media/（自建媒体层，信令走 service WS 网关）
  ├── sfu/    mediasoup：1v1 通话、语聊房
  ├── srs/    SRS：自建直播（RTMP → FFmpeg 转码 → FLV/HLS）
  └── coturn/ TURN 中继

  外部：第三方直播云（推流/转码/CDN/实时审核）、第三方 RTC（连麦）、
        第三方审核 API、商店 IAP（App Store / Google Play / 华为）
```

## 3. Tanggung Jawab Subsistem

### 3.1 contracts (kontrak gRPC, direktori tingkat atas baru)

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- Pipeline pembuatan: CI menggunakan buf untuk menghasilkan tiga jenis stub dan mengirimkannya ke sub-repo masing-masing (build tidak bergantung pada jaringan)
  - service/, admin/ → stub PHP (grpc/grpc + google/protobuf)
  - infrastructure/ → stub Rust (tonic)
- Aturan versi: hanya menambah field, tidak mengubah/menghapus; nama paket membawa versi major (`social.user.v1`)

### 3.2 service (webman v2) — monolit bisnis sisi pengguna

- **Domain API**: auth (JWT token ganda + daftar hitam), profile, posts, likes, comments, follows, IM (percakapan/pesan/gerbang WS), notifications, penjadwalan terjemahan, sinyal live room/danmaku/co-hosting, sinyal panggilan suara/voice chat room, ekonomi virtual (dompet/hadiah/verifikasi IAP/bagi hasil), ekspor/hapus GDPR
- **Sistem error multibahasa**: error mengembalikan `{code, lang_key, params}`, teks dirender oleh klien sesuai locale
- **Antrian** (redis-queue): pemicu moderasi, penjadwalan terjemahan, pengiriman push, statistik asinkron, siaran efek hadiah
- **Terjadwal** (webman-crontab): prapemanasan terjemahan, pembersihan token/pesan kedaluwarsa, arsip audit, penyelesaian bagi hasil
- **ID**: `erikwang2013/snowflake-php` (sama dengan admin)
- **Kontrak**: ekspor otomatis OpenAPI 3.0 → pembuatan klien bertipe di tiga platform

### 3.3 infrastructure (bee-rust) — lapisan komputasi throughput tinggi

Tidak menyimpan data utama bisnis (MySQL adalah satu-satunya sumber kebenaran), menangani kemampuan komputasi berat/kueri berat:

- `bee_search`: pencarian teks lengkap post/pengguna (segmentasi kata Mandarin, indeks multibahasa)
- `bee_graph`: grafik sosial → feed rekomendasi
- `bee_tsdb`: statistik deret waktu seperti DAU, posting, interaksi, penonton live, durasi panggilan suara
- `bee_cache/bee_kv`: cache linimasa, penghitung (jumlah like, jumlah tayangan, jumlah online)
- Di-deploy per region, baca banyak tulis sedikit, data direplikasi dari pusat

### 3.4 admin (modifikasi open-admin)

**Reuse**: infrastruktur JWT/RBAC/audit/manajemen file/health check/i18n zh-en

**Baru**:
- Workbench moderasi konten: moderasi perbandingan bilingual post/komentar/gambar, template alasan penolakan multibahasa, sanksi pengguna
- Antrian penanganan laporan
- Workbench permintaan GDPR (tiket ekspor/hapus)
- Dashboard data terhubung ke bee_tsdb
- Manajemen istilah i18n (CRUD istilah bersama empat klien)
- Manajemen katalog hadiah (SKU, harga, efek, nama multibahasa)
- Konfigurasi provider live (strategi pemilihan rute, urutan peralihan)
- Moderasi permohonan penarikan dana

### 3.5 media (lapisan media mandiri, Node.js + layanan sistem)

- `sfu/`: mediasoup, menangani panggilan 1v1 dan media voice chat room; hanya meneruskan media tanpa logika bisnis
- `srs/`: live streaming mandiri SRS, push RTMP → transkode FFmpeg → distribusi HTTP-FLV/HLS
- `coturn/`: relay TURN, fallback tembus NAT
- Semua sinyal diteruskan melalui gerbang WS service

### 3.6 apps — tiga platform native paralel

- Berbagi kontrak OpenAPI, setiap platform menghasilkan klien bertipe secara independen
- Modul infrastruktur terpadu: lapisan jaringan (retry/refresh autentikasi), klien WS (sinyal IM/danmaku/panggilan), i18n (sumber daya lokal + istilah remote inkremental), pendaftaran push, tema
- Catatan HarmonyOS: adaptasi Huawei Push Kit dan model konkurensi ArkTS

## 4. Komunikasi Backend (gRPC)

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| Pemanggil → yang dipanggil | Muatan |
|------|------|
| service → infra | Pencarian teks lengkap, feed rekomendasi, cache panas linimasa, baca/tulis hitungan, penulisan statistik deret waktu |
| admin → infra | Kueri statistik dashboard, pencarian admin |
| admin → service | Sanksi pengguna, penghapusan konten, pengiriman hasil moderasi |
| service → admin | Peristiwa laporan, antrian tugas moderasi (asinkron) |

Batas: tiga aplikasi dan frontend admin (Flutter) menggunakan HTTPS REST + WS, tidak menyentuh gRPC secara langsung.

**Prasyarat operasional**: gRPC di sisi PHP bergantung pada ekstensi resmi `grpc` (ekstensi C) + paket composer `grpc/grpc`; mode server mengacu pada solusi walkor/grpc resmi workerman; dokumentasi deployment harus menjelaskannya.

## 5. Arsitektur Multibahasa (Tiga Lapisan)

| Lapisan | Solusi |
|----|------|
| **Lapisan UI** | Sumber daya locale per platform (mulai zh/en, sistem mendukung bahasa apa pun); server hanya mengirim kode error + key template |
| **Lapisan konten** | Simpan teks asli saat publikasi + deteksi bahasa otomatis ditulis ke field `lang`; saat dibaca, jika reader.lang ≠ author.lang → layanan terjemahan (abstraksi provider LLM/MT), hasil di-cache di Redis (bee_cache, TTL), dengan penanda `is_translated` untuk kembali ke teks asli; konten populer di-prepare terjemahannya secara terjadwal |
| **Lapisan kepatuhan** | Aturan moderasi berlaku per region (aturan GDPR UE vs region lain); antarmuka laporan/moderasi bilingual |

Danmaku adalah teks pendek real-time; kontennya tidak diterjemahkan, hanya UI i18n + filter kata sensitif multibahasa.

## 6. Arsitektur IM

- **Gerbang**: gerbang WS webman, multi-instance + penerusan lintas node Redis pub/sub, deduplikasi idempoten `client_msg_id`
- **Data**: conversations / conversation_members / messages / message_reads; chat pribadi + grup (batas grup 500)
- **Pengiriman**: online → push langsung WS; offline → push APNs/FCM/Huawei
- **Kemampuan**: tanda terima baca, sedang mengetik, penarikan berbatas waktu, pesan gambar/suara (upload S3 + transkode)
- Berbagi sistem pengguna dan sistem notifikasi dengan feed post

## 7. Arsitektur Live Streaming (video + danmaku + co-hosting, sistem dua jalur)

### 7.1 Abstraksi Provider (di dalam service)

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| Mekanisme | Desain |
|------|------|
| Strategi pemilihan rute | Provider default per region saat live room dibuat (admin dapat konfigurasi override); region tanpa cakupan pihak ketiga atau sensitif biaya → mandiri |
| Toleransi kegagalan | SDK streamer melakukan push dua jalur (utama = pihak ketiga, cadangan = SRS mandiri); sisi pemutar mengurai URL sesuai provider, jika pihak ketiga gagal otomatis beralih ke aliran mandiri |
| Danmaku/co-hosting | Dipisahkan dari pipeline video: danmaku melalui WS service, co-hosting melalui RTC pihak ketiga |
| Kepatuhan | Moderasi audio-video real-time pada jalur mandiri memakai ulang API moderasi pihak ketiga (hanya membeli moderasi, bukan kapasitas) |

### 7.2 Live Room

CRUD ruangan, state machine mulai/berhenti siaran, sampul, pengumuman (multibahasa), penghitung penonton (bee_tsdb), kanal ruangan danmaku (Redis pub/sub), manajemen peran co-hosting (streamer/slot co-hosting, service menerbitkan token RTC pihak ketiga), statistik online/puncak/durasi → dashboard admin.

## 8. Arsitektur Voice (Tiga Serangkai)

| Bentuk | Implementasi |
|------|------|
| Pesan suara | Ekstensi tipe pesan IM: penyimpanan S3 + transkode (m4a) + durasi |
| Panggilan 1v1 | Sinyal melalui gerbang WS (offer/answer/ICE), state machine dering/terima/tutup (Redis), media melalui mediasoup, catatan panggilan disimpan ke DB |
| Voice chat room | Manajemen ruangan memakai ulang pola live room; naik/turun mic dan pendengar dikelola statusnya oleh service, media melalui mediasoup |

## 9. Ekonomi Virtual (isi ulang + hadiah + penarikan)

```
移动端 IAP（App Store/Google Play/华为）──┐
国内：微信支付 / 支付宝（APP/H5）          ├─▶ PaymentProvider ─▶ 钱包
国外：微信国际 / 支付宝国际 / Stripe / PayPal│    （按 region 选路）
                                          └─▶ payments 支付单（幂等+验签+对账）
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
主播钱包 ──▶ payouts 提现单 ──▶ 国内：商家转账 │ 国外：Stripe Connect/PayPal
```

### 9.1 Kanal Pembayaran (dalam dan luar negeri)

```
PaymentProvider 接口（admin 配置）
├── 国内（CNY）
│   ├── wechat_cn    微信支付（APP/H5）
│   ├── alipay_cn    支付宝（APP/WAP）
│   └── 提现：商家转账（零钱/银行卡）
├── 国外（USD/EUR/...）
│   ├── wechat_global  微信国际支付（境外商户）
│   ├── alipay_global  支付宝国际（Alipay+）
│   ├── stripe         卡 / Apple Pay / Google Pay / SEPA
│   ├── paypal
│   └── 提现：Stripe Connect / PayPal 批量打款
└── 移动端虚拟币充值：App Store / Google Play / 华为 IAP（商店政策强制，服务端凭证校验）
```

| Mekanisme | Desain |
|------|------|
| Pemilihan kanal | Memilih kanal berdasarkan region pengguna + mata uang + aturan merchant admin, urutan fallback dapat dikonfigurasi (dalam/luar negeri terpisah alami) |
| Order pembayaran | Model terpadu payments: pengguna/kanal/jumlah/mata uang/state machine, idempoten di semua kanal |
| Callback | Enkapsulasi verifikasi tanda tangan terpadu (RSA/HMAC), callback idempoten, tugas rekonsiliasi harian (pencocokan laporan kanal) |
| Penarikan | Order penarikan payouts: transfer merchant domestik, pembayaran Stripe Connect/PayPal luar negeri; pilih mode split/agen sesuai kapabilitas kanal |
| Penetapan harga | Tabel harga regional (admin): koin virtual × harga mata uang, nilai tukar dikelola terpusat |
| Manajemen risiko | Batas/frekuensi/alarm order abnormal, audit semua transaksi (pakai ulang sistem audit) |
| SKU hadiah | Katalog hadiah (harga, penanda efek, nama multibahasa) dikelola oleh admin |

Kepatuhan: isi ulang koin virtual di perangkat seluler wajib melalui IAP toko (potongan Apple/Google/Huawei); WeChat/Alipay digunakan untuk H5/Web dan skenario region tertentu; penarikan melibatkan kliring dan penyelesaian dana, platform mewujudkannya melalui antarmuka split/pengiriman kanal berlisensi; kualifikasi kontrak kanal dikonfirmasi sebelum M6b; batas anak di bawah umur masuk ke tahap kepatuhan.

## 10. Model Data Inti

- Pengguna: users, user_profiles (field multibahasa)
- Sosial: follows, posts, post_translations, comments, comment_translations, likes, reports
- IM: conversations, conversation_members, messages, message_reads
- Live: live_rooms, live_streams (termasuk provider), danmaku_archive
- Voice: call_records, voice_rooms, voice_room_members
- Ekonomi virtual: wallets, currency_transactions, gift_catalog, gifts_given, streamer_earnings, withdrawals, payments, payouts, price_plans (penetapan harga regional/nilai tukar), merchant_configs (konfigurasi merchant kanal), products (SKU IAP)
- Platform: i18n_terms (istilah bersama empat klien), moderation_queue, provider_configs, audit_logs

## 11. Pemilihan Basis Data dan Penyimpanan

| Penggunaan | Penyimpanan | Komponen |
|------|------|----------|
| Data utama bisnis (pengguna/post/IM/dompet/moderasi/laporan) | MySQL 8 (master pusat + replika baca-saja regional) | Dipakai bersama service dan admin, satu-satunya sumber kebenaran |
| Data panas/sesi/status online/penghitung/kanal danmaku/state machine panggilan | Redis 7 | bee_kv / bee_cache (fitur redis) |
| Pencarian teks lengkap (pencarian post/pengguna, pencarian admin) | OpenSearch (mulai single-node) | bee_search (fitur opensearch) |
| Statistik deret waktu (DAU/trend/penonton live/durasi panggilan/dashboard) | QuestDB (mulai single-binary) | bee_tsdb (fitur questdb, dapat diganti influxdb) |
| Grafik sosial → feed rekomendasi | Neo4j Community Edition (mulai single-node) | bee_graph (fitur neo4j, dapat diganti nebulagraph) |
| File objek (gambar/video/suara/paket ekspor) | S3 (MinIO atau vendor cloud) | service terhubung langsung + distribusi CDN |
| Log audit | MySQL audit_logs, diarsipkan ke object storage saat kedaluwarsa | Pakai ulang sistem audit admin |

Prinsip pemilihan: setiap komponen bee-rust adalah abstraksi feature flag, mulai single-node, ganti backend terdistribusi seiring skala, tidak terkunci; MySQL selalu satu-satunya sumber kebenaran, lapisan komputasi (indeks/statistik/grafik/cache) hanya menyimpan data turunan yang dapat dibangun ulang. Frontend admin (Flutter) tidak menyentuh basis data secara langsung, semuanya melalui backend admin.

## 12. Deployment dan Operasional (Multi-Region Global)

- **Arsitektur awal**: dua region besar — region Tiongkok + region luar negeri; setiap region: cluster webman + cluster bee-rust + Redis lokal + media (SFU/SRS/TURN); master MySQL pusat + replika baca-saja per region; CDN per region
- **Akses WS terdekat**, pesan lintas region dikoordinasikan via pusat; push per region melalui vendor terkait
- **Jalur evolusi**: setelah lalu lintas tumbuh, sharding basis data berdasarkan hash pengguna
- **Monitoring**: metrik Prometheus (mengikuti pola open-admin), log terpusat, alarm (tingkat error/latensi/antrian menumpuk/kesehatan layanan media)

## 13. Keamanan dan Kepatuhan

- service mereplikasi pola pertahanan 18 lapis open-admin (XSS/SQLi/CSRF/rate limiting/CSP)
- Pipeline moderasi: kata sensitif multibahasa saat publikasi → moderasi gambar/audio-video (API pihak ketiga) → workbench moderasi manual
- GDPR: ekspor data, hak penghapusan/pencabutan, kebijakan retensi log, batas usia anak di bawah umur, diferensiasi aturan regional

## 14. Milestone (full-stack tunggal, sekitar 9–10 bulan)

| Fase | Konten | Durasi |
|------|------|------|
| M0 Fondasi | Kerangka monorepo, contracts (gRPC) + pembuatan stub tiga platform + pemeriksaan end-to-end, inisialisasi proyek tiga platform, CI (build+test), kerangka layanan bee-rust | 1–2 minggu |
| M1 Loop tertutup | Registrasi/login/profil, posting/detail, linimasa sederhana, like & komentar | 3–4 minggu |
| M2 Sosial lengkap | Sistem follow, feed lengkap, pencarian teks lengkap (bee_search), notifikasi | 3–4 minggu |
| M3 IM | Gerbang WS, percakapan, pesan, push offline, baca & tarik kembali | 4–6 minggu |
| M4 Voice | Komponen media (mediasoup+coturn), pesan suara, panggilan 1v1, voice chat room | 4–5 minggu |
| M5a Live utama | Pipeline pihak ketiga, live room, danmaku, co-hosting | 3–4 minggu |
| M5b Live pelengkap | Integrasi SRS mandiri, toleransi kegagalan push ganda, konfigurasi pemilihan rute | 2 minggu |
| M6a Koin virtual + hadiah | IAP, dompet, hadiah, bagi hasil | 2–3 minggu |
| M6b Kanal pembayaran | WeChat/Alipay/WeChat internasional/Alipay internasional/Stripe/PayPal, penarikan, rekonsiliasi | 3–4 minggu |
| M7 Multibahasa + kepatuhan | i18n semua klien, terjemahan konten, workbench moderasi, GDPR, integrasi moderasi audio-video | 3–4 minggu |
| M8 Rilis | Deployment dua region (termasuk region TURN), monitoring & alarm, uji beban, tinjauan keamanan | 2–3 minggu |

Setiap milestone adalah irisan yang dapat dikirim secara independen; dapat berhenti di tengah jalan, produk selalu lengkap dan dapat digunakan.

## 15. Ringkasan Tech Stack

| Subsistem | Teknologi |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / ekstensi grpc / snowflake-php |
| infrastructure | Rust / bee-rust workspace (search/graph/tsdb/kv/cache) / tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| Eksternal | Cloud live pihak ketiga, RTC pihak ketiga, API moderasi pihak ketiga, WeChat Pay/Alipay/WeChat internasional/Alipay internasional/Stripe/PayPal, IAP App Store/Google Play/Huawei, push APNs/FCM/Huawei |

## 16. Perencanaan Tim (sumber daya nyata, ritme stabil)

### 16.1 Struktur Organisasi

```
技术负责人 / PM（1人，兼任 contracts 契约 owner）
├── 后端组（2人）       webman service 主力 + admin 改造/支付专项
├── 平台组（2人）       Rust ×1（infrastructure）、音视频 ×1（media）
├── 客户端组（3人）     iOS、Android、HarmonyOS 各 1
├── 质量与运维（2人）   QA ×1、DevOps ×1
└── 支持（弹性）        UI/UX ×1（常驻）、支付/合规顾问（按需）、本地化（外包）
```

### 16.2 Rincian Peran

| Peran | Jumlah orang | Tanggung jawab | Keterampilan kunci | Mulai dari |
|------|---|------|----------|------|
| Pemimpin teknis/PM | 1 | Owner kontrak contracts (gRPC), koordinasi lintas subsistem, mendorong milestone | PHP/arsitektur/manajemen proyek | M0 |
| Backend PHP · service | 1 | Autentikasi/post/gerbang WS IM/sinyal live & voice/penjadwalan terjemahan/pemicu moderasi/GDPR | webman/Redis/MySQL/WS | M0 |
| Backend PHP · admin + pembayaran | 1 | Modifikasi 8 modul open-admin, PaymentProvider semua kanal, rekonsiliasi, penarikan | PHP/pengalaman kanal pembayaran | M0 (khusus pembayaran M6) |
| Insinyur iOS | 1 | Klien SwiftUI, APNs, WS, integrasi WebRTC, i18n | Swift/SwiftUI | M0 |
| Insinyur Android | 1 | Kotlin+Compose, FCM, WS, WebRTC, i18n | Kotlin/Compose | M0 |
| Insinyur HarmonyOS | 1 | Klien ArkTS, Push Kit, i18n | ArkTS/ekosistem HarmonyOS | M0 |
| Insinyur Rust | 1 | Layanan bee-rust (search/graph/tsdb) + tonic gRPC | Rust/axum/tonic | Akhir M1 |
| Insinyur audio-video | 1 | Komponen media (mediasoup/SRS/FFmpeg/coturn), toleransi kegagalan push ganda, deployment TURN regional | Node.js/WebRTC/SRS/transkode | Akhir M3 |
| Desainer UI/UX | 1 | Sistem desain tiga platform, visual live/hadiah/voice, standar copywriting i18n | Figma/desain multibahasa | M0 |
| QA | 1 | Regresi tiga platform + backend + media, uji beban, verifikasi alur moderasi/pembayaran | Pengujian seluler/API | M1 |
| DevOps | 1 | CI/CD, deployment dua region, monitoring Prometheus, operasional layanan media, log | Docker/K8s/Prometheus | M2 |
| Konsultan pembayaran/keuangan | Fleksibel | Kualifikasi kontrak kanal, aturan rekonsiliasi, batas manajemen risiko, penyelesaian bagi hasil | Industri pembayaran/keuangan | Mulai M6 |
| Konsultan kepatuhan/hukum | Fleksibel | GDPR, regulasi regional, aturan moderasi konten, kebijakan toko | Kepatuhan data | Mulai M7 |
| Lokalisasi | Outsource | Peninjauan terjemahan istilah, copywriting multibahasa | Review terjemahan | Mulai M7 |

### 16.3 Ritme Milestone

| Fase | Tim | Fokus paralel |
|------|------|----------|
| M0–M2 | Pemimpin + backend 2 + mobile 3 + desain + QA | Kontrak lebih dulu, tiga platform paralel sesuai OpenAPI; saat Rust siap, integrasi pencarian |
| M3–M4 | + audio-video, DevOps | Audio-video membangun media paralel dengan IM/voice |
| M5 | Semua | Live dua jalur, backend mendukung media |
| M6 | + konsultan pembayaran | Khusus pembayaran + rekonsiliasi |
| M7 | + konsultan kepatuhan, lokalisasi | i18n semua klien + penutupan kepatuhan |
| M8 | Jaminan penuh | Rilis dua region, uji beban, tinjauan keamanan |

### 16.4 Prioritas Perekrutan

1. Backend PHP ×2 + pemimpin teknis (inti periode fondasi, backend adalah domain dengan beban kerja terbesar)
2. Mobile ×3 (tiga platform paralel adalah kendala keras durasi total, semakin awal semakin baik)
3. UI/UX, QA
4. Rust, DevOps (sebelum M1–M2)
5. Audio-video (akhir M3)
6. Konsultan pembayaran/kepatuhan, lokalisasi (sesuai kebutuhan M6/M7)

### 16.5 Risiko dan Cadangan

- Audio-video dan kanal pembayaran adalah dua peran paling sulit direkrut (ahli langka), siapkan rencana cadangan outsource/konsultan
- Jika insinyur HarmonyOS sulit direkrut, insinyur Android dapat merangkap lebih dulu (ArkTS sejalan dengan TS, cepat dikuasai), ritme paralel tiga platform tidak terpengaruh
