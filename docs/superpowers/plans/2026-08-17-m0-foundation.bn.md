# M0 ফাউন্ডেশন বাস্তবায়ন পরিকল্পনা

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **এজেন্টিক কর্মীদের জন্য:** আবশ্যক সাব-স্কিল: superpowers:subagent-driven-development (প্রস্তাবিত) বা superpowers:executing-plans ব্যবহার করে এই পরিকল্পনাটি কাজে-কাজে বাস্তবায়ন করুন। ধাপগুলো ট্র্যাক করতে চেকবক্স (`- [ ]`) সিনট্যাক্স ব্যবহার হয়।

**লক্ষ্য:** মনোরেপো কঙ্কাল, gRPC কন্ট্রাক্ট ও তিন প্রান্তের স্টাব জেনারেশন পাইপলাইন, চারটি সাবসিস্টেমের চলমান কঙ্কাল, সম্পূর্ণ সবুজ CI এবং service→infrastructure পর্যন্ত এন্ড-টু-এন্ড gRPC লাইভনেস পরীক্ষা স্থাপন।

**স্থাপত্য:** শীর্ষ স্তরের ডিরেক্টরি contracts/ (proto কন্ট্রাক্ট, জেনারেশনের একমাত্র প্রবেশবিন্দু) → buf PHP স্টাব (service, admin) ও Rust স্টাব (infrastructure) তৈরি করে; service (webman v2) gRPC ক্লায়েন্ট হিসেবে কাজ করে, infrastructure (bee-rust + tonic) gRPC সার্ভার হিসেবে; তিনটি নেটিভ প্রজেক্ট (iOS/Android/HarmonyOS) আলাদাভাবে ইনিশিয়ালাইজ হয় এবং OpenAPI-র মাধ্যমে ক্লায়েন্ট তৈরি করে; GitHub Actions ম্যাট্রিক্স CI।

**টেক স্ট্যাক:** PHP 8.3+ / webman v2 / grpc এক্সটেনশন / buf / protobuf / Rust (tonic + prost, bee-rust workspace) / xcodegen / Gradle (Android) / hvigor (HarmonyOS) / GitHub Actions

**টিম বিভাজন (ডিজাইন ডক §16, M0 কাঠামো):**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead` (প্রযুক্তি প্রধান): T10 ইন্টিগ্রেশন সমাপ্তি

---

### কার্য 1: রিপোজিটরি নিয়ম ও রুট README

**ফাইল:**
- তৈরি করুন: `README.md`
- পরিবর্তন করুন: `.gitignore`

- [ ] **ধাপ 1: .gitignore কভারেজ পরীক্ষা**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: প্রতিটি লাইনের মিল থাকবে; যা নেই তা যোগ করুন (PHP `vendor/`, Rust `target/`, Android `build/`, Node `node_modules/`, `.env`)।

- [ ] **ধাপ 2: রুট README.md তৈরি**

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

- [ ] **ধাপ 3: কমিট**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### কার্য 2: contracts-এ gRPC কন্ট্রাক্ট সংজ্ঞা

**ফাইল:**
- তৈরি করুন: `contracts/buf.yaml`
- তৈরি করুন: `contracts/common/types.proto`
- তৈরি করুন: `contracts/infra/infra_service.proto`
- তৈরি করুন: `contracts/user/user_service.proto`
- তৈরি করুন: `contracts/admin/admin_service.proto`

- [ ] **ধাপ 1: buf.yaml লেখা**

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

- [ ] **ধাপ 2: সাধারণ টাইপ লেখা (এন্ড-টু-এন্ড Ping/Pong লাইভনেস পরীক্ষার জন্য)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **ধাপ 3: তিনটি সার্ভিস কন্ট্রাক্ট লেখা**

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

`contracts/user/user_service.proto` (service-এর পাবলিক সার্ভিস, admin কল করে; M0-তে শুধু লাইভনেস স্টাব):
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto` (admin-এর পাবলিক সার্ভিস; M0-তে শুধু লাইভনেস স্টাব):
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **ধাপ 4: যাচাই**

```bash
cd contracts && buf lint && buf build
```
Expected: আউটপুটে কোনো ত্রুটি নেই, exit 0। buf ইনস্টল না থাকলে: `go install github.com/bufbuild/buf/cmd/buf@latest` বা `brew install buf`।

- [ ] **ধাপ 5: কমিট**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### কার্য 3: স্টাব জেনারেশন পাইপলাইন + PHP gRPC প্রস্তুতি

**ফাইল:**
- তৈরি করুন: `scripts/gen-contracts.sh`
- তৈরি করুন: `service/README.grpcs.md` (grpc এক্সটেনশন ইনস্টলের নোট)

- [ ] **ধাপ 1: জেনারেশন স্ক্রিপ্ট লেখা**

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

- [ ] **ধাপ 2: জেনারেট ও যাচাই**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: `Social/Infra/V1/InfraServiceClient.php`, `Social/Common/V1/Pong.php` ইত্যাদি স্টাব ফাইল থাকবে।

- [ ] **ধাপ 3: PHP gRPC ডিপেন্ডেন্সি প্রস্তুত (service ও admin-এ আলাদাভাবে চালান)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **ধাপ 4: কমিট**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### কার্য 4: infrastructure-এ tonic gRPC সার্ভিস কঙ্কাল

**ফাইল:**
- তৈরি করুন: `infrastructure/crates/social_grpc/Cargo.toml`
- তৈরি করুন: `infrastructure/crates/social_grpc/build.rs`
- তৈরি করুন: `infrastructure/crates/social_grpc/src/main.rs`
- পরিবর্তন করুন: `infrastructure/Cargo.toml` (workspace members-এ `"crates/social_grpc"` যোগ করুন)

- [ ] **ধাপ 1: crate তৈরি**

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

- [ ] **ধাপ 2: build.rs কন্ট্রাক্ট কম্পাইল করে**

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

- [ ] **ধাপ 3: Ping সার্ভার বাস্তবায়ন**

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

- [ ] **ধাপ 4: workspace-এ যোগ করে বিল্ড**

`infrastructure/Cargo.toml`-এর members-এ `"crates/social_grpc"` যোগ করুন।

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: বিল্ড সফল, কোনো ত্রুটি নেই।

- [ ] **ধাপ 5: কমিট**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### কার্য 5: service-এ webman কঙ্কাল + gRPC লাইভনেস প্রোব ক্লায়েন্ট

**ফাইল:**
- তৈরি করুন: `service/` (composer দিয়ে webman প্রজেক্ট)
- তৈরি করুন: `service/app/controller/HealthController.php`
- তৈরি করুন: `service/scripts/probe_ping.php`
- পরিবর্তন করুন: `service/config/route.php`

- [ ] **ধাপ 1: webman প্রজেক্ট জেনারেট**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: `service/app/`, `service/config/`, `service/vendor/`, `service/start.php` জেনারেট হবে।

- [ ] **ধাপ 2: হেলথ চেক রুট**

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

`service/config/route.php`-এ যোগ করুন:
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **ধাপ 3: gRPC লাইভনেস প্রোব স্ক্রিপ্ট**

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

- [ ] **ধাপ 4: লোকাল যাচাই**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: `/health` `{"status":"ok","service":"social-service"}` রিটার্ন করবে; প্রোব প্রিন্ট করবে `pong from service`।

- [ ] **ধাপ 5: কমিট**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### কার্য 6: admin বেসলাইন গ্রহণ

**ফাইল:**
- তৈরি করুন: `docs/ADMIN_BASELINE.md`

- [ ] **ধাপ 1: ডিপেন্ডেন্সি ও কনফিগারেশন**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor প্রস্তুত; .env লোকাল MySQL/Redis অনুযায়ী কনফিগার করুন (রিপোজিটরির নমুনা ফাইল পরিবর্তন করবেন না)।

- [ ] **ধাপ 2: মাইগ্রেশন ও টেস্ট**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: বিদ্যমান open-admin টেস্ট স্যুট সম্পূর্ণ সবুজ (প্রজেক্টে টেস্ট এন্ট্রি না থাকলে বেসলাইন ডকুমেন্টে লিখে রাখুন)।

- [ ] **ধাপ 3: বেসলাইন ডকুমেন্ট লেখা**

`docs/ADMIN_BASELINE.md`: admin-এর বর্তমান সংস্করণ, চলমান অবস্থা, সক্রিয় মডিউল (JWT/RBAC/অডিট/ফাইল/i18n), grpc এক্সটেনশনের প্রস্তুতি ও ভবিষ্যৎ রিফ্যাক্টরের প্রবেশবিন্দু লিখে রাখুন (ডিজাইন ডক §3.4-এর আটটি নতুন সংযোজনের অনুরূপ)।

- [ ] **ধাপ 4: কমিট**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### কার্য 7: iOS প্রজেক্ট ইনিশিয়ালাইজেশন

> এই মেশিনটি Linux, তাই Xcode প্রজেক্ট বিল্ড করা যাবে না; সোর্স + xcodegen কনফিগারেশন দিয়ে দিন, বিল্ড যাচাই macOS CI-তে রাখুন (T10-এ job সংরক্ষিত; এই কার্য ব্লক করে না)।

**ফাইল:**
- তৈরি করুন: `apps/ios/project.yml` (xcodegen)
- তৈরি করুন: `apps/ios/SocialApp/SocialAppApp.swift`
- তৈরি করুন: `apps/ios/SocialApp/APIClient.swift`
- তৈরি করুন: `apps/ios/SocialApp/ContentView.swift`
- তৈরি করুন: `apps/ios/openapi-config.json`

- [ ] **ধাপ 1: xcodegen কনফিগারেশন**

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

- [ ] **ধাপ 2: SwiftUI কঙ্কাল**

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

`apps/ios/SocialApp/APIClient.swift` (নেটওয়ার্ক লেয়ারের কঙ্কাল; M1-এ OpenAPI-জেনারেটেড ক্লায়েন্ট যুক্ত হবে):
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

- [ ] **ধাপ 3: OpenAPI জেনারেশন কনফিগারেশন**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **ধাপ 4: যাচাই (Linux সীমা)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: লোকালি প্রজেক্ট জেনারেট করুন বা বাদ দেওয়ার কারণ স্পষ্টভাবে লিখে রাখুন।

- [ ] **ধাপ 5: কমিট**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### কার্য 8: Android প্রজেক্ট ইনিশিয়ালাইজেশন

**ফাইল:**
- তৈরি করুন: `apps/android/settings.gradle.kts`
- তৈরি করুন: `apps/android/build.gradle.kts`
- তৈরি করুন: `apps/android/gradle.properties`
- তৈরি করুন: `apps/android/app/build.gradle.kts`
- তৈরি করুন: `apps/android/app/src/main/AndroidManifest.xml`
- তৈরি করুন: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- তৈরি করুন: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- তৈরি করুন: `apps/android/openapi-config.json`

- [ ] **ধাপ 1: Gradle কঙ্কাল**

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

- [ ] **ধাপ 2: এন্ট্রি পয়েন্ট ও নেটওয়ার্ক লেয়ার**

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

- [ ] **ধাপ 3: OpenAPI জেনারেশন কনফিগারেশন**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **ধাপ 4: বিল্ড যাচাই (Android SDK প্রয়োজন)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL, `app/build/outputs/apk/debug/app-debug.apk` তৈরি হবে। এই মেশিনে SDK না থাকলে: পরিবেশের প্রয়োজনীয়তা লিখে রাখুন, CI-তে যাচাই করুন।

- [ ] **ধাপ 5: কমিট**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### কার্য 9: HarmonyOS প্রজেক্ট ইনিশিয়ালাইজেশন

> এই মেশিনে DevEco CLI না থাকলে প্রজেক্ট কাঠামো + পরিবেশ প্রয়োজনীয়তার রেকর্ড দিয়ে দিন; বিল্ড যাচাই পরে CI/DevEco পরিবেশে হবে।

**ফাইল:**
- তৈরি করুন: `apps/harmonyos/build-profile.json5`
- তৈরি করুন: `apps/harmonyos/oh-package.json5`
- তৈরি করুন: `apps/harmonyos/hvigorfile.ts`
- তৈরি করুন: `apps/harmonyos/AppScope/app.json5`
- তৈরি করুন: `apps/harmonyos/entry/src/main/module.json5`
- তৈরি করুন: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- তৈরি করুন: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- তৈরি করুন: `apps/harmonyos/openapi-config.json`

- [ ] **ধাপ 1: প্রজেক্ট কঙ্কাল (API 12+, Stage মডেল)**

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

- [ ] **ধাপ 2: এন্ট্রি পয়েন্ট ও পেজ**

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

- [ ] **ধাপ 3: OpenAPI জেনারেশন কনফিগারেশন**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **ধাপ 4: যাচাই (পরিবেশ সীমা)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **ধাপ 5: কমিট**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### কার্য 10: এন্ড-টু-এন্ড প্রোব + সম্পূর্ণ সবুজ CI (lead ইন্টিগ্রেশন)

**ফাইল:**
- তৈরি করুন: `.github/workflows/ci.yml`
- তৈরি করুন: `scripts/ci-probe.sh`

- [ ] **ধাপ 1: CI ম্যাট্রিক্স**

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

- [ ] **ধাপ 2: ইন্টিগ্রেশন প্রোব স্ক্রিপ্ট**

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

- [ ] **ধাপ 3: লোকালি এন্ড-টু-এন্ড চালানো**

```bash
bash scripts/ci-probe.sh
```
Expected: `E2E OK` প্রিন্ট হবে (health ও gRPC ping দুটোই পাস)।

- [ ] **ধাপ 4: কমিট**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## সেলফ-রিভিউ রেকর্ড

- কভারেজ: কন্ট্রাক্ট (T2/T3) → প্রোব (T4/T5/T10), তিন প্রান্ত ইনিশিয়ালাইজেশন (T7/T8/T9), admin বেসলাইন (T6), CI (T10) — ডিজাইন ডক M0-এর সম্পূর্ণ বিষয়বস্তুর অনুরূপ
- প্লেসহোল্ডার: নেই (সব ধাপে বাস্তব কমান্ড ও কোড আছে)
- টাইপ সামঞ্জস্য: PingRequest/Pong স্টাব তিন প্রান্তে অভিন্ন; `pong from {client}` অ্যাসারশন T5/T10-তে একীভূত
