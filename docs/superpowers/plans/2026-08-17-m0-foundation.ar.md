# خطة تنفيذ الأساس M0

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **للعاملين الآليين:** مهارة فرعية مطلوبة: استخدم superpowers:subagent-driven-development (موصى به) أو superpowers:executing-plans لتنفيذ هذه الخطة مهمةً تلو الأخرى. تُتتبع الخطوات باستخدام صيغة مربعات الاختيار (`- [ ]`).

**الهدف:** إنشاء هيكل المستودع الموحد (monorepo)، وعقود gRPC وخط أنابيب توليد الأكواد اللاصقة (stubs) للأطراف الثلاثة، وهياكل قابلة للتشغيل للأنظمة الفرعية الأربعة، وCI أخضر بالكامل، واختبار استجابة gRPC من طرف إلى طرف بين service → infrastructure.

**البنية:** الدليل الجذري contracts/ (عقود proto، نقطة الدخول الوحيدة للتوليد) → تولّد buf أكواد PHP اللاصقة (service، admin) وأكواد Rust اللاصقة (infrastructure)؛ يعمل service (webman v2) كعميل gRPC، وinfrastructure (bee-rust + tonic) كخادم gRPC؛ تُهيَّأ المشاريع الأصلية الثلاثة (iOS/Android/HarmonyOS) كلٌّ على حدة وتولّد عملاء عبر OpenAPI؛ وCI مصفوفي عبر GitHub Actions.

**المكدس التقني:** PHP 8.3+ / webman v2 / إضافة grpc / buf / protobuf / Rust (tonic + prost، مساحة عمل bee-rust) / xcodegen / Gradle (Android) / hvigor (HarmonyOS) / GitHub Actions

**توزيع الفرق (وثيقة التصميم §16، تشكيلة M0):**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead` (المسؤول التقني): T10 إغلاق التكامل

---

### المهمة 1: قواعد المستودع وREADME الجذر

**الملفات:**
- إنشاء: `README.md`
- تعديل: `.gitignore`

- [ ] **الخطوة 1: فحص تغطية .gitignore**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: لكل سطر تطابق؛ أضف المفقود (PHP `vendor/`، Rust `target/`، Android `build/`، Node `node_modules/`، `.env`).

- [ ] **الخطوة 2: إنشاء README.md الجذر**

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

- [ ] **الخطوة 3: الإيداع (commit)**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### المهمة 2: تعريف عقود gRPC في contracts

**الملفات:**
- إنشاء: `contracts/buf.yaml`
- إنشاء: `contracts/common/types.proto`
- إنشاء: `contracts/infra/infra_service.proto`
- إنشاء: `contracts/user/user_service.proto`
- إنشاء: `contracts/admin/admin_service.proto`

- [ ] **الخطوة 1: كتابة buf.yaml**

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

- [ ] **الخطوة 2: كتابة الأنواع المشتركة (لاختبار استجابة Ping/Pong من طرف إلى طرف)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **الخطوة 3: كتابة عقود الخدمات الثلاثة**

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

`contracts/user/user_service.proto` (الخدمة العامة لـ service، يستدعيها admin؛ في M0 كود لاصق لاختبار الاستجابة فقط):
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto` (الخدمة العامة لـ admin؛ في M0 كود لاصق لاختبار الاستجابة فقط):
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **الخطوة 4: التحقق**

```bash
cd contracts && buf lint && buf build
```
Expected: لا أخطاء في المخرجات، exit 0. إذا لم يكن buf مثبتًا: `go install github.com/bufbuild/buf/cmd/buf@latest` أو `brew install buf`.

- [ ] **الخطوة 5: الإيداع**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### المهمة 3: خط أنابيب توليد الأكواد اللاصقة + جاهزية PHP gRPC

**الملفات:**
- إنشاء: `scripts/gen-contracts.sh`
- إنشاء: `service/README.grpcs.md` (ملاحظات تثبيت إضافة grpc)

- [ ] **الخطوة 1: كتابة سكربت التوليد**

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

- [ ] **الخطوة 2: التوليد والتحقق**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: توجد ملفات الأكواد اللاصقة مثل `Social/Infra/V1/InfraServiceClient.php` و`Social/Common/V1/Pong.php`.

- [ ] **الخطوة 3: تجهيز تبعيات PHP gRPC (تنفيذ منفصل في service وadmin)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **الخطوة 4: الإيداع**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### المهمة 4: هيكل خدمة gRPC tonic في infrastructure

**الملفات:**
- إنشاء: `infrastructure/crates/social_grpc/Cargo.toml`
- إنشاء: `infrastructure/crates/social_grpc/build.rs`
- إنشاء: `infrastructure/crates/social_grpc/src/main.rs`
- تعديل: `infrastructure/Cargo.toml` (إضافة `"crates/social_grpc"` إلى workspace members)

- [ ] **الخطوة 1: إنشاء الـ crate**

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

- [ ] **الخطوة 2: build.rs يترجم العقود**

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

- [ ] **الخطوة 3: تنفيذ خادم Ping**

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

- [ ] **الخطوة 4: الإضافة إلى workspace والبناء**

أضف `"crates/social_grpc"` إلى members في `infrastructure/Cargo.toml`.

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: البناء ناجح دون أخطاء.

- [ ] **الخطوة 5: الإيداع**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### المهمة 5: هيكل webman في service + عميل اختبار استجابة gRPC

**الملفات:**
- إنشاء: `service/` (مشروع webman عبر composer)
- إنشاء: `service/app/controller/HealthController.php`
- إنشاء: `service/scripts/probe_ping.php`
- تعديل: `service/config/route.php`

- [ ] **الخطوة 1: توليد مشروع webman**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: يتم توليد `service/app/` و`service/config/` و`service/vendor/` و`service/start.php`.

- [ ] **الخطوة 2: مسار فحص الصحة**

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

أضف إلى `service/config/route.php`:
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **الخطوة 3: سكربت اختبار استجابة gRPC**

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

- [ ] **الخطوة 4: التحقق محليًا**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: يُرجع `/health` القيمة `{"status":"ok","service":"social-service"}`؛ ويطبع اختبار الاستجابة `pong from service`.

- [ ] **الخطوة 5: الإيداع**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### المهمة 6: قبول خط الأساس لـ admin

**الملفات:**
- إنشاء: `docs/ADMIN_BASELINE.md`

- [ ] **الخطوة 1: التبعيات والإعداد**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor جاهز؛ اضبط .env وفق MySQL/Redis المحلي (لا تعدّل ملف المثال داخل المستودع).

- [ ] **الخطوة 2: الترحيلات والاختبارات**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: مجموعة اختبارات open-admin الحالية خضراء بالكامل (إذا لم يكن للمشروع مدخل اختبار، سجّل ذلك في وثيقة خط الأساس).

- [ ] **الخطوة 3: كتابة وثيقة خط الأساس**

`docs/ADMIN_BASELINE.md`: سجّل إصدار admin الحالي وحالة التشغيل والوحدات المفعّلة (JWT/RBAC/التدقيق/الملفات/i18n) وجاهزية إضافة grpc ونقاط دخول التعديلات المستقبلية (المقابلة للإضافات الثماني في §3.4 من وثيقة التصميم).

- [ ] **الخطوة 4: الإيداع**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### المهمة 7: تهيئة مشروع iOS

> هذا الجهاز يعمل بـ Linux ولا يمكنه بناء مشروع Xcode؛ سلّم الكود المصدري + إعداد xcodegen، وأجّل التحقق من البناء إلى CI على macOS (job محجوز في T10؛ هذه المهمة لا تمنع التقدم).

**الملفات:**
- إنشاء: `apps/ios/project.yml` (xcodegen)
- إنشاء: `apps/ios/SocialApp/SocialAppApp.swift`
- إنشاء: `apps/ios/SocialApp/APIClient.swift`
- إنشاء: `apps/ios/SocialApp/ContentView.swift`
- إنشاء: `apps/ios/openapi-config.json`

- [ ] **الخطوة 1: إعداد xcodegen**

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

- [ ] **الخطوة 2: هيكل SwiftUI**

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

`apps/ios/SocialApp/APIClient.swift` (هيكل طبقة الشبكة؛ في M1 سيُربط العميل المولَّد عبر OpenAPI):
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

- [ ] **الخطوة 3: إعداد توليد OpenAPI**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **الخطوة 4: التحقق (حد Linux)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: توليد المشروع محليًا أو تسجيل سبب التخطي بوضوح.

- [ ] **الخطوة 5: الإيداع**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### المهمة 8: تهيئة مشروع Android

**الملفات:**
- إنشاء: `apps/android/settings.gradle.kts`
- إنشاء: `apps/android/build.gradle.kts`
- إنشاء: `apps/android/gradle.properties`
- إنشاء: `apps/android/app/build.gradle.kts`
- إنشاء: `apps/android/app/src/main/AndroidManifest.xml`
- إنشاء: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- إنشاء: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- إنشاء: `apps/android/openapi-config.json`

- [ ] **الخطوة 1: هيكل Gradle**

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

- [ ] **الخطوة 2: نقطة الدخول وطبقة الشبكة**

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

- [ ] **الخطوة 3: إعداد توليد OpenAPI**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **الخطوة 4: التحقق من البناء (يتطلب Android SDK)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL، ينتج `app/build/outputs/apk/debug/app-debug.apk`. إذا لم يكن على الجهاز SDK: سجّل متطلبات البيئة وتحقق عبر CI.

- [ ] **الخطوة 5: الإيداع**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### المهمة 9: تهيئة مشروع HarmonyOS

> إذا لم يكن DevEco CLI متاحًا على هذا الجهاز، سلّم بنية المشروع + سجل متطلبات البيئة، ويُنفَّذ التحقق من البناء لاحقًا في بيئة CI/DevEco.

**الملفات:**
- إنشاء: `apps/harmonyos/build-profile.json5`
- إنشاء: `apps/harmonyos/oh-package.json5`
- إنشاء: `apps/harmonyos/hvigorfile.ts`
- إنشاء: `apps/harmonyos/AppScope/app.json5`
- إنشاء: `apps/harmonyos/entry/src/main/module.json5`
- إنشاء: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- إنشاء: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- إنشاء: `apps/harmonyos/openapi-config.json`

- [ ] **الخطوة 1: هيكل المشروع (API 12+، نموذج Stage)**

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

- [ ] **الخطوة 2: نقطة الدخول والصفحات**

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

- [ ] **الخطوة 3: إعداد توليد OpenAPI**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **الخطوة 4: التحقق (حد البيئة)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **الخطوة 5: الإيداع**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### المهمة 10: اختبار استجابة من طرف إلى طرف + CI أخضر بالكامل (تكامل lead)

**الملفات:**
- إنشاء: `.github/workflows/ci.yml`
- إنشاء: `scripts/ci-probe.sh`

- [ ] **الخطوة 1: مصفوفة CI**

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

- [ ] **الخطوة 2: سكربت اختبار الاستجابة المتكامل**

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

- [ ] **الخطوة 3: تشغيل السيناريو من طرف إلى طرف محليًا**

```bash
bash scripts/ci-probe.sh
```
Expected: طباعة `E2E OK` (يجتاز كل من health وgRPC ping).

- [ ] **الخطوة 4: الإيداع**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## سجل المراجعة الذاتية

- التغطية: العقود (T2/T3) ← اختبار الاستجابة (T4/T5/T10)، تهيئة الأطراف الثلاثة (T7/T8/T9)، خط أساس admin (T6)، CI (T10) — تقابل كامل محتوى M0 في وثيقة التصميم
- العناصر النائبة: لا يوجد (جميع الخطوات تحتوي أوامر وكودًا حقيقيين)
- اتساق الأنواع: أكواد PingRequest/Pong اللاصقة متطابقة في الأطراف الثلاثة؛ وتأكيد `pong from {client}` موحَّد في T5/T10
