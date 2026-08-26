# M0 基盤実装計画

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **エージェント型ワーカー向け:** 必須サブスキル: superpowers:subagent-driven-development（推奨）または superpowers:executing-plans を使用して、この計画をタスク単位で実装してください。手順はチェックボックス（`- [ ]`）構文で追跡します。

**目標:** monorepo の骨組み、gRPC 契約と三端スタブ生成パイプライン、四サブシステムの実行可能な骨組み、CI 全緑を確立し、service→infrastructure のエンドツーエンド gRPC 死活確認を通します。

**アーキテクチャ:** 最上位ディレクトリ contracts/（proto 契約、唯一の生成入口）→ buf が PHP スタブ（service、admin）と Rust スタブ（infrastructure）を生成；service（webman v2）が gRPC クライアント、infrastructure（bee-rust + tonic）が gRPC サーバー；三端ネイティブ工程（iOS/Android/HarmonyOS）をそれぞれ初期化し、OpenAPI でクライアントを生成；GitHub Actions マトリックス CI。

**技術スタック:** PHP 8.3+ / webman v2 / grpc 拡張 / buf / protobuf / Rust（tonic + prost、bee-rust workspace）/ xcodegen / Gradle(Android) / hvigor(HarmonyOS) / GitHub Actions

**チーム分業（設計ドキュメント §16 に対応、M0 編成）：**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead`（技術責任者）：T10 統合の取りまとめ

---

### タスク 1: リポジトリ規約とルート README

**ファイル:**
- 作成: `README.md`
- 変更: `.gitignore`

- [ ] **ステップ 1: .gitignore のカバレッジ確認**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: 各行にマッチがあること。欠けている場合は追加（PHP `vendor/`、Rust `target/`、Android `build/`、Node `node_modules/`、`.env`）。

- [ ] **ステップ 2: ルート README.md を作成**

```markdown
# Social Platform

多语言社交平台 monorepo：图文社区 + IM + 直播/语音 + 虚拟经济。

| 目录 | 说明 | 技术 |
|------|------|------|
| contracts/ | gRPC 契约（proto，buf 生成入口） | protobuf / buf |
| service/ | 用户端业务服务 | webman v2 (PHP 8.3) |
| admin/ | 管理后台（open-admin 改造） | webman v2 + Flutter |
| infrastructure/ | 高吞吐计算层 | bee-rust (tonic) |
| media/ | 自建媒体层（mediasoup/SRS/coturn） | Node.js（M4/M5 启用） |
| apps/ios, apps/android, apps/harmonyos | 原生客户端 | SwiftUI / Kotlin+Compose / ArkTS |

完整设计见 `docs/superpowers/specs/2026-08-16-social-platform-design.md`。
```

- [ ] **ステップ 3: コミット**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### タスク 2: contracts gRPC 契約定義

**ファイル:**
- 作成: `contracts/buf.yaml`
- 作成: `contracts/common/types.proto`
- 作成: `contracts/infra/infra_service.proto`
- 作成: `contracts/user/user_service.proto`
- 作成: `contracts/admin/admin_service.proto`

- [ ] **ステップ 1: buf.yaml を記述**

```yaml
version: v2
modules:
  - path: .
lint:
  use:
    - STANDARD
breaking:
  use:
    - FILE
```

- [ ] **ステップ 2: 共通型を記述（Ping/Pong エンドツーエンド死活確認用）**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **ステップ 3: 三つのサービス契約を記述**

`contracts/infra/infra_service.proto`:
```proto
syntax = "proto3";
package social.infra.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service InfraService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/user/user_service.proto`（service の対外サービス、admin が呼び出し；M0 は死活確認スタブのみ）:
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto`（admin の対外サービス；M0 は死活確認スタブのみ）:
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **ステップ 4: 検証**

```bash
cd contracts && buf lint && buf build
```
Expected: 出力エラーなし、exit 0。buf が未インストールの場合：`go install github.com/bufbuild/buf/cmd/buf@latest` または `brew install buf`。

- [ ] **ステップ 5: コミット**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### タスク 3: スタブ生成パイプライン + PHP gRPC 準備

**ファイル:**
- 作成: `scripts/gen-contracts.sh`
- 作成: `service/README.grpcs.md`（grpc 拡張のインストール手順）

- [ ] **ステップ 1: 生成スクリプトを記述**

`scripts/gen-contracts.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../contracts"
buf generate \
  --template <(cat <<'TPL'
version: v2
plugins:
  - remote: buf.build/protocolbuffers/php
    out: ../service/generated
  - remote: buf.build/grpc/php
    out: ../service/generated
  - remote: buf.build/protocolbuffers/php
    out: ../admin/generated
  - remote: buf.build/grpc/php
    out: ../admin/generated
TPL
)
echo "PHP stubs generated (service/generated, admin/generated)"
```

- [ ] **ステップ 2: 生成して検証**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: `Social/Infra/V1/InfraServiceClient.php`、`Social/Common/V1/Pong.php` などのスタブファイルが存在すること。

- [ ] **ステップ 3: PHP gRPC 依存関係を準備（service と admin それぞれで実行）**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **ステップ 4: コミット**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### タスク 4: infrastructure tonic gRPC サービス骨組み

**ファイル:**
- 作成: `infrastructure/crates/social_grpc/Cargo.toml`
- 作成: `infrastructure/crates/social_grpc/build.rs`
- 作成: `infrastructure/crates/social_grpc/src/main.rs`
- Modify: `infrastructure/Cargo.toml`（workspace members 加 `"crates/social_grpc"`）

- [ ] **ステップ 1: crate を作成**

`infrastructure/crates/social_grpc/Cargo.toml`:
```toml
[package]
name = "social_grpc"
version = "0.1.0"
edition = "2024"

[dependencies]
tokio = { workspace = true }
tonic = "0.12"
prost = "0.13"

[build-dependencies]
tonic-build = "0.12"
```

- [ ] **ステップ 2: build.rs で契約をコンパイル**

`infrastructure/crates/social_grpc/build.rs`:
```rust
fn main() -> Result<(), Box<dyn std::error::Error>> {
    tonic_build::configure()
        .compile_protos(
            &[
                "../../../contracts/infra/infra_service.proto",
                "../../../contracts/common/types.proto",
            ],
            &["../../../contracts"],
        )?;
    Ok(())
}
```

- [ ] **ステップ 3: Ping サーバー側を実装**

`infrastructure/crates/social_grpc/src/main.rs`:
```rust
pub mod social {
    tonic::include_proto!("social");
}
use social::infra::v1::{infra_service_server::{InfraService, InfraServiceServer}, PingRequest};
use social::common::v1::Pong;
use tonic::{Request, Response, Status};

#[derive(Default)]
pub struct InfraSvc;

#[tonic::async_trait]
impl InfraService for InfraSvc {
    async fn ping(&self, req: Request<PingRequest>) -> Result<Response<Pong>, Status> {
        Ok(Response::new(Pong { message: format!("pong from {}", req.get_ref().client) }))
    }
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let addr = "[::1]:50051".parse()?;
    println!("infra gRPC listening on {addr}");
    Server::builder()
        .add_service(InfraServiceServer::new(InfraSvc))
        .serve(addr)
        .await?;
    Ok(())
}
```

- [ ] **ステップ 4: workspace に追加してビルド**

`infrastructure/Cargo.toml` members 追加 `"crates/social_grpc"`。

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: ビルド成功、エラーなし。

- [ ] **ステップ 5: コミット**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### タスク 5: service webman 骨組み + gRPC 死活確認クライアント

**ファイル:**
- 作成: `service/`（composer で webman 工程を生成）
- 作成: `service/app/controller/HealthController.php`
- 作成: `service/scripts/probe_ping.php`
- 変更: `service/config/route.php`

- [ ] **ステップ 1: webman 工程を生成**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: `service/app/`、`service/config/`、`service/vendor/`、`service/start.php` が生成されること。

- [ ] **ステップ 2: ヘルスチェックルート**

`service/app/controller/HealthController.php`:
```php
<?php
namespace app\controller;

use support\Response;

class HealthController
{
    public function index(): Response
    {
        return json(['status' => 'ok', 'service' => 'social-service']);
    }
}
```

`service/config/route.php` に追加：
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **ステップ 3: gRPC 死活確認スクリプト**

`service/scripts/probe_ping.php`:
```php
<?php
require __DIR__ . '/../vendor/autoload.php';

$client = new Social\Infra\V1\InfraServiceClient(
    '127.0.0.1:50051',
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);
$req = new Social\Infra\V1\PingRequest(['client' => 'service']);
[$reply, $status] = $client->Ping($req)->wait();
if ($status->code !== 0) {
    fwrite(STDERR, "gRPC error: {$status->code} {$status->details}\n");
    exit(1);
}
echo $reply->getMessage(), PHP_EOL;
```

- [ ] **ステップ 4: ローカルで検証**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: `/health` 返回 `{"status":"ok","service":"social-service"}`；探活输出 `pong from service`。

- [ ] **ステップ 5: コミット**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### タスク 6: admin ベースライン受け入れ

**ファイル:**
- 作成: `docs/ADMIN_BASELINE.md`

- [ ] **ステップ 1: 依存関係と設定**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor が準備済み。.env はローカルの MySQL/Redis に合わせて設定（バージョン管理内のサンプルファイルは変更しない）。

- [ ] **ステップ 2: マイグレーションとテスト**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: 既存の open-admin テストスイートが全緑（工程にテスト入口がない場合は、ベースラインドキュメントに記録）。

- [ ] **ステップ 3: ベースラインドキュメントを記述**

`docs/ADMIN_BASELINE.md`：admin の現在バージョン、実行可能状態、有効化済みモジュール（JWT/RBAC/監査/ファイル/i18n）、grpc 拡張の準備状態、今後の改修入口（設計ドキュメント §3.4 の八項目の新規追加に対応）を記録。

- [ ] **ステップ 4: コミット**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### タスク 7: iOS 工程の初期化

> 本機は Linux のため Xcode 工程をビルド不可；ソースコード + xcodegen 設定を出力し、ビルド検証は macOS CI で実施（T10 に job を予約、本タスクはブロックしない）。

**ファイル:**
- 作成: `apps/ios/project.yml`（xcodegen）
- 作成: `apps/ios/SocialApp/SocialAppApp.swift`
- 作成: `apps/ios/SocialApp/APIClient.swift`
- 作成: `apps/ios/SocialApp/ContentView.swift`
- 作成: `apps/ios/openapi-config.json`

- [ ] **ステップ 1: xcodegen 設定**

`apps/ios/project.yml`:
```yaml
name: SocialApp
options:
  bundleIdPrefix: com.social
targets:
  SocialApp:
    type: application
    platform: iOS
    deploymentTarget: "16.0"
    sources: [SocialApp]
    settings:
      base:
        GENERATE_INFOPLIST_FILE: YES
```

- [ ] **ステップ 2: SwiftUI 骨組み**

`apps/ios/SocialApp/SocialAppApp.swift`:
```swift
import SwiftUI

@main
struct SocialAppApp: App {
    var body: some Scene { WindowGroup { ContentView() } }
}
```

`apps/ios/SocialApp/ContentView.swift`:
```swift
import SwiftUI

struct ContentView: View {
    @State private var health = "checking…"
    var body: some View {
        VStack(spacing: 16) {
            Text("Social").font(.largeTitle)
            Text(health).font(.caption)
        }
        .task { health = await APIClient.shared.health() }
    }
}
```

`apps/ios/SocialApp/APIClient.swift`（ネットワーク層の骨組み、M1 で OpenAPI 生成クライアントを接続）:
```swift
import Foundation

struct APIClient {
    static let shared = APIClient()
    private let base = URL(string: "http://127.0.0.1:8787")!

    func health() async -> String {
        do {
            let (_, resp) = try await URLSession.shared.data(from: base.appendingPathComponent("health"))
            return (resp as? HTTPURLResponse)?.statusCode == 200 ? "ok" : "fail"
        } catch { return "error" }
    }
}
```

- [ ] **ステップ 3: OpenAPI 生成設定**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **ステップ 4: 検証（Linux の上限）**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: 本機で工程を生成するか、スキップ理由を明記する。

- [ ] **ステップ 5: コミット**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### タスク 8: Android 工程の初期化

**ファイル:**
- 作成: `apps/android/settings.gradle.kts`
- 作成: `apps/android/build.gradle.kts`
- 作成: `apps/android/gradle.properties`
- 作成: `apps/android/app/build.gradle.kts`
- 作成: `apps/android/app/src/main/AndroidManifest.xml`
- 作成: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- 作成: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- 作成: `apps/android/openapi-config.json`

- [ ] **ステップ 1: Gradle 骨組み**

`apps/android/settings.gradle.kts`:
```kotlin
pluginManagement { repositories { google(); mavenCentral(); gradlePluginPortal() } }
dependencyResolutionManagement { repositories { google(); mavenCentral() } }
rootProject.name = "SocialApp"
include(":app")
```

`apps/android/build.gradle.kts`:
```kotlin
plugins {
    id("com.android.application") version "8.5.2" apply false
    id("org.jetbrains.kotlin.android") version "2.0.20" apply false
}
```

`apps/android/app/build.gradle.kts`:
```kotlin
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}
android {
    namespace = "com.social.app"
    compileSdk = 35
    defaultConfig {
        applicationId = "com.social.app"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"
    }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    kotlinOptions { jvmTarget = "17" }
}
dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
}
```

`apps/android/gradle.properties`:
```
org.gradle.jvmargs=-Xmx2g
android.useAndroidX=true
```

- [ ] **ステップ 2: エントリとネットワーク層**

`apps/android/app/src/main/AndroidManifest.xml`:
```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <uses-permission android:name="android.permission.INTERNET" />
    <application android:label="Social" android:theme="@android:style/Theme.Material.Light">
        <activity android:name=".MainActivity" android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>
    </application>
</manifest>
```

`apps/android/app/src/main/java/com/social/app/MainActivity.kt`:
```kotlin
package com.social.app

import android.os.Bundle
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val tv = TextView(this).apply { text = "checking…"; textSize = 24f }
        setContentView(tv)
        CoroutineScope(Dispatchers.IO).launch {
            val health = APIClient.health()
            runOnUiThread { tv.text = health }
        }
    }
}
```

`apps/android/app/src/main/java/com/social/app/APIClient.kt`:
```kotlin
package com.social.app

import java.net.HttpURLConnection
import java.net.URL
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

object APIClient {
    suspend fun health(): String = withContext(Dispatchers.IO) {
        try {
            val conn = URL("http://10.0.2.2:8787/health").openConnection() as HttpURLConnection
            if (conn.responseCode == 200) "ok" else "fail:${conn.responseCode}"
        } catch (e: Exception) { "error" }
    }
}
```

- [ ] **ステップ 3: OpenAPI 生成設定**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **ステップ 4: ビルド検証（Android SDK が必要）**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL、`app/build/outputs/apk/debug/app-debug.apk` を生成。本機に SDK がない場合：環境要件を記録し、CI で検証。

- [ ] **ステップ 5: コミット**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### タスク 9: HarmonyOS 工程の初期化

> 本機に DevEco CLI がない場合は工程構造 + 環境要件の記録を出力し、ビルド検証は後続の CI/DevEco 環境で実施。

**ファイル:**
- 作成: `apps/harmonyos/build-profile.json5`
- 作成: `apps/harmonyos/oh-package.json5`
- 作成: `apps/harmonyos/hvigorfile.ts`
- 作成: `apps/harmonyos/AppScope/app.json5`
- 作成: `apps/harmonyos/entry/src/main/module.json5`
- 作成: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- 作成: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- 作成: `apps/harmonyos/openapi-config.json`

- [ ] **ステップ 1: 工程骨組み（API 12+、Stage モデル準拠）**

`apps/harmonyos/oh-package.json5`:
```json5
{
  "modelVersion": "5.0.0",
  "name": "social-app",
  "version": "0.1.0",
  "dependencies": {},
  "devDependencies": { "@ohos/hypium": "1.0.19" }
}
```

`apps/harmonyos/build-profile.json5`:
```json5
{
  "app": {
    "signingConfigs": [],
    "products": [{ "name": "default", "compatibleSdkVersion": "5.0.0(12)", "runtimeOS": "HarmonyOS" }]
  },
  "modules": [{ "name": "entry", "srcPath": "./entry", "targets": [{ "name": "default" }] }]
}
```

`apps/harmonyos/entry/src/main/module.json5`:
```json5
{
  "module": {
    "name": "entry",
    "type": "entry",
    "deviceTypes": ["phone"],
    "pages": "$profile:main_pages",
    "abilities": [{ "name": "EntryAbility", "srcEntry": "./ets/entryability/EntryAbility.ets", "skills": [{ "entities": ["entity.system.home"], "actions": ["action.system.home"] }] }]
  }
}
```

- [ ] **ステップ 2: エントリとページ**

`apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`:
```ts
import { AbilityConstant, UIAbility, Want } from '@kit.AbilityKit';
import { hilog } from '@kit.PerformanceAnalysisKit';
import { window } from '@kit.ArkUI';

export default class EntryAbility extends UIAbility {
  onCreate(want: Want, launchParam: AbilityConstant.LaunchParam): void {
    hilog.info(0x0000, 'SocialApp', 'onCreate');
  }
  onWindowStageCreate(windowStage: window.WindowStage): void {
    windowStage.loadContent('pages/Index');
  }
}
```

`apps/harmonyos/entry/src/main/ets/pages/Index.ets`:
```ts
import { http } from '@kit.NetworkKit';

@Entry
@Component
struct Index {
  @State health: string = 'checking…';

  aboutToAppear(): void {
    const req = http.createHttp();
    req.request('http://127.0.0.1:8787/health', (err, data) => {
      this.health = err ? 'error' : (data.responseCode === 200 ? 'ok' : `fail:${data.responseCode}`);
      req.destroy();
    });
  }

  build() {
    Column({ space: 16 }) {
      Text('Social').fontSize(32)
      Text(this.health).fontSize(14)
    }.width('100%').height('100%').justifyContent(FlexAlign.Center)
  }
}
```

- [ ] **ステップ 3: OpenAPI 生成設定**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **ステップ 4: 検証（環境の上限）**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **ステップ 5: コミット**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### タスク 10: エンドツーエンド死活確認 + CI 全緑（lead 統合）

**ファイル:**
- 作成: `.github/workflows/ci.yml`
- 作成: `scripts/ci-probe.sh`

- [ ] **ステップ 1: CI マトリックス**

`.github/workflows/ci.yml`:
```yaml
name: CI
on: [push, pull_request]
jobs:
  php-service:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.3", extensions: grpc, coverage: none }
      - run: cd service && composer install --no-interaction
      - run: cd service && php -r 'require "vendor/autoload.php"; echo "deps ok\n";'
  php-admin:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.3", extensions: grpc, coverage: none }
      - run: cd admin && composer install --no-interaction
  rust-infra:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: dtolnay/rust-toolchain@stable
      - run: cd infrastructure && cargo build --workspace
      - run: cd infrastructure && cargo test --workspace
  android:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-java@v4
        with: { distribution: temurin, java-version: "17" }
      - uses: android-actions/setup-android@v3
      - run: cd apps/android && ./gradlew assembleDebug
  # ios / harmonyos：macOS / DevEco 环境就绪后启用
```

- [ ] **ステップ 2: 統合死活確認スクリプト**

`scripts/ci-probe.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
cd /home/wwwroot/social
(cd infrastructure && cargo run -p social_grpc) &
INFRA_PID=$!
sleep 5
(cd service && php start.php start) &
SERVICE_PID=$!
trap 'kill $INFRA_PID $SERVICE_PID 2>/dev/null || true' EXIT
sleep 3
curl -sf http://127.0.0.1:8787/health | grep -q '"ok"' || { echo "health check failed"; exit 1; }
out=$(cd service && php scripts/probe_ping.php)
[[ "$out" == "pong from service" ]] || { echo "gRPC probe failed: $out"; exit 1; }
echo "E2E OK"
```

- [ ] **ステップ 3: ローカルでエンドツーエンドを実行**

```bash
bash scripts/ci-probe.sh
```
Expected: `E2E OK` が出力される（health + gRPC ping いずれも成功）。

- [ ] **ステップ 4: コミット**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## セルフレビュー記録

- カバレッジ：契約（T2/T3）→ 死活確認（T4/T5/T10）、三端初期化（T7/T8/T9）、admin ベースライン（T6）、CI（T10）——設計ドキュメント M0 の全内容に対応
- プレースホルダー：なし（全ステップに実コマンドとコードを含む）
- 型の一致：PingRequest/Pong の三端スタブが一致；`pong from {client}` のアサーションを T5/T10 で統一
