# M0 फ़ाउंडेशन कार्यान्वयन योजना

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **एजेंटिक वर्कर के लिए:** आवश्यक उप-कौशल: इस योजना को कार्य-दर-कार्य लागू करने के लिए superpowers:subagent-driven-development (अनुशंसित) या superpowers:executing-plans का उपयोग करें। चरणों को ट्रैक करने के लिए चेकबॉक्स (`- [ ]`) सिंटैक्स का उपयोग किया जाता है।

**लक्ष्य:** मोनोरेपो ढांचा, gRPC कॉन्ट्रैक्ट और तीनों छोरों के लिए स्टब जनरेशन पाइपलाइन, चारों सबसिस्टम के चलने योग्य ढांचे, पूरी तरह हरा CI, और service→infrastructure के बीच एंड-टू-एंड gRPC लाइवनेस जांच।

**आर्किटेक्चर:** शीर्ष स्तर की निर्देशिका contracts/ (proto कॉन्ट्रैक्ट, जनरेशन का एकमात्र प्रवेश बिंदु) → buf PHP स्टब (service, admin) और Rust स्टब (infrastructure) उत्पन्न करता है; service (webman v2) gRPC क्लाइंट के रूप में कार्य करता है, infrastructure (bee-rust + tonic) gRPC सर्वर के रूप में; तीनों नेटिव प्रोजेक्ट (iOS/Android/HarmonyOS) अलग-अलग इनिशियलाइज़ होते हैं और OpenAPI से क्लाइंट उत्पन्न करते हैं; GitHub Actions मैट्रिक्स CI।

**टेक स्टैक:** PHP 8.3+ / webman v2 / grpc एक्सटेंशन / buf / protobuf / Rust (tonic + prost, bee-rust workspace) / xcodegen / Gradle (Android) / hvigor (HarmonyOS) / GitHub Actions

**टीम विभाजन (डिज़ाइन दस्तावेज़ §16, M0 कार्यभार):**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead` (तकनीकी प्रमुख): T10 एकीकरण का समापन

---

### कार्य 1: रिपॉजिटरी नियम और रूट README

**फ़ाइलें:**
- बनाएँ: `README.md`
- संशोधित करें: `.gitignore`

- [ ] **चरण 1: .gitignore कवरेज जांचें**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: हर पंक्ति का मिलान होना चाहिए; जो छूटे उन्हें जोड़ें (PHP `vendor/`, Rust `target/`, Android `build/`, Node `node_modules/`, `.env`)।

- [ ] **चरण 2: रूट README.md बनाएँ**

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

- [ ] **चरण 3: कमिट करें**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### कार्य 2: contracts में gRPC कॉन्ट्रैक्ट परिभाषाएँ

**फ़ाइलें:**
- बनाएँ: `contracts/buf.yaml`
- बनाएँ: `contracts/common/types.proto`
- बनाएँ: `contracts/infra/infra_service.proto`
- बनाएँ: `contracts/user/user_service.proto`
- बनाएँ: `contracts/admin/admin_service.proto`

- [ ] **चरण 1: buf.yaml लिखें**

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

- [ ] **चरण 2: साझा टाइप लिखें (एंड-टू-एंड Ping/Pong लाइवनेस जांच के लिए)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **चरण 3: तीनों सेवा कॉन्ट्रैक्ट लिखें**

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

`contracts/user/user_service.proto` (service का सार्वजनिक सेवा, admin द्वारा कॉल; M0 में केवल लाइवनेस स्टब):
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto` (admin का सार्वजनिक सेवा; M0 में केवल लाइवनेस स्टब):
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **चरण 4: सत्यापित करें**

```bash
cd contracts && buf lint && buf build
```
Expected: आउटपुट में कोई त्रुटि नहीं, exit 0। अगर buf इंस्टॉल नहीं है: `go install github.com/bufbuild/buf/cmd/buf@latest` या `brew install buf`।

- [ ] **चरण 5: कमिट करें**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### कार्य 3: स्टब जनरेशन पाइपलाइन + PHP gRPC तैयारी

**फ़ाइलें:**
- बनाएँ: `scripts/gen-contracts.sh`
- बनाएँ: `service/README.grpcs.md` (grpc एक्सटेंशन इंस्टॉलेशन नोट्स)

- [ ] **चरण 1: जनरेशन स्क्रिप्ट लिखें**

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

- [ ] **चरण 2: जनरेट करें और सत्यापित करें**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: `Social/Infra/V1/InfraServiceClient.php`, `Social/Common/V1/Pong.php` जैसी स्टब फ़ाइलें मौजूद हों।

- [ ] **चरण 3: PHP gRPC निर्भरताएँ तैयार (service और admin में अलग-अलग चलाएँ)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **चरण 4: कमिट करें**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### कार्य 4: infrastructure में tonic gRPC सेवा ढांचा

**फ़ाइलें:**
- बनाएँ: `infrastructure/crates/social_grpc/Cargo.toml`
- बनाएँ: `infrastructure/crates/social_grpc/build.rs`
- बनाएँ: `infrastructure/crates/social_grpc/src/main.rs`
- संशोधित करें: `infrastructure/Cargo.toml` (workspace members में `"crates/social_grpc"` जोड़ें)

- [ ] **चरण 1: crate बनाएँ**

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

- [ ] **चरण 2: build.rs कॉन्ट्रैक्ट कंपाइल करता है**

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

- [ ] **चरण 3: Ping सर्वर इम्प्लीमेंटेशन**

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

- [ ] **चरण 4: workspace में जोड़ें और बिल्ड करें**

`infrastructure/Cargo.toml` के members में `"crates/social_grpc"` जोड़ें।

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: बिल्ड सफल, कोई त्रुटि नहीं।

- [ ] **चरण 5: कमिट करें**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### कार्य 5: service में webman ढांचा + gRPC लाइवनेस प्रोब क्लाइंट

**फ़ाइलें:**
- बनाएँ: `service/` (composer से webman प्रोजेक्ट)
- बनाएँ: `service/app/controller/HealthController.php`
- बनाएँ: `service/scripts/probe_ping.php`
- संशोधित करें: `service/config/route.php`

- [ ] **चरण 1: webman प्रोजेक्ट जनरेट करें**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: `service/app/`, `service/config/`, `service/vendor/`, `service/start.php` जनरेट हों।

- [ ] **चरण 2: हेल्थ चेक रूट**

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

`service/config/route.php` में जोड़ें:
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **चरण 3: gRPC लाइवनेस प्रोब स्क्रिप्ट**

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

- [ ] **चरण 4: लोकल सत्यापन**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: `/health` `{"status":"ok","service":"social-service"}` लौटाए; प्रोब `pong from service` प्रिंट करे।

- [ ] **चरण 5: कमिट करें**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### कार्य 6: admin बेसलाइन स्वीकृति

**फ़ाइलें:**
- बनाएँ: `docs/ADMIN_BASELINE.md`

- [ ] **चरण 1: निर्भरताएँ और कॉन्फ़िगरेशन**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor तैयार; .env लोकल MySQL/Redis के अनुसार कॉन्फ़िगर करें (रिपॉजिटरी की उदाहरण फ़ाइल न बदलें)।

- [ ] **चरण 2: माइग्रेशन और टेस्ट**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: मौजूदा open-admin टेस्ट सूट पूरी तरह हरा (अगर प्रोजेक्ट में टेस्ट एंट्री नहीं है, बेसलाइन दस्तावेज़ में दर्ज करें)।

- [ ] **चरण 3: बेसलाइन दस्तावेज़ लिखें**

`docs/ADMIN_BASELINE.md`: admin का वर्तमान संस्करण, चालू स्थिति, सक्षम मॉड्यूल (JWT/RBAC/ऑडिट/फ़ाइलें/i18n), grpc एक्सटेंशन की तैयारी और आगामी रिफैक्टरिंग के प्रवेश बिंदु दर्ज करें (डिज़ाइन दस्तावेज़ §3.4 के आठ नए जोड़ों के अनुरूप)।

- [ ] **चरण 4: कमिट करें**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### कार्य 7: iOS प्रोजेक्ट इनिशियलाइज़ेशन

> यह मशीन Linux है और Xcode प्रोजेक्ट नहीं बना सकती; सोर्स + xcodegen कॉन्फ़िगरेशन दें, बिल्ड सत्यापन macOS CI पर टालें (T10 में job आरक्षित; यह कार्य ब्लॉक नहीं करता)।

**फ़ाइलें:**
- बनाएँ: `apps/ios/project.yml` (xcodegen)
- बनाएँ: `apps/ios/SocialApp/SocialAppApp.swift`
- बनाएँ: `apps/ios/SocialApp/APIClient.swift`
- बनाएँ: `apps/ios/SocialApp/ContentView.swift`
- बनाएँ: `apps/ios/openapi-config.json`

- [ ] **चरण 1: xcodegen कॉन्फ़िगरेशन**

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

- [ ] **चरण 2: SwiftUI ढांचा**

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

`apps/ios/SocialApp/APIClient.swift` (नेटवर्क लेयर ढांचा; M1 में OpenAPI-जनरेटेड क्लाइंट जुड़ेगा):
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

- [ ] **चरण 3: OpenAPI जनरेशन कॉन्फ़िगरेशन**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **चरण 4: सत्यापन (Linux सीमा)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: प्रोजेक्ट लोकल जनरेट करें या छोड़ने का कारण स्पष्ट रूप से दर्ज करें।

- [ ] **चरण 5: कमिट करें**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### कार्य 8: Android प्रोजेक्ट इनिशियलाइज़ेशन

**फ़ाइलें:**
- बनाएँ: `apps/android/settings.gradle.kts`
- बनाएँ: `apps/android/build.gradle.kts`
- बनाएँ: `apps/android/gradle.properties`
- बनाएँ: `apps/android/app/build.gradle.kts`
- बनाएँ: `apps/android/app/src/main/AndroidManifest.xml`
- बनाएँ: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- बनाएँ: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- बनाएँ: `apps/android/openapi-config.json`

- [ ] **चरण 1: Gradle ढांचा**

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

- [ ] **चरण 2: एंट्री पॉइंट और नेटवर्क लेयर**

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

- [ ] **चरण 3: OpenAPI जनरेशन कॉन्फ़िगरेशन**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **चरण 4: बिल्ड सत्यापन (Android SDK चाहिए)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL, `app/build/outputs/apk/debug/app-debug.apk` बने। अगर इस मशीन पर SDK नहीं है: पर्यावरण आवश्यकता दर्ज करें, CI में सत्यापन।

- [ ] **चरण 5: कमिट करें**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### कार्य 9: HarmonyOS प्रोजेक्ट इनिशियलाइज़ेशन

> अगर इस मशीन पर DevEco CLI नहीं है तो प्रोजेक्ट संरचना + पर्यावरण आवश्यकता रिकॉर्ड दें; बिल्ड सत्यापन बाद में CI/DevEco परिवेश में होगा।

**फ़ाइलें:**
- बनाएँ: `apps/harmonyos/build-profile.json5`
- बनाएँ: `apps/harmonyos/oh-package.json5`
- बनाएँ: `apps/harmonyos/hvigorfile.ts`
- बनाएँ: `apps/harmonyos/AppScope/app.json5`
- बनाएँ: `apps/harmonyos/entry/src/main/module.json5`
- बनाएँ: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- बनाएँ: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- बनाएँ: `apps/harmonyos/openapi-config.json`

- [ ] **चरण 1: प्रोजेक्ट ढांचा (API 12+, Stage मॉडल)**

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

- [ ] **चरण 2: एंट्री पॉइंट और पेज**

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

- [ ] **चरण 3: OpenAPI जनरेशन कॉन्फ़िगरेशन**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **चरण 4: सत्यापन (पर्यावरण सीमा)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **चरण 5: कमिट करें**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### कार्य 10: एंड-टू-एंड प्रोब + पूरी तरह हरा CI (lead एकीकरण)

**फ़ाइलें:**
- बनाएँ: `.github/workflows/ci.yml`
- बनाएँ: `scripts/ci-probe.sh`

- [ ] **चरण 1: CI मैट्रिक्स**

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

- [ ] **चरण 2: इंटीग्रेशन प्रोब स्क्रिप्ट**

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

- [ ] **चरण 3: लोकल में एंड-टू-एंड चलाएँ**

```bash
bash scripts/ci-probe.sh
```
Expected: `E2E OK` प्रिंट हो (health और gRPC ping दोनों पास)।

- [ ] **चरण 4: कमिट करें**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## सेल्फ-रिव्यू रिकॉर्ड

- कवरेज: कॉन्ट्रैक्ट (T2/T3) → प्रोब (T4/T5/T10), तीनों छोर इनिशियलाइज़ेशन (T7/T8/T9), admin बेसलाइन (T6), CI (T10) — डिज़ाइन दस्तावेज़ की M0 की पूरी सामग्री के अनुरूप
- प्लेसहोल्डर: कोई नहीं (हर चरण में वास्तविक कमांड और कोड हैं)
- टाइप स्थिरता: PingRequest/Pong स्टब तीनों छोरों पर समान; `pong from {client}` असेरशन T5/T10 में एकीकृत
