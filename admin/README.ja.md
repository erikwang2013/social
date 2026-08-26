# オープン管理バックエンド (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

webman v2 + Flutter ベースのフルスタック管理バックエンドシステム。

> [English version](README_EN.md) | [アーキテクチャ設計図](docs/ARCHITECTURE.md) | [設計ドキュメント](docs/DESIGN.md) | [セキュリティアーキテクチャ](docs/SECURITY.md) | [API リファレンス](docs/API.md)

## 機能一覧

| 業務領域 | 機能 | 説明 |
|--------|------|------|
| 🔐 認証 | ログイン/トークン更新/ログアウト | クリック認証コード + JWT + ブラックリスト |
| | アカウントロック | 5 回失敗で 15 分ロック |
| | 同時セッション制限 | 同一ユーザーの有効トークンは最大 3 つ |
| 📊 ダッシュボード | リアルタイム統計/トレンドグラフ/分布グラフ/最近の操作 | Redis キャッシュ 5 分 |
| 👥 ユーザー管理 | CRUD + 一括削除/有効・無効化 | ソフトデリート + パスワード再確認 |
| | Excel 一括インポート | 行単位の検証 + エラーレポート |
| 🔒 ロール権限 | ロール CRUD + 権限ツリー | RBAC method.path 粒度の権限検証 |
| ⚙ システム設定 | キー・バリュー CRUD | グループ管理 |
| 📋 操作監査 | ログ照会 + アクセス元端末検出 | 8 プラットフォーム自動認識 |
| 📁 ファイル管理 | アップロード/Excel エクスポート/PDF エクスポート | 機密データ自動マスキング |
| 🛡 セキュリティ防御 | 18 層の多層防御 | XSS/SQL インジェクション/パストラバーサル/コマンドインジェクション/CSRF/レート制限/CSP... |
| 🏥 運用 | ヘルスチェック/metrics/API ドキュメント/security.txt | Prometheus + OpenAPI 3.0 + hg/apidoc 対話型ドキュメント |
| 🌐 国際化 | 中国語・英語切り替え | Accept-Language ヘッダー / ?lang= パラメータ |

## 技術スタック

| レイヤー | 技術 | 説明 |
|---|------|------|
| バックエンドフレームワーク | webman v2 (workerman) | 超高性能 PHP 常駐プロセスフレームワーク |
| PHP バージョン | 8.3+ | |
| データベース | MySQL 8.0+ | テーブルプレフィックス `erik_`、BIGINT 非オートインクリメント主キー |
| 検索エンジン | Elasticsearch | `webman-scout` による同期と検索 |
| 管理側フロントエンド | Flutter 3.x | Web は PC 管理バックエンドスタイル（`apps/flutter/`） |
| モバイル | HarmonyOS ArkTS | HarmonyOS ネイティブクライアント（`apps/harmonyos/`）、スマホ/タブレット/2in1 対応 |

## 主要依存パッケージ

| パッケージ | 用途 |
|---|------|
| `erikwang2013/snowflake-php` | Snowflake アルゴリズムでグローバル一意の BIGINT 主キーを生成 |
| `erikwang2013/hashids` | API レイヤーで ID を暗号化・復号化し、実際のデータベース ID を隠す |
| `erikwang2013/jwt-webman` | JWT 認証トークンの発行と検証 |
| `erikwang2013/encryption` | API 転送レイヤーの機密データ暗号化・復号化 |
| `erikwang2013/encryptable` | データベース保存レイヤーの機密フィールド自動暗号化・復号化 |
| `erikwang2013/webman-scout` | Elasticsearch データ同期と全文検索 |
| `erikwang2013/season` | 国旗データ |
| `erikwang2013/poster-php` | クリック認証コードの生成と検証 + ポスター生成 |
| `phpoffice/phpspreadsheet` | Excel エクスポート |
| `barryvdh/laravel-dompdf` | PDF エクスポート（Dompdf ベース） |

## プロジェクト構成

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

## 環境要件

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41（フロントエンド開発にのみ必要）
- Elasticsearch >= 7.x（任意、検索機能に必要）

## クイックスタート

### 1. 依存関係のインストール

```bash
composer install
```

### 2. 環境変数の設定

環境変数をコピーして変更します（任意。設定しない場合は `config/*.php` 内のデフォルト値を使用）:

```bash
cp .env.example .env
```

主な設定項目：

| 環境変数 | 説明 | デフォルト値 |
|---------|------|--------|
| `JWT_SECRET` | JWT 署名キー | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Hashids ソルト | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | API 暗号化キー | 32 バイトのデフォルト値 |
| `SNOWFLAKE_DATACENTER_ID` | データセンター ID (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | ワーカーノード ID (0-31) | `1` |
| `SCOUT_HOSTS` | ES アドレス | `http://localhost:9200` |

**本番環境ではすべてのキーを必ずランダム文字列に変更してください。**

### 3. ワンクリックインストール

サービス起動後、ブラウザでインストールウィザードにアクセスし、データベース初期化と管理者作成を完了します：

```bash
php start.php start
```

デフォルトで `http://0.0.0.0:8787` をリッスンします（ポートは `config/server.php` で変更可能）。

ブラウザで **`http://localhost:8787/install`** を開き、ウィザードに従って入力します：

| 手順 | 内容 |
|------|------|
| ① データベース設定 | ホストアドレス、ポート、データベース名、ユーザー名、パスワード |
| ② 管理者設定 | 管理者ユーザー名、パスワード（デフォルト admin / admin888） |

「インストール開始」をクリックすると、テーブル作成、権限データのシード、管理者アカウント作成が自動で行われ、`.env` にデータベース設定が書き込まれます。

> インストール完了後に `runtime/install.lock` ロックファイルが生成されます。再インストールする場合はこのファイルを削除してください。

### 4. ログイン

`http://localhost:8787` にアクセスし、インストール時に設定した管理者アカウントでログインします。

### 5. フロントエンドの起動（任意）

**Flutter 管理バックエンド（Web）:**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**HarmonyOS クライアント（スマホ）:**

DevEco Studio で `apps/harmonyos/` ディレクトリを開き、実機またはエミュレーターを接続して実行します。

### 6. Docker Compose ワンクリックデプロイ（本番環境に推奨）

プロジェクトは 5 つのサービス（Nginx、PHP (webman app)、MySQL、Redis、Elasticsearch）を含む完全な Docker オーケストレーションを提供します。

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

- `Dockerfile`: PHP 8.3 + OPcache + Composer、`php:8.3-cli` ベース
- `docker-compose.yml`: 5 サービス編成、ネットワーク分離、データボリューム永続化
- `.env.docker`: Docker 環境専用の環境変数


## データベース規約

- **テーブルプレフィックス**: `erik_`
- **主キー**: すべてのテーブルの主キーは `id BIGINT UNSIGNED NOT NULL`、**AUTO_INCREMENT 禁止**
- **ID 生成**: 主キー ID はアプリケーションレイヤーの `SnowflakeService::generate()` で生成、分散環境で一意
- **必須フィールド**: 各テーブルに `id`、`created_at`、`updated_at` を含める必要があります
- **ソフトデリート**: ソフトデリートが必要なテーブルは `deleted_at DATETIME DEFAULT NULL` を追加
- **機密フィールド**: 携帯番号、メールアドレス、身分証番号などは `encryptable` プラグインで自動暗号化・復号化し、データベースフィールドは `VARCHAR(500)` で暗号文を保存

## API リファレンス

完全な API 仕様（統一レスポンス形式、業務エラーコード、ID 処理、API バージョン、レート制限、ミドルウェアアーキテクチャ、認証と認証コードのフロー）および全 API 一覧は、**[API リファレンスドキュメント](docs/API.md)** を参照してください。

## フロントエンドの説明

### Flutter 管理バックエンド（PC スタイル）

- **レイアウト**: サイドバー（64px/240px に折りたたみ可能）+ トップバー + コンテンツ領域、レスポンシブ 3 ブレークポイント（スマホ/タブレット/デスクトップ）
- **ページ**: ログイン、ダッシュボード、ユーザー管理、ロール権限、システム設定、操作ログ、プロフィール
- **状態管理**: GetX（`ApiService` シングルトン + `AuthService` による Token 永続化）
- **ダッシュボード**: 統計カード、トレンド折れ線グラフ（fl_chart）、円グラフ、最近の操作ログ
- **エクスポート**: Excel/PDF エクスポート、PDF には削除不可の著作権情報が含まれます
- **一括操作**: 複数選択の一括削除、一括有効化/無効化
- **テーマ**: Material 3 ライト/ダークの 2 テーマ

### HarmonyOS モバイル

- **ページ**: ログイン、ダッシュボード、ユーザー一覧/詳細、プロフィール
- **認証**: JWT Bearer + 401 時に Token を自動無感更新、更新失敗時はログインページへ自動リダイレクト
- **保存**: Token は AppStorage で管理

## 開発規約

- グローバル関数/クラス参照の前にバックスラッシュを付けず、`use` インポートを統一使用
- すべての PHP ファイルの先頭に著作権表示を含める必要があります
- すべての設定ファイルに中国語のコメント説明を含める必要があります
- データベースの主キーはアプリケーションレイヤーの snowflake で生成し、オートインクリメントを禁止
- API レイヤーのすべてのパラメータとレスポンスの ID は hashids で暗号化・復号化
- AdminPermission ミドルウェアは Redis でユーザー権限をキャッシュ（TTL=60s）、N+1 クエリのボトルネックを解消

## デプロイ

### Docker Compose（推奨）

プロジェクトルートに 5 つのサービスを編成する `docker-compose.yml` を提供：

| サービス | イメージ | ポート |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | ローカル `Dockerfile` で構築 | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

PHP イメージは `Dockerfile` で構築、ベースイメージは `php:8.3-cli`、OPcache を有効化。

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

GitHub Actions 継続的インテグレーションパイプライン：`.github/workflows/ci.yml`

- PHP 構文チェック（`php -l`）
- PHPUnit ユニットテスト
- Flutter 静的解析（`flutter analyze`）

### データベースバックアップ

`database/backup/` ディレクトリ：

- `backup.sh` — mysqldump + gzip バックアップ、30 日前の古いバックアップを自動クリーンアップ
- `restore.sh` — 対話式復元、利用可能なバックアップを一覧表示して選択

### Nginx セキュリティ設定

本番デプロイでは `docs/nginx-security.conf` を参照してリバースプロキシのセキュリティ強化を設定してください。

## オープンソースは大変です — ご支援歓迎

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
