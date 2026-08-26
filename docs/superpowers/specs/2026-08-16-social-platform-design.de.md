# Gesamtdesign der Social Platform (Social Platform Design)

**语言 / Languages:** [中文](2026-08-16-social-platform-design.md) · [English](2026-08-16-social-platform-design.en.md) · [한국어](2026-08-16-social-platform-design.ko.md) · [Русский](2026-08-16-social-platform-design.ru.md) · [Deutsch](2026-08-16-social-platform-design.de.md) · [Français](2026-08-16-social-platform-design.fr.md) · [Español](2026-08-16-social-platform-design.es.md) · [Português](2026-08-16-social-platform-design.pt.md) · [हिन्दी](2026-08-16-social-platform-design.hi.md) · [العربية](2026-08-16-social-platform-design.ar.md) · [বাংলা](2026-08-16-social-platform-design.bn.md) · [Bahasa Indonesia](2026-08-16-social-platform-design.id.md) · [日本語](2026-08-16-social-platform-design.ja.md)

- Datum: 2026-08-16
- Status: Bestätigt, Implementierung ausstehend
- Umfang: Bild+Text-Short-Content-Community + Instant Messaging + Live-Streaming/Sprache + virtuelle Ökonomie, mehrsprachig, global multiregional

## 1. Ziele und Umfang

Aufbau einer Social Platform, die Bild+Text-Short-Content mit IM verbindet, ergänzt um Live-Streaming (Video + Danmaku + Co-Hosting), Sprache (Nachrichten / 1v1-Anrufe / Sprachchat-Räume) und eine virtuelle Ökonomie mit Geschenk-Spenden. Unterstützung für mehrsprachiges UI, Inhaltsübersetzung und regionale Compliance, Bereitstellung in mehreren Weltregionen. Parallele native Entwicklung auf drei Plattformen: iOS / Android / HarmonyOS.

## 2. Systemübersicht

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

## 3. Verantwortlichkeiten der Subsysteme

### 3.1 contracts (gRPC-Verträge, neues Top-Level-Verzeichnis)

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- Generierungspipeline: CI erzeugt mit buf drei Arten von Stubs und committet sie in die jeweiligen Sub-Repos (Builds ohne Netzabhängigkeit)
  - service/, admin/ → PHP-Stubs (grpc/grpc + google/protobuf)
  - infrastructure/ → Rust-Stubs (tonic)
- Versionsregel: nur Felder hinzufügen, nie ändern oder löschen; Paketnamen tragen die Major-Version (`social.user.v1`)

### 3.2 service (webman v2) — nutzerseitiger Business-Monolith

- **API-Domänen**: auth (JWT-Doppeltokens + Blacklist), profile, posts, likes, comments, follows, IM (Konversationen/Nachrichten/WS-Gateway), notifications, Übersetzungs-Scheduling, Signalisierung für Live-Räume/Danmaku/Co-Hosting, Signalisierung für Sprachanrufe/Sprachchat-Räume, virtuelle Ökonomie (Wallet/Geschenke/IAP-Prüfung/Umsatzbeteiligung), GDPR-Export/-Löschung
- **Mehrsprachiges Fehlersystem**: Fehler liefern `{code, lang_key, params}` zurück; Texte rendert der Client je Locale
- **Queues** (redis-queue): Moderationstrigger, Übersetzungs-Scheduling, Push-Zustellung, asynchrone Statistiken, Geschenk-Effekt-Broadcast
- **Geplante Aufgaben** (webman-crontab): Übersetzungs-Vorwärmung, Bereinigung abgelaufener Tokens/Nachrichten, Audit-Archivierung, Abrechnung der Umsatzbeteiligung
- **IDs**: `erikwang2013/snowflake-php` (konsistent mit admin)
- **Verträge**: OpenAPI-3.0-Autoexport → typisierte Clients für die drei Plattformen

### 3.3 infrastructure (bee-rust) — Rechenebene mit hohem Durchsatz

Speichert keine fachlichen Primärdaten (MySQL ist die einzige Quelle der Wahrheit); übernimmt rechen-/abfrageintensive Fähigkeiten:

- `bee_search`: Volltextsuche über Beiträge/Nutzer (chinesische Wortsegmentierung, mehrsprachige Indizierung)
- `bee_graph`: sozialer Graph → Empfehlungsfeed
- `bee_tsdb`: Zeitreihen-Statistiken: DAU, Postings, Interaktionen, Live-Aufrufe, Dauer von Sprachanrufen usw.
- `bee_cache/bee_kv`: Timeline-Cache, Zähler (Likes, Aufrufe, Online-Nutzer)
- Regional bereitgestellt; leselastig, schreibarm; Daten aus der Zentrale repliziert

### 3.4 admin (open-admin-Umbau)

**Wiederverwendet**: JWT/RBAC/Audit/Dateiverwaltung/Health Checks/zh-en-i18n-Infrastruktur

**Neu**:
- Content-Moderation-Workbench: zweisprachige Gegenüberstellung von Beiträgen/Kommentaren/Bildern, mehrsprachige Vorlagen für Ablehnungsgründe, Nutzersanktionen
- Warteschlange zur Meldungsbearbeitung
- GDPR-Anfrageschalter (Export/Lösch-Tickets)
- Datendashboards auf Basis von bee_tsdb
- i18n-Begriffsverwaltung (CRUD für die vier Clients gemeinsamer Begriffe)
- Geschenk-Katalogverwaltung (SKU, Preise, Effekte, mehrsprachige Namen)
- Live-Provider-Konfiguration (Routing-Strategie, Umschaltreihenfolge)
- Prüfung von Auszahlungsanträgen

### 3.5 media (eigene Medienebene, Node.js + Systemdienste)

- `sfu/`: mediasoup; trägt die Medienebene von 1v1-Anrufen und Sprachchat-Räumen; nur Medienweiterleitung, keine Fachlogik
- `srs/`: selbst gehostetes SRS-Live-Streaming; RTMP-Ingest → FFmpeg-Transkodierung → HTTP-FLV/HLS-Auslieferung
- `coturn/`: TURN-Relay, Fallback für NAT-Traversal
- Die gesamte Signalisierung wird über das WS-Gateway von service weitergeleitet

### 3.6 apps — parallele native Entwicklung auf drei Plattformen

- Gemeinsamer OpenAPI-Vertrag; jede Plattform generiert ihren eigenen typisierten Client
- Einheitliche Infrastrukturmodule: Netzwerkebene (Retry/Auth-Refresh), WS-Client (IM/Danmaku/Anruf-Signalisierung), i18n (lokale Ressourcen + inkrementelle Remote-Begriffe), Push-Registrierung, Themes
- HarmonyOS-Hinweise: Huawei Push Kit, Anpassung an das ArkTS-Nebenläufigkeitsmodell

## 4. Backend-Kommunikation (gRPC)

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| Aufrufer → Aufgerufener | Inhalt |
|------|------|
| service → infra | Volltextsuche, Empfehlungsfeed, Timeline-Hot-Cache, Zähler-Lesen/-Schreiben, Zeitreihen-Statistikschreiben |
| admin → infra | Dashboard-Statistikabfragen, Backend-Suche |
| admin → service | Nutzersanktionen, Inhaltslöschung, Auslieferung von Moderationsergebnissen |
| service → admin | Meldungsereignisse, Einreihung von Moderationsaufgaben (async) |

Grenze: Die Apps der drei Plattformen und das Admin-Frontend (Flutter) nutzen HTTPS REST + WS und berühren gRPC nie direkt.

**Betriebsvoraussetzung**: gRPC auf PHP-Seite benötigt die offizielle `grpc`-Erweiterung (C-Erweiterung) + das Composer-Paket `grpc/grpc`; der Servermodus folgt workermans offiziellem walkor/grpc-Ansatz; die Deploy-Dokumentation muss dies klar beschreiben.

## 5. Mehrsprachige Architektur (drei Ebenen)

| Ebene | Ansatz |
|----|------|
| **UI-Ebene** | Locale-Ressourcen je Plattform (Start mit zh/en; das System unterstützt beliebige Sprachen); der Server sendet nur Fehlercodes + Template-Keys |
| **Inhaltsebene** | beim Veröffentlichen Originaltext speichern + automatische Spracherkennung ins `lang`-Feld; beim Lesen reader.lang ≠ author.lang → Übersetzungsdienst (LLM/MT-Provider-Abstraktion), Ergebnisse in Redis gecacht (bee_cache, TTL), `is_translated`-Flag erlaubt Rückkehr zum Original; beliebte Inhalte werden zeitgesteuert vorgewärmt |
| **Compliance-Ebene** | Moderationsregeln gelten regional (EU-GDPR-Regeln vs. andere Regionen); zweisprachige Meldungs-/Moderations-UI |

Danmaku ist realzeitnaher Kurztext: keine Inhaltsübersetzung, nur UI-i18n + mehrsprachige Filterung sensibler Wörter.

## 6. IM-Architektur

- **Gateway**: webman-WS-Gateway, mehrere Instanzen + Redis-pub/sub-Node-übergreifende Weiterleitung, idempotente Deduplizierung über `client_msg_id`
- **Daten**: conversations / conversation_members / messages / message_reads; 1v1 + Gruppenchats (Gruppenlimit 500)
- **Zustellung**: online → direkter WS-Push; offline → APNs/FCM/Huawei-Push
- **Funktionen**: Lesebestätigungen, Tippindikator, zeitlich begrenztes Zurückrufen, Bild-/Sprachnachrichten (S3-Upload + Transkodierung)
- Teilt sich Nutzer- und Benachrichtigungssystem mit dem Feed

## 7. Live-Streaming-Architektur (Video + Danmaku + Co-Hosting, Doppelspur)

### 7.1 Provider-Abstraktion (innerhalb von service)

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| Mechanismus | Design |
|------|------|
| Routing-Strategie | Standard-Provider wird bei Raum-Erstellung je Region gewählt (admin kann überschreiben); Regionen ohne Drittanbieter-Abdeckung oder mit Kostensensibilität → selbst gehostet |
| Fehler-Failover | Doppel-Ingest im Streamer-SDK (primär = Drittanbieter, Backup = eigener SRS); Player lösen URLs je Provider auf und wechseln bei Drittanbieter-Ausfall automatisch auf den eigenen Stream |
| Danmaku/Co-Hosting | von der Videopipeline entkoppelt: Danmaku über service-WS, Co-Hosting über Drittanbieter-RTC |
| Compliance | die Echtzeit-Audio/Video-Moderation der eigenen Pipeline nutzt Drittanbieter-Moderations-APIs (nur Moderation kaufen, nicht den Transport) |

### 7.2 Live-Räume

Raum-CRUD, Zustandsautomat Start/Ende des Streams, Cover, Ankündigungen (mehrsprachig), Aufrufzähler (bee_tsdb), Danmaku-Raumkanäle (Redis pub/sub), Rollenverwaltung für Co-Hosting (Host/Co-Host-Plätze; service stellt Drittanbieter-RTC-Tokens aus), Online-/Peak-/Dauer-Statistiken → admin-Dashboards.

## 8. Spracharchitektur (Dreiergespann)

| Form | Umsetzung |
|------|------|
| Sprachnachrichten | IM-Nachrichtentyp-Erweiterung: S3-Speicherung + Transkodierung (m4a) + Dauer |
| 1v1-Anrufe | Signalisierung über das WS-Gateway (offer/answer/ICE), Klingel/Annahme/Auflegen-Zustandsautomat (Redis), Medienebene über mediasoup, Anrufaufzeichnungen in der DB |
| Sprachchat-Räume | Raumverwaltung übernimmt das Live-Raum-Muster; Mikrofon an/aus/Zuhörer-Zustände verwaltet service; Medienebene über mediasoup |

## 9. Virtuelle Ökonomie (Aufladen + Geschenk-Spenden + Auszahlungen)

```
移动端 IAP（App Store/Google Play/华为）──┐
国内：微信支付 / 支付宝（APP/H5）          ├─▶ PaymentProvider ─▶ 钱包
国外：微信国际 / 支付宝国际 / Stripe / PayPal│    （按 region 选路）
                                          └─▶ payments 支付单（幂等+验签+对账）
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
主播钱包 ──▶ payouts 提现单 ──▶ 国内：商家转账 │ 国外：Stripe Connect/PayPal
```

### 9.1 Zahlungskanäle (Inland vs. Ausland)

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

| Mechanismus | Design |
|------|------|
| Kanal-Routing | Kanalwahl nach Nutzerregion + Währung + admin-Merchant-Regeln, konfigurierbare Fallback-Reihenfolge (natürliche Trennung Inland/Ausland) |
| Zahlungsbeleg | einheitliches payments-Modell: Nutzer/Kanal/Betrag/Währung/Zustandsautomat, über alle Kanäle idempotent |
| Callbacks | einheitliche Signaturprüfungs-Wrapper (RSA/HMAC), idempotente Callbacks, tägliche Abgleich-Jobs (Prüfung gegen Kanalabrechnungen) |
| Auszahlungen | payouts: Inland Merchant-Überweisung, Ausland Stripe Connect/PayPal; Split-/Auszahlungsmodus je Kanal-Fähigkeit |
| Preisgestaltung | regionale Preistabellen (admin): virtuelle Währung × Währungspreise, zentral verwaltete Wechselkurse |
| Risikokontrolle | Limits/Frequenzbegrenzungen/Warnungen bei anomalen Bestellungen, vollständige Auditierung (nutzt das Auditsystem) |
| Geschenk-SKU | Geschenk-Katalog (Preise, Effekt-IDs, mehrsprachige Namen) wird von admin verwaltet |

Compliance: Mobile Aufladungen virtueller Währung müssen über Store-IAP laufen (Apple/Google/Huawei Provision); WeChat/Alipay für H5/Web und regionale Szenarien; Auszahlungen betreffen die Geldabwicklung, daher setzt die Plattform sie über Split-/Auszahlungs-Schnittstellen lizenzierter Kanäle um; die Vertragsqualifikation der Kanäle ist vor M6b zu klären; Limits für Minderjährige kommen in die Compliance-Phase.

## 10. Zentrale Datenmodelle

- Nutzer: users, user_profiles (mehrsprachige Felder)
- Sozial: follows, posts, post_translations, comments, comment_translations, likes, reports
- IM: conversations, conversation_members, messages, message_reads
- Live: live_rooms, live_streams (mit Provider), danmaku_archive
- Sprache: call_records, voice_rooms, voice_room_members
- Virtuelle Ökonomie: wallets, currency_transactions, gift_catalog, gifts_given, streamer_earnings, withdrawals, payments, payouts, price_plans (regionale Preise/Wechselkurse), merchant_configs (Kanal-Merchant-Konfigurationen), products (IAP-SKUs)
- Plattform: i18n_terms (Begriffe für die vier Clients), moderation_queue, provider_configs, audit_logs

## 11. Datenbank- und Speicherwahl

| Zweck | Speicher | Komponente |
|------|------|----------|
| Fachliche Primärdaten (Nutzer/Beiträge/IM/Wallet/Moderation/Meldungen) | MySQL 8 (zentraler Master + regionale Read-only-Replikate) | von service und admin geteilt; einzige Quelle der Wahrheit |
| Hot-Daten/Sessions/Online-Status/Zähler/Danmaku-Kanäle/Anruf-Zustandsautomaten | Redis 7 | bee_kv / bee_cache (redis-Feature) |
| Volltextsuche (Beitrags-/Nutzersuche, Admin-Backend-Suche) | OpenSearch (Start mit einem Knoten) | bee_search (opensearch-Feature) |
| Zeitreihen-Statistiken (DAU/Trends/Live-Aufrufe/Anrufdauer/Dashboards) | QuestDB (Start mit einer Binärdatei) | bee_tsdb (questdb-Feature, austauschbar gegen influxdb) |
| Sozialer Graph → Empfehlungsfeed | Neo4j Community (Start mit einem Knoten) | bee_graph (neo4j-Feature, austauschbar gegen nebulagraph) |
| Objektdateien (Bilder/Videos/Sprache/Exportpakete) | S3 (MinIO oder Cloud-Anbieter) | service-Direktzugriff + CDN-Auslieferung |
| Audit-Logs | MySQL audit_logs, bei Ablauf Archivierung in Objektspeicher | nutzt das admin-Auditsystem |

Auswahlprinzipien: Die bee-rust-Komponenten sind Feature-Flag-Abstraktionen — Start mit einem Knoten, mit wachsender Skalierung Austausch gegen verteilte Backends, keine Bindung; MySQL ist immer die einzige Quelle der Wahrheit; die Rechenebene (Indizes/Statistiken/Graph/Cache) speichert nur rekonstruierbare abgeleitete Daten. Das Admin-Frontend (Flutter) berührt die Datenbank nie direkt; alles läuft über das admin-Backend.

## 12. Bereitstellung und Betrieb (global multiregional)

- **Startarchitektur**: zwei Großregionen — China + Ausland; jede Region mit webman-Cluster + bee-rust-Cluster + lokalem Redis + media (SFU/SRS/TURN); zentraler MySQL-Master + Read-only-Replikate je Region; CDN nach Region
- **WS-Nähest-Einwahl**, regionenübergreifende Nachrichten zentral koordiniert; Push pro Region über den jeweiligen Anbieter
- **Evolutionspfad**: nach Verkehrswachstum Sharding der Datenbanken nach Nutzer-Hash
- **Monitoring**: Prometheus-Metriken (nach dem open-admin-Muster), zentralisierte Logs, Alarme (Fehlerquote/Latenz/Queue-Stau/Gesundheit der Mediendienste)

## 13. Sicherheit und Compliance

- service repliziert das 18-Ebenen-Abwehrmodell von open-admin (XSS/SQLi/CSRF/Rate-Limiting/CSP)
- Moderationspipeline: mehrsprachiger Sensibelwortfilter beim Veröffentlichen → Bild-/Audio-Video-Moderation (Drittanbieter-APIs) → manuelle Moderation
- GDPR: Datencxport, Löschungs-/Beseitigungsrechte, Log-Aufbewahrungsrichtlinie, Altersgrenze für Minderjährige, regional differenzierte Regeln

## 14. Meilensteine (Solo-Fullstack, ca. 9–10 Monate)

| Phase | Inhalt | Dauer |
|------|------|------|
| M0 Fundament | monorepo-Gerüst, contracts(gRPC)+Stub-Generierung für drei Plattformen+End-to-End-Liveness-Probes, Projektinit der drei Plattformen, CI (build+test), bee-rust-Dienstgerüst | 1–2 Wochen |
| M1 Geschlossener Kreislauf | Registrierung/Login/Profil, Beitrag/Details, vereinfachte Timeline, Likes und Kommentare | 3–4 Wochen |
| M2 Sozial komplett | Follow-System, vollständiger Feed, Volltextsuche (bee_search), Benachrichtigungen | 3–4 Wochen |
| M3 IM | WS-Gateway, Konversationen, Nachrichten, Offline-Push, Lesen/Zurückrufen | 4–6 Wochen |
| M4 Sprache | media-Komponenten (mediasoup+coturn), Sprachnachrichten, 1v1-Anrufe, Sprachchat-Räume | 4–5 Wochen |
| M5a Live primär | Drittanbieter-Pipeline, Live-Räume, Danmaku, Co-Hosting | 3–4 Wochen |
| M5b Live Ergänzung | eigener SRS-Anschluss, Doppel-Ingest-Failover, Routing-Konfiguration | 2 Wochen |
| M6a Virtuelle Währung+Geschenke | IAP, Wallet, Geschenke, Umsatzbeteiligung | 2–3 Wochen |
| M6b Zahlungskanäle | WeChat/Alipay/WeChat Global/Alipay Global/Stripe/PayPal, Auszahlungen, Abgleich | 3–4 Wochen |
| M7 Mehrsprachigkeit+Compliance | i18n aller Plattformen, Inhaltsübersetzung, Moderations-Workbench, GDPR, Audio/Video-Moderationsintegration | 3–4 Wochen |
| M8 Launch | Zwei-Regionen-Bereitstellung (inkl. regionalem TURN), Monitoring/Alarme, Lasttests, Sicherheits-Nachprüfung | 2–3 Wochen |

Jeder Meilenstein ist ein unabhängig auslieferbares Segment; das Projekt kann jederzeit gestoppt werden, das Produkt bleibt immer vollständig nutzbar.

## 15. Tech-Stack-Übersicht

| Subsystem | Technologie |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / grpc-Erweiterung / snowflake-php |
| infrastructure | Rust / bee-rust workspace (search/graph/tsdb/kv/cache) / tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| Extern | Drittanbieter-Live-Cloud, Drittanbieter-RTC, Drittanbieter-Moderations-APIs, WeChat Pay/Alipay/WeChat Pay Global/Alipay Global/Stripe/PayPal, App-Store/Google-Play/Huawei-IAP, APNs/FCM/Huawei-Push |

## 16. Teamplanung (echte Ressourcen, stabiler Rhythmus)

### 16.1 Organisationsstruktur

```
技术负责人 / PM（1人，兼任 contracts 契约 owner）
├── 后端组（2人）       webman service 主力 + admin 改造/支付专项
├── 平台组（2人）       Rust ×1（infrastructure）、音视频 ×1（media）
├── 客户端组（3人）     iOS、Android、HarmonyOS 各 1
├── 质量与运维（2人）   QA ×1、DevOps ×1
└── 支持（弹性）        UI/UX ×1（常驻）、支付/合规顾问（按需）、本地化（外包）
```

### 16.2 Rollendetails

| Rolle | Person | Verantwortung | Kernkompetenzen | Einsatz |
|------|---|------|----------|------|
| Tech-Lead/PM | 1 | Owner der contracts(gRPC), Koordination über Subsysteme, Meilenstein-Vorantrieb | PHP/Architektur/Projektmanagement | M0 |
| Backend PHP · service | 1 | Auth/Beiträge/IM-WS-Gateway/Live- und Sprachsignalisierung/Übersetzungs-Scheduling/Moderationstrigger/GDPR | webman/Redis/MySQL/WS | M0 |
| Backend PHP · admin+Zahlungen | 1 | open-admin-8-Module-Umbau, PaymentProvider alle Kanäle, Abgleich, Auszahlungen | PHP/Zahlungskanalerfahrung | M0 (Zahlungsschwerpunkt M6) |
| iOS-Ingenieur | 1 | SwiftUI-Client, APNs, WS, WebRTC-Integration, i18n | Swift/SwiftUI | M0 |
| Android-Ingenieur | 1 | Kotlin+Compose, FCM, WS, WebRTC, i18n | Kotlin/Compose | M0 |
| HarmonyOS-Ingenieur | 1 | ArkTS-Client, Push Kit, i18n | ArkTS/HarmonyOS-Ökosystem | M0 |
| Rust-Ingenieur | 1 | bee-rust-Dienstwerdung (search/graph/tsdb) + tonic-gRPC | Rust/axum/tonic | Ende M1 |
| Audio/Video-Ingenieur | 1 | media-Komponenten (mediasoup/SRS/FFmpeg/coturn), Doppel-Ingest-Failover, regionaler TURN | Node.js/WebRTC/SRS/Transkodierung | Ende M3 |
| UI/UX-Designer | 1 | Designsystem der drei Plattformen, Live/Geschenk/Sprache-Visuals, i18n-Textrichtlinien | Figma/mehrsprachiges Design | M0 |
| QA | 1 | Regression drei Plattformen+Backend+Media, Lasttests, Prüfung Moderation/Zahlungsabläufe | Mobile-/API-Tests | M1 |
| DevOps | 1 | CI/CD, Zwei-Regionen-Bereitstellung, Prometheus-Monitoring, Betrieb der Mediendienste, Logging | Docker/K8s/Prometheus | M2 |
| Zahlungs-/Finanzberater | flexibel | Kanal-Vertragsqualifikation, Abgleichregeln, Risikolimits, Abrechnung der Umsatzbeteiligung | Zahlungsbranche/Finanzen | ab M6 |
| Compliance-/Rechtsberater | flexibel | GDPR, regionale Vorschriften, Inhaltsmoderationsregeln, Store-Richtlinien | Daten-Compliance | ab M7 |
| Lokalisierung | Outsourcing | Übersetzung und Prüfung der Begriffe, mehrsprachige Texte | Übersetzung/Prüfung | ab M7 |

### 16.3 Meilenstein-Rhythmus

| Phase | Team | Paralleler Fokus |
|------|------|----------|
| M0–M2 | Lead+2 Backend+3 Mobile+Design+QA | Verträge zuerst; drei Plattformen parallel auf OpenAPI; Rust kommt für die Suche |
| M3–M4 | +Audio/Video, DevOps | Audio/Video baut media parallel zu IM/Sprache |
| M5 | Volles Team | Live-Doppelspur; Backend unterstützt Media |
| M6 | +Zahlungsberater | Zahlungsschwerpunkt+Abgleich |
| M7 | +Compliance-Berater, Lokalisierung | i18n aller Plattformen+Compliance-Abschluss |
| M8 | Volles Team, Absicherung | Zwei-Regionen-Launch, Lasttests, Sicherheits-Nachprüfung |

### 16.4 Einstellungsprioritäten

1. Backend PHP ×2 + Tech-Lead (Kern der Fundamentphase; Backend ist der größte Arbeitsbereich)
2. Mobile ×3 (Drei-Plattform-Parallelität ist die harte Randbedingung der Gesamtdauer — je früher, desto besser)
3. UI/UX, QA
4. Rust, DevOps (vor M1–M2 an Bord)
5. Audio/Video (Ende M3)
6. Zahlungs-/Compliance-Berater, Lokalisierung (bei Bedarf in M6/M7)

### 16.5 Risiken und Absicherung

- Audio/Video und Zahlungskanäle sind die zwei am schwersten zu besetzenden Rollen (Expertenmangel); Outsourcing-/Berater-Fallback einplanen
- Ist ein HarmonyOS-Ingenieur schwer zu finden, kann zunächst ein Android-Ingenieur einspringen (ArkTS stammt aus derselben Familie wie TS und ist schnell erlernbar); der Drei-Plattform-Parallelrhythmus bleibt unberührt
