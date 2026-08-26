# ソーシャルプラットフォーム全体設計（Social Platform Design）

**语言 / Languages:** [中文](2026-08-16-social-platform-design.md) · [English](2026-08-16-social-platform-design.en.md) · [한국어](2026-08-16-social-platform-design.ko.md) · [Русский](2026-08-16-social-platform-design.ru.md) · [Deutsch](2026-08-16-social-platform-design.de.md) · [Français](2026-08-16-social-platform-design.fr.md) · [Español](2026-08-16-social-platform-design.es.md) · [Português](2026-08-16-social-platform-design.pt.md) · [हिन्दी](2026-08-16-social-platform-design.hi.md) · [العربية](2026-08-16-social-platform-design.ar.md) · [বাংলা](2026-08-16-social-platform-design.bn.md) · [Bahasa Indonesia](2026-08-16-social-platform-design.id.md) · [日本語](2026-08-16-social-platform-design.ja.md)

- 日付：2026-08-16
- ステータス：確定済み、実装待ち
- 範囲：画像・テキスト短尺コンテンツコミュニティ＋インスタントメッセージ（IM）＋ライブ/ボイス＋仮想経済、多言語、グローバル多リージョン

## 1. 目標と範囲

画像・テキスト短尺コンテンツ＋IM のソーシャルプラットフォームを構築する。ライブ（動画＋弾幕＋コホスティング）、ボイス（メッセージ/1対1通話/ボイスチャットルーム）、ギフト投げ銭の仮想経済を備える。UI 多言語、コンテンツ翻訳、多地域コンプライアンスに対応し、グローバル多リージョン展開。iOS / Android / HarmonyOS の三端を並行してネイティブ開発。

## 2. システム概要

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

## 3. サブシステムの責務

### 3.1 contracts（gRPC 契約、新設のトップレベルディレクトリ）

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- 生成パイプライン：CI が buf で3種類のスタブを生成し、各サブリポジトリにコミットする（ビルドはネットワーク非依存）
  - service/、admin/ → PHP スタブ（grpc/grpc + google/protobuf）
  - infrastructure/ → Rust スタブ（tonic）
- バージョン規則：フィールドは追加のみ・変更削除なし。パッケージ名にメジャーバージョンを付与（`social.user.v1`）

### 3.2 service（webman v2）— ユーザー向け業務モノリス

- **API 領域**：auth（JWT ダブルトークン＋ブラックリスト）、profile、posts、likes、comments、follows、IM（会話/メッセージ/WS ゲートウェイ）、notifications、翻訳スケジューリング、ライブルーム/弾幕/コホスティングのシグナリング、ボイス通話/ボイスチャットルームのシグナリング、仮想経済（ウォレット/ギフト/IAP 検証/収益分配）、GDPR エクスポート/削除
- **多言語エラー体系**：エラーは `{code, lang_key, params}` を返し、文言はクライアントが locale に応じてレンダリング
- **キュー**（redis-queue）：モデレーション発火、翻訳スケジューリング、プッシュ配信、非同期統計、ギフトエフェクトのブロードキャスト
- **定期実行**（webman-crontab）：翻訳のプレウォーム、期限切れトークン/メッセージのクリーンアップ、監査アーカイブ、収益分配の決済
- **ID**：`erikwang2013/snowflake-php`（admin と同一）
- **契約**：OpenAPI 3.0 の自動エクスポート → 三端で型付きクライアントを生成

### 3.3 infrastructure（bee-rust）— 高スループット計算層

業務の主データは保持しない（MySQL が唯一の真実の源）。計算負荷・クエリ負荷の高い機能を担う：

- `bee_search`：投稿/ユーザーの全文検索（中国語分かち書き、多言語インデックス）
- `bee_graph`：ソーシャルグラフ → レコメンドフィード
- `bee_tsdb`：DAU、投稿、インタラクション、ライブ視聴、ボイス通話時間などの時系列統計
- `bee_cache/bee_kv`：タイムラインキャッシュ、カウンター（いいね数、視聴数、オンライン人数）
- リージョンごとに展開。読み取り中心で、データは中央からレプリケーション

### 3.4 admin（open-admin 改修）

**再利用**：JWT/RBAC/監査/ファイル管理/ヘルスチェック/中英 i18n の基盤

**新規**：
- コンテンツモデレーションワークベンチ：投稿/コメント/画像の二言語対照モデレーション、却下理由の多言語テンプレート、ユーザー処罰
- 通報処理キュー
- GDPR リクエスト台（エクスポート/削除チケット）
- データダッシュボードで bee_tsdb に接続
- i18n 用語管理（四端共通用語の CRUD）
- ギフトライブラリ管理（SKU、価格、エフェクト、多言語名称）
- ライブ provider 設定（ルーティング戦略、切替順序）
- 出金申請の審査

### 3.5 media（自社構築メディア層、Node.js ＋ システムサービス）

- `sfu/`：mediasoup。1対1通話、ボイスチャットルームのメディア面を担う。メディア転送のみで業務は持たない
- `srs/`：SRS による自社ライブ。RTMP 配信 → FFmpeg トランスコード → HTTP-FLV/HLS 配信
- `coturn/`：TURN リレー。NAT 越えのフォールバック
- シグナリングは一律 service の WS ゲートウェイで中継

### 3.6 apps — 三端ネイティブ並行開発

- OpenAPI 契約を共有し、各端は独立に型付きクライアントを生成
- 統一基盤モジュール：ネットワーク層（リトライ/認証リフレッシュ）、WS クライアント（IM/弾幕/通話シグナリング）、i18n（ローカルリソース＋リモート用語の差分更新）、プッシュ登録、テーマ
- HarmonyOS の注意点：Huawei Push Kit、ArkTS 並行モデルへの適合

## 4. バックエンド通信（gRPC）

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| 呼び出し側 → 被呼び出し側 | 内容 |
|------|------|
| service → infra | 全文検索、レコメンドフィード、タイムラインのホットキャッシュ、カウンターの読み書き、時系列統計の書き込み |
| admin → infra | ダッシュボード統計のクエリ、管理画面検索 |
| admin → service | ユーザー処罰、コンテンツ削除、モデレーション結果の通知 |
| service → admin | 通報イベント、モデレーションタスクのキュー投入（非同期） |

境界：三端アプリと管理側フロントエンド（Flutter）は HTTPS REST ＋ WS を使用し、gRPC には直接触れない。

**運用前提**：PHP 側の gRPC は公式 `grpc` 拡張（C 拡張）＋ `grpc/grpc` composer パッケージに依存。サーバーモードは workerman 公式の walkor/grpc 方式を参照し、デプロイ文書に明記する必要がある。

## 5. 多言語アーキテクチャ（3層）

| 層 | 方針 |
|----|------|
| **UI 層** | 各端の locale リソース（初期は zh/en、仕組みは任意言語対応）。サーバーはエラーコード＋テンプレート key のみ送信 |
| **コンテンツ層** | 投稿時に原文を保存し、自動言語検出で `lang` フィールドに書き込み。閲覧時に reader.lang ≠ author.lang → 翻訳サービス（LLM/MT provider 抽象）、結果は Redis にキャッシュ（bee_cache、TTL）、`is_translated` マークで原文に切替可能。人気コンテンツは定期プレウォーム |
| **コンプライアンス層** | モデレーション規則はリージョンごとに適用（EU の GDPR 規則 vs 他リージョン）。通報/モデレーション画面は二言語 |

弾幕はリアルタイムの短文のため内容翻訳は行わず、UI i18n ＋ 多言語の不適切ワードフィルタのみ実施。

## 6. IM アーキテクチャ

- **ゲートウェイ**：webman WS ゲートウェイ。マルチインスタンス＋ Redis pub/sub によるノード間転送、`client_msg_id` による冪等重複排除
- **データ**：conversations / conversation_members / messages / message_reads。1対1＋グループ（グループ上限 500）
- **配信**：オンライン → WS 直接配信。オフライン → APNs/FCM/Huawei プッシュ
- **機能**：既読レシート、入力中表示、時間制限付き撤回、画像/ボイスメッセージ（S3 アップロード＋トランスコード）
- 投稿フィードとユーザー体系・通知体系を共用

## 7. ライブアーキテクチャ（動画＋弾幕＋コホスティング、二重トラック方式）

### 7.1 Provider 抽象（service 内）

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| 仕組み | 設計 |
|------|------|
| ルーティング戦略 | ライブルーム作成時にリージョンごとのデフォルト provider（admin で上書き設定可）。第三者カバレッジがない、またはコスト重視のリージョン → 自社構築 |
| 障害対策 | 配信者側 SDK で二重配信（主＝第三者、副＝自社 SRS）。視聴側は provider に応じて URL を解決し、第三者障害時は自動で自社ストリームに切替 |
| 弾幕/コホスティング | 動画パイプラインから分離：弾幕は service WS、コホスティングは第三者 RTC |
| コンプライアンス | 自社パイプラインのリアルタイム音声・映像モデレーションは第三者審査 API を再利用（モデレーションのみ購入、容量は購入しない） |

### 7.2 ライブルーム

ルーム CRUD、配信開始/終了のステートマシン、カバー画像、告知（多言語）、視聴カウント（bee_tsdb）、弾幕ルームチャンネル（Redis pub/sub）、コホスティング役割管理（配信者/コホスティング枠、service が第三者 RTC token を発行）、オンライン/ピーク/時間統計 → admin ダッシュボード。

## 8. ボイスアーキテクチャ（3点セット）

| 形態 | 実装 |
|------|------|
| ボイスメッセージ | IM メッセージタイプ拡張：S3 保存＋トランスコード（m4a）＋再生時間 |
| 1対1通話 | シグナリングは WS ゲートウェイ（offer/answer/ICE）、着信/応答/切断のステートマシン（Redis）、メディア面は mediasoup、通話記録は DB 保存 |
| ボイスチャットルーム | ルーム管理はライブルームの方式を再利用。マイクON/OFF、リスナーの状態は service が管理、メディア面は mediasoup |

## 9. 仮想経済（課金＋ギフト投げ銭＋出金）

```
移动端 IAP（App Store/Google Play/华为）──┐
国内：微信支付 / 支付宝（APP/H5）          ├─▶ PaymentProvider ─▶ 钱包
国外：微信国际 / 支付宝国际 / Stripe / PayPal│    （按 region 选路）
                                          └─▶ payments 支付单（幂等+验签+对账）
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
主播钱包 ──▶ payouts 提现单 ──▶ 国内：商家转账 │ 国外：Stripe Connect/PayPal
```

### 9.1 決済チャネル（国内/国外の区分）

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

| 仕組み | 設計 |
|------|------|
| チャネルルーティング | ユーザーの region ＋ 通貨 ＋ admin のマーチャント規則でチャネルを選択。フォールバック順序を設定可能（国内外は自然に分離） |
| 支払いオーダー | payments 統一モデル：ユーザー/チャネル/金額/通貨/ステートマシン、全チャネルで冪等 |
| コールバック | 統一署名検証のラッパー（RSA/HMAC）、コールバックは冪等、日次照合タスク（チャネル明細との突合） |
| 出金 | payouts 出金オーダー：国内はマーチャント振込、国外は Stripe Connect/PayPal 支払い。チャネル能力に応じて分帳/代発モードを選択 |
| 価格設定 | リージョン別価格表（admin）：仮想コイン × 通貨価格、為替レートは集中管理 |
| リスク管理 | 限度額/頻度制限/異常オーダーアラート、全取引の監査（監査体系を再利用） |
| ギフト SKU | ギフトカタログ（価格、エフェクト識別子、多言語名称）は admin が管理 |

コンプライアンス：モバイルの仮想コイン課金はストア IAP 必須（Apple/Google/Huawei の手数料）。WeChat/Alipay は H5/Web および特定リージョンのシーンで使用。出金は資金の清算を伴うため、プラットフォームはライセンス保有チャネルの分帳/代発インターフェースで実行。チャネル契約資格は M6b までに確認。未成年者の利用限度はコンプライアンス段階で対応。

## 10. コアデータモデル

- ユーザー：users、user_profiles（多言語フィールド）
- ソーシャル：follows、posts、post_translations、comments、comment_translations、likes、reports
- IM：conversations、conversation_members、messages、message_reads
- ライブ：live_rooms、live_streams（provider 含む）、danmaku_archive
- ボイス：call_records、voice_rooms、voice_room_members
- 仮想経済：wallets、currency_transactions、gift_catalog、gifts_given、streamer_earnings、withdrawals、payments、payouts、price_plans（リージョン価格/為替）、merchant_configs（チャネルマーチャント設定）、products（IAP SKU）
- プラットフォーム：i18n_terms（四端共通用語）、moderation_queue、provider_configs、audit_logs

## 11. データベースとストレージ選定

| 用途 | ストレージ | 実装コンポーネント |
|------|------|----------|
| 業務主データ（ユーザー/投稿/IM/ウォレット/モデレーション/通報） | MySQL 8（中央マスター＋リージョン読み取り専用レプリカ） | service と admin で共用、唯一の真実の源 |
| ホットデータ/セッション/オンライン状態/カウンター/弾幕チャンネル/通話ステートマシン | Redis 7 | bee_kv / bee_cache（redis 機能） |
| 全文検索（投稿/ユーザー検索、admin 管理画面検索） | OpenSearch（シングルノードから開始） | bee_search（opensearch 機能） |
| 時系列統計（DAU/トレンド/ライブ視聴/通話時間/ダッシュボード） | QuestDB（単一バイナリから開始） | bee_tsdb（questdb 機能、influxdb に変更可） |
| ソーシャルグラフ → レコメンドフィード | Neo4j コミュニティ版（シングルノードから開始） | bee_graph（neo4j 機能、nebulagraph に変更可） |
| オブジェクトファイル（画像/動画/音声/エクスポートパッケージ） | S3（MinIO またはクラウドベンダー） | service 直接接続＋ CDN 配信 |
| 監査ログ | MySQL audit_logs、期限到達でオブジェクトストレージにアーカイブ | admin 監査体系を再利用 |

選定原則：bee-rust の各コンポーネントはフィーチャーフラグ抽象とし、シングルノードから開始して規模に応じて分散バックエンドへ交換可能で、ベンダーロックインしない。MySQL は常に唯一の真実の源。計算層（インデックス/統計/グラフ/キャッシュ）は再構築可能な派生データのみ保持。管理側フロントエンド（Flutter）はデータベースに直接触れず、すべて admin バックエンド経由。

## 12. デプロイと運用（グローバル多リージョン）

- **初期構成**：中国リージョン＋海外リージョンの2大リージョン。各リージョンに webman クラスタ＋ bee-rust クラスタ＋ローカル Redis＋ media（SFU/SRS/TURN）。MySQL 中央マスター＋各リージョン読み取り専用レプリカ。CDN はリージョン別
- **WS は最寄り接続**、クロスリージョンは中央で調整。プッシュはリージョンごとに対応ベンダー
- **進化パス**：トラフィック増加後はユーザー hash でシャーディング
- **監視**：Prometheus メトリクス（open-admin の方式を踏襲）、集中ログ、アラート（エラー率/レイテンシ/キュー滞留/メディアサービス健全性）

## 13. セキュリティとコンプライアンス

- service は open-admin の18層防御パターン（XSS/SQLi/CSRF/レート制限/CSP）を踏襲
- モデレーションパイプライン：投稿時多言語の不適切ワード → 画像/音声・映像審査（第三者 API）→ 人手によるモデレーション台
- GDPR：データエクスポート、退会/削除権、ログ保持ポリシー、未成年の年齢要件、リージョン別規則の差異化

## 14. マイルストーン（ワンマンフルスタック、約9〜10か月）

| フェーズ | 内容 | 期間 |
|------|------|------|
| M0 基盤 | monorepo 骨格、contracts（gRPC）＋三端スタブ生成＋E2E 死活確認、三端プロジェクト初期化、CI（build+test）、bee-rust サービス骨格 | 1–2週 |
| M1 クローズドループ | 登録/ログイン/プロフィール、投稿/詳細、簡易タイムライン、いいね・コメント | 3–4週 |
| M2 ソーシャル完備 | フォロー体系、完全フィード、全文検索（bee_search）、通知 | 3–4週 |
| M3 IM | WS ゲートウェイ、会話、メッセージ、オフラインプッシュ、既読・撤回 | 4–6週 |
| M4 ボイス | media コンポーネント（mediasoup+coturn）、ボイスメッセージ、1対1通話、ボイスチャットルーム | 4–5週 |
| M5a ライブ主力 | 第三者パイプライン、ライブルーム、弾幕、コホスティング | 3–4週 |
| M5b ライブ補完 | 自社 SRS 接続、二重配信の障害対策、ルーティング設定 | 2週 |
| M6a 仮想コイン＋ギフト | IAP、ウォレット、ギフト、収益分配 | 2–3週 |
| M6b 決済チャネル | WeChat/Alipay/WeChat 国際/Alipay 国際/Stripe/PayPal、出金、照合 | 3–4週 |
| M7 多言語＋コンプライアンス | 全端 i18n、コンテンツ翻訳、モデレーション台、GDPR、音声・映像審査連携 | 3–4週 |
| M8 リリース | 二リージョン展開（TURN リージョン含む）、監視アラート、負荷試験、セキュリティ再確認 | 2–3週 |

各マイルストーンは独立してデリバリー可能なスライス。途中で停止でき、プロダクトは常に完全な形で利用可能。

## 15. テクノロジースタック総括

| サブシステム | 技術 |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / grpc 拡張 / snowflake-php |
| infrastructure | Rust / bee-rust workspace（search/graph/tsdb/kv/cache）/ tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| 外部 | 第三者ライブクラウド、第三者 RTC、第三者審査 API、WeChat Pay/Alipay/WeChat 国際/Alipay 国際/Stripe/PayPal、App Store/Google Play/Huawei IAP、APNs/FCM/Huawei プッシュ |

## 16. チーム計画（実人員、安定リズム）

### 16.1 組織構成

```
技术负责人 / PM（1人，兼任 contracts 契约 owner）
├── 后端组（2人）       webman service 主力 + admin 改造/支付专项
├── 平台组（2人）       Rust ×1（infrastructure）、音视频 ×1（media）
├── 客户端组（3人）     iOS、Android、HarmonyOS 各 1
├── 质量与运维（2人）   QA ×1、DevOps ×1
└── 支持（弹性）        UI/UX ×1（常驻）、支付/合规顾问（按需）、本地化（外包）
```

### 16.2 役割詳細

| 役割 | 人数 | 責務 | 主要スキル | 着任 |
|------|---|------|----------|------|
| テックリード/PM | 1 | contracts（gRPC）契約オーナー、サブシステム間調整、マイルストーン推進 | PHP/アーキテクチャ/プロジェクト管理 | M0 |
| バックエンド PHP・service | 1 | 認証/投稿/IM WS ゲートウェイ/ライブ・ボイスシグナリング/翻訳スケジューリング/モデレーション発火/GDPR | webman/Redis/MySQL/WS | M0 |
| バックエンド PHP・admin＋決済 | 1 | open-admin の8モジュール改修、PaymentProvider 全チャネル、照合、出金 | PHP/決済チャネル経験 | M0（決済専任 M6） |
| iOS エンジニア | 1 | SwiftUI クライアント、APNs、WS、WebRTC 統合、i18n | Swift/SwiftUI | M0 |
| Android エンジニア | 1 | Kotlin+Compose、FCM、WS、WebRTC、i18n | Kotlin/Compose | M0 |
| HarmonyOS エンジニア | 1 | ArkTS クライアント、Push Kit、i18n | ArkTS/鴻蒙エコシステム | M0 |
| Rust エンジニア | 1 | bee-rust のサービス化（search/graph/tsdb）＋ tonic gRPC | Rust/axum/tonic | M1 末 |
| 音声・映像エンジニア | 1 | media コンポーネント（mediasoup/SRS/FFmpeg/coturn）、二重配信の障害対策、TURN リージョン展開 | Node.js/WebRTC/SRS/トランスコード | M3 末 |
| UI/UX デザイナー | 1 | 三端デザイン体系、ライブ/ギフト/ボイスのビジュアル、i18n コピー規範 | Figma/多言語デザイン | M0 |
| QA | 1 | 三端＋バックエンド＋メディアのリグレッション、負荷試験、モデレーション/決済フロー検証 | モバイル/API テスト | M1 |
| DevOps | 1 | CI/CD、二リージョン展開、Prometheus 監視、メディアサービス運用、ログ | Docker/K8s/Prometheus | M2 |
| 決済/財務顧問 | フレキシブル | チャネル契約資格、照合規則、リスク管理限度、収益分配決済 | 決済業界/財務 | M6 から |
| コンプライアンス/法務顧問 | フレキシブル | GDPR、リージョン規制、コンテンツモデレーション規則、ストアポリシー | データコンプライアンス | M7 から |
| ローカライズ | 外注 | 用語翻訳の校閲、多言語コピー | 翻訳校閲 | M7 から |

### 16.3 マイルストーンリズム

| フェーズ | チーム | 並行重点 |
|------|------|----------|
| M0–M2 | リーダー＋バックエンド2＋モバイル3＋デザイン＋QA | 契約を先行し、三端は OpenAPI に従い並行。Rust 着任で検索を接続 |
| M3–M4 | ＋音声・映像、DevOps | 音声・映像が media を構築し、IM/ボイスと並行 |
| M5 | 全員 | ライブ二重トラック、バックエンドがメディアを支援 |
| M6 | ＋決済顧問 | 決済専任＋照合 |
| M7 | ＋コンプライアンス顧問、ローカライズ | i18n 全端＋コンプライアンス完結 |
| M8 | 全員で保障 | 二リージョンリリース、負荷試験、セキュリティ再確認 |

### 16.4 採用優先度

1. バックエンド PHP ×2＋テックリード（基盤期の核。バックエンドが最大の工数領域）
2. モバイル ×3（三端並行は総工期の制約条件。早ければ早いほど良い）
3. UI/UX、QA
4. Rust、DevOps（M1–M2 までに着任）
5. 音声・映像（M3 末）
6. 決済/コンプライアンス顧問、ローカライズ（M6/M7 で必要に応じて）

### 16.5 リスクとフォールバック

- 音声・映像と決済チャネルは最も採用が難しい2役割（専門家が希少）。外注/顧問のフォールバックを用意
- HarmonyOS エンジニアが採用困難な場合は Android エンジニアが兼任で先行（ArkTS は TS と同系で習得が速い）。三端並行のリズムは影響なし
