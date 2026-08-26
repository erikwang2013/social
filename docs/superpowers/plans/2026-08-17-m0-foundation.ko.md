# M0 기반 구현 계획

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **에이전트 작업자용:** 필수 하위 스킬: superpowers:subagent-driven-development(권장) 또는 superpowers:executing-plans를 사용해 이 계획을 작업 단위로 구현하세요. 단계는 체크박스(`- [ ]`) 문법으로 추적합니다.

**목표:** 모노레포 골격, gRPC 계약과 3개 엔드 스텁 생성 파이프라인, 4개 하위 시스템의 실행 가능한 골격, CI 전체 통과, 그리고 service→infrastructure 엔드투엔드 gRPC 활성 검사 연결.

**아키텍처:** 최상위 디렉터리 contracts/(proto 계약, 유일한 생성 진입점) → buf가 PHP 스텁(service, admin)과 Rust 스텁(infrastructure)을 생성. service(webman v2)는 gRPC 클라이언트, infrastructure(bee-rust + tonic)는 gRPC 서버. 3개 네이티브 프로젝트(iOS/Android/HarmonyOS)는 각각 초기화하고 OpenAPI로 클라이언트를 생성. GitHub Actions 매트릭스 CI.

**기술 스택:** PHP 8.3+ / webman v2 / grpc 확장 / buf / protobuf / Rust(tonic + prost, bee-rust workspace) / xcodegen / Gradle(Android) / hvigor(HarmonyOS) / GitHub Actions

**팀 분담(설계 문서 §16, M0 편성):**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead`(기술 책임자): T10 통합 마무리

---

### Task 1: 저장소 규칙과 루트 README

**파일:**
- 생성: `README.md`
- 수정: `.gitignore`

- [ ] **Step 1: .gitignore 커버리지 확인**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: 모든 줄이 매치되어야 함. 없는 항목은 추가(PHP `vendor/`, Rust `target/`, Android `build/`, Node `node_modules/`, `.env`).

- [ ] **Step 2: 루트 README.md 생성**

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

- [ ] **Step 3: 커밋**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### Task 2: contracts gRPC 계약 정의

**파일:**
- 생성: `contracts/buf.yaml`
- 생성: `contracts/common/types.proto`
- 생성: `contracts/infra/infra_service.proto`
- 생성: `contracts/user/user_service.proto`
- 생성: `contracts/admin/admin_service.proto`

- [ ] **Step 1: buf.yaml 작성**

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

- [ ] **Step 2: 공용 타입 작성(엔드투엔드 Ping/Pong 활성 검사용)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **Step 3: 서비스 계약 3개 작성**

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

`contracts/user/user_service.proto`(service의 대외 서비스, admin이 호출. M0은 활성 검사 스텁만):
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto`(admin의 대외 서비스. M0은 활성 검사 스텁만):
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **Step 4: 검증**

```bash
cd contracts && buf lint && buf build
```
Expected: 출력 오류 없음, exit 0. buf가 설치되지 않았다면 `go install github.com/bufbuild/buf/cmd/buf@latest` 또는 `brew install buf`.

- [ ] **Step 5: 커밋**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### Task 3: 스텁 생성 파이프라인 + PHP gRPC 준비

**파일:**
- 생성: `scripts/gen-contracts.sh`
- 생성: `service/README.grpcs.md`(grpc 확장 설치 안내)

- [ ] **Step 1: 생성 스크립트 작성**

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

- [ ] **Step 2: 생성 및 검증**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: `Social/Infra/V1/InfraServiceClient.php`, `Social/Common/V1/Pong.php` 등 스텁 파일이 존재해야 함.

- [ ] **Step 3: PHP gRPC 의존성 준비(service와 admin 각각 실행)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **Step 4: 커밋**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### Task 4: infrastructure tonic gRPC 서비스 골격

**파일:**
- 생성: `infrastructure/crates/social_grpc/Cargo.toml`
- 생성: `infrastructure/crates/social_grpc/build.rs`
- 생성: `infrastructure/crates/social_grpc/src/main.rs`
- 수정: `infrastructure/Cargo.toml`(workspace members에 `"crates/social_grpc"` 추가)

- [ ] **Step 1: crate 생성**

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

- [ ] **Step 2: build.rs가 계약 컴파일**

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

- [ ] **Step 3: Ping 서버 구현**

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

- [ ] **Step 4: workspace에 추가하고 빌드**

`infrastructure/Cargo.toml` members에 `"crates/social_grpc"`를 추가합니다.

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: 오류 없이 빌드 성공.

- [ ] **Step 5: 커밋**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### Task 5: service webman 골격 + gRPC 활성 검사 클라이언트

**파일:**
- 생성: `service/`(composer로 webman 프로젝트 생성)
- 생성: `service/app/controller/HealthController.php`
- 생성: `service/scripts/probe_ping.php`
- 수정: `service/config/route.php`

- [ ] **Step 1: webman 프로젝트 생성**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: `service/app/`, `service/config/`, `service/vendor/`, `service/start.php`가 생성됨.

- [ ] **Step 2: 헬스 체크 라우트**

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

`service/config/route.php`에 추가:
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **Step 3: gRPC 활성 검사 스크립트**

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

- [ ] **Step 4: 로컬 검증**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: `/health`가 `{"status":"ok","service":"social-service"}` 반환. 활성 검사 출력은 `pong from service`.

- [ ] **Step 5: 커밋**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### Task 6: admin 베이스라인 검수

**파일:**
- 생성: `docs/ADMIN_BASELINE.md`

- [ ] **Step 1: 의존성과 설정**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor 준비 완료. .env는 로컬 MySQL/Redis에 맞게 설정(버전 관리의 예시 파일은 수정하지 않음).

- [ ] **Step 2: 마이그레이션과 테스트**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: 기존 open-admin 테스트 스위트 전체 통과(프로젝트에 테스트 진입점이 없으면 베이스라인 문서에 기록).

- [ ] **Step 3: 베이스라인 문서 작성**

`docs/ADMIN_BASELINE.md`: admin의 현재 버전, 실행 가능 상태, 활성화된 모듈(JWT/RBAC/감사/파일/i18n), grpc 확장 준비 상태, 향후 개조 진입점(설계 문서 §3.4의 8가지 추가 항목)을 기록.

- [ ] **Step 4: 커밋**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### Task 7: iOS 프로젝트 초기화

> 이 머신은 Linux라 Xcode 프로젝트를 빌드할 수 없음. 소스 + xcodegen 설정을 산출하고 빌드 검증은 macOS CI로 이관(T10에 job 예약, 이 작업은 블로킹하지 않음).

**파일:**
- 생성: `apps/ios/project.yml`(xcodegen)
- 생성: `apps/ios/SocialApp/SocialAppApp.swift`
- 생성: `apps/ios/SocialApp/APIClient.swift`
- 생성: `apps/ios/SocialApp/ContentView.swift`
- 생성: `apps/ios/openapi-config.json`

- [ ] **Step 1: xcodegen 설정**

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

- [ ] **Step 2: SwiftUI 골격**

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

`apps/ios/SocialApp/APIClient.swift`(네트워크 계층 골격, M1에서 OpenAPI 생성 클라이언트 연결):
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

- [ ] **Step 3: OpenAPI 생성 설정**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **Step 4: 검증(Linux 한계)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: 로컬에서 프로젝트를 생성하거나 건너뛴 사유를 명확히 기록.

- [ ] **Step 5: 커밋**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### Task 8: Android 프로젝트 초기화

**파일:**
- 생성: `apps/android/settings.gradle.kts`
- 생성: `apps/android/build.gradle.kts`
- 생성: `apps/android/gradle.properties`
- 생성: `apps/android/app/build.gradle.kts`
- 생성: `apps/android/app/src/main/AndroidManifest.xml`
- 생성: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- 생성: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- 생성: `apps/android/openapi-config.json`

- [ ] **Step 1: Gradle 골격**

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

- [ ] **Step 2: 진입점과 네트워크 계층**

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

- [ ] **Step 3: OpenAPI 생성 설정**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **Step 4: 빌드 검증(Android SDK 필요)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL, `app/build/outputs/apk/debug/app-debug.apk` 산출. 이 머신에 SDK가 없으면 환경 요건을 기록하고 CI에서 검증.

- [ ] **Step 5: 커밋**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### Task 9: HarmonyOS 프로젝트 초기화

> 이 머신에 DevEco CLI가 없으면 프로젝트 구조 + 환경 요건 기록을 산출하고, 빌드 검증은 추후 CI/DevEco 환경에서 실행.

**파일:**
- 생성: `apps/harmonyos/build-profile.json5`
- 생성: `apps/harmonyos/oh-package.json5`
- 생성: `apps/harmonyos/hvigorfile.ts`
- 생성: `apps/harmonyos/AppScope/app.json5`
- 생성: `apps/harmonyos/entry/src/main/module.json5`
- 생성: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- 생성: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- 생성: `apps/harmonyos/openapi-config.json`

- [ ] **Step 1: 프로젝트 골격(API 12+, Stage 모델)**

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

- [ ] **Step 2: 진입점과 페이지**

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

- [ ] **Step 3: OpenAPI 생성 설정**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **Step 4: 검증(환경 한계)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **Step 5: 커밋**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### Task 10: 엔드투엔드 활성 검사 + CI 전체 통과(lead 통합)

**파일:**
- 생성: `.github/workflows/ci.yml`
- 생성: `scripts/ci-probe.sh`

- [ ] **Step 1: CI 매트릭스**

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

- [ ] **Step 2: 통합 활성 검사 스크립트**

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

- [ ] **Step 3: 로컬에서 엔드투엔드 실행**

```bash
bash scripts/ci-probe.sh
```
Expected: `E2E OK` 출력(health와 gRPC ping 모두 통과).

- [ ] **Step 4: 커밋**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## 셀프 리뷰 기록

- 범위: 계약(T2/T3) → 활성 검사(T4/T5/T10), 3개 엔드 초기화(T7/T8/T9), admin 베이스라인(T6), CI(T10) — 설계 문서 M0 전체 내용에 대응
- 자리 표시자: 없음(모든 단계에 실제 명령과 코드 포함)
- 타입 일관성: PingRequest/Pong 3개 엔드 스텁 일치, `pong from {client}` 검증은 T5/T10에서 통일
