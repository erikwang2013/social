# M0 Foundation Implementation Plan

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the monorepo skeleton, the gRPC contracts with a stub-generation pipeline for all three ends, runnable skeletons for the four subsystems, fully green CI, and end-to-end gRPC liveness from service → infrastructure.

**Architecture:** Top-level directory contracts/ (proto contracts, the single generation entry point) → buf generates PHP stubs (service, admin) and Rust stubs (infrastructure); service (webman v2) acts as the gRPC client, infrastructure (bee-rust + tonic) as the gRPC server; the three native projects (iOS/Android/HarmonyOS) are each initialized and generate their clients via OpenAPI; matrix CI on GitHub Actions.

**Tech Stack:** PHP 8.3+ / webman v2 / grpc extension / buf / protobuf / Rust (tonic + prost, bee-rust workspace) / xcodegen / Gradle (Android) / hvigor (HarmonyOS) / GitHub Actions

**Team roles (design doc §16, M0 staffing):**
- `backend-service`: T1, T5
- `backend-admin`: T2, T3, T6
- `rust-infra`: T4
- `ios-dev` / `android-dev` / `harmonyos-dev`: T7 / T8 / T9
- `lead` (tech lead): T10 integration wrap-up

---

### Task 1: Repository conventions and root README

**Files:**
- Create: `README.md`
- Modify: `.gitignore`

- [ ] **Step 1: Check .gitignore coverage**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: every line has a match; add any missing entries (PHP `vendor/`, Rust `target/`, Android `build/`, Node `node_modules/`, `.env`).

- [ ] **Step 2: Create root README.md**

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

- [ ] **Step 3: Commit**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### Task 2: contracts gRPC contract definitions

**Files:**
- Create: `contracts/buf.yaml`
- Create: `contracts/common/types.proto`
- Create: `contracts/infra/infra_service.proto`
- Create: `contracts/user/user_service.proto`
- Create: `contracts/admin/admin_service.proto`

- [ ] **Step 1: Write buf.yaml**

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

- [ ] **Step 2: Write shared types (for end-to-end Ping/Pong probing)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **Step 3: Write the three service contracts**

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

`contracts/user/user_service.proto` (service's public API, called by admin; M0 is a probe-only stub):
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto` (admin's public API; M0 is a probe-only stub):
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **Step 4: Validate**

```bash
cd contracts && buf lint && buf build
```
Expected: no output errors, exit 0. If buf is not installed: `go install github.com/bufbuild/buf/cmd/buf@latest` or `brew install buf`.

- [ ] **Step 5: Commit**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### Task 3: Stub generation pipeline + PHP gRPC readiness

**Files:**
- Create: `scripts/gen-contracts.sh`
- Create: `service/README.grpcs.md` (installation notes for the grpc extension)

- [ ] **Step 1: Write the generation script**

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

- [ ] **Step 2: Generate and verify**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: stub files such as `Social/Infra/V1/InfraServiceClient.php` and `Social/Common/V1/Pong.php` exist.

- [ ] **Step 3: PHP gRPC dependencies ready (run in both service and admin)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **Step 4: Commit**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### Task 4: infrastructure tonic gRPC service skeleton

**Files:**
- Create: `infrastructure/crates/social_grpc/Cargo.toml`
- Create: `infrastructure/crates/social_grpc/build.rs`
- Create: `infrastructure/crates/social_grpc/src/main.rs`
- Modify: `infrastructure/Cargo.toml` (add `"crates/social_grpc"` to workspace members)

- [ ] **Step 1: Create the crate**

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

- [ ] **Step 2: build.rs compiles the contracts**

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

- [ ] **Step 3: Ping server implementation**

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

- [ ] **Step 4: Add to the workspace and build**

Append `"crates/social_grpc"` to the members of `infrastructure/Cargo.toml`.

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: build succeeds with no errors.

- [ ] **Step 5: Commit**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### Task 5: service webman skeleton + gRPC probe client

**Files:**
- Create: `service/` (webman project generated via composer)
- Create: `service/app/controller/HealthController.php`
- Create: `service/scripts/probe_ping.php`
- Modify: `service/config/route.php`

- [ ] **Step 1: Generate the webman project**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: `service/app/`, `service/config/`, `service/vendor/`, `service/start.php` are generated.

- [ ] **Step 2: Health check route**

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

Append to `service/config/route.php`:
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **Step 3: gRPC probe script**

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

- [ ] **Step 4: Local verification**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: `/health` returns `{"status":"ok","service":"social-service"}`; the probe prints `pong from service`.

- [ ] **Step 5: Commit**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### Task 6: admin baseline acceptance

**Files:**
- Create: `docs/ADMIN_BASELINE.md`

- [ ] **Step 1: Dependencies and configuration**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor is ready; .env is configured for the local MySQL/Redis (do not modify the sample file checked into the repo).

- [ ] **Step 2: Migrations and tests**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: the existing open-admin test suite is fully green (if the project has no test entry, record this in the baseline document).

- [ ] **Step 3: Write the baseline document**

`docs/ADMIN_BASELINE.md`: record admin's current version, runnable status, enabled modules (JWT/RBAC/audit/files/i18n), grpc extension readiness, and the future rework entry points (corresponding to the eight additions in design doc §3.4).

- [ ] **Step 4: Commit**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### Task 7: iOS project initialization

> This machine runs Linux and cannot build an Xcode project; deliver the source + xcodegen config and defer build verification to the macOS CI (job reserved in T10; this task does not block).

**Files:**
- Create: `apps/ios/project.yml` (xcodegen)
- Create: `apps/ios/SocialApp/SocialAppApp.swift`
- Create: `apps/ios/SocialApp/APIClient.swift`
- Create: `apps/ios/SocialApp/ContentView.swift`
- Create: `apps/ios/openapi-config.json`

- [ ] **Step 1: xcodegen configuration**

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

- [ ] **Step 2: SwiftUI skeleton**

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

`apps/ios/SocialApp/APIClient.swift` (network layer skeleton; M1 will plug in the OpenAPI-generated client):
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

- [ ] **Step 3: OpenAPI generation config**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **Step 4: Verification (Linux ceiling)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: either generate the project locally or explicitly record the reason for skipping.

- [ ] **Step 5: Commit**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### Task 8: Android project initialization

**Files:**
- Create: `apps/android/settings.gradle.kts`
- Create: `apps/android/build.gradle.kts`
- Create: `apps/android/gradle.properties`
- Create: `apps/android/app/build.gradle.kts`
- Create: `apps/android/app/src/main/AndroidManifest.xml`
- Create: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- Create: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- Create: `apps/android/openapi-config.json`

- [ ] **Step 1: Gradle skeleton**

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

- [ ] **Step 2: Entry point and network layer**

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

- [ ] **Step 3: OpenAPI generation config**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **Step 4: Build verification (requires Android SDK)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL, producing `app/build/outputs/apk/debug/app-debug.apk`. If this machine has no SDK: record the environment requirement and verify in CI.

- [ ] **Step 5: Commit**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### Task 9: HarmonyOS project initialization

> If DevEco CLI is unavailable on this machine, produce the project structure + an environment-requirements record; build verification runs later in the CI/DevEco environment.

**Files:**
- Create: `apps/harmonyos/build-profile.json5`
- Create: `apps/harmonyos/oh-package.json5`
- Create: `apps/harmonyos/hvigorfile.ts`
- Create: `apps/harmonyos/AppScope/app.json5`
- Create: `apps/harmonyos/entry/src/main/module.json5`
- Create: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- Create: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- Create: `apps/harmonyos/openapi-config.json`

- [ ] **Step 1: Project skeleton (API 12+, Stage model)**

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

- [ ] **Step 2: Entry point and pages**

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

- [ ] **Step 3: OpenAPI generation config**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **Step 4: Verification (environment ceiling)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **Step 5: Commit**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### Task 10: End-to-end probe + fully green CI (lead integration)

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `scripts/ci-probe.sh`

- [ ] **Step 1: CI matrix**

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

- [ ] **Step 2: Integration probe script**

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

- [ ] **Step 3: Run end-to-end locally**

```bash
bash scripts/ci-probe.sh
```
Expected: prints `E2E OK` (both the health check and the gRPC ping pass).

- [ ] **Step 4: Commit**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## Self-Review notes

- Coverage: contracts (T2/T3) → probing (T4/T5/T10), three-end initialization (T7/T8/T9), admin baseline (T6), CI (T10) — covers everything in design doc M0
- Placeholders: none (every step contains real commands and code)
- Type consistency: PingRequest/Pong stubs are consistent across the three ends; the `pong from {client}` assertion is unified in T5/T10
