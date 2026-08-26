# M0 Фундамент: план реализации

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **Для агентных исполнителей:** ОБЯЗАТЕЛЬНЫЙ ПОД-НАВЫК: используйте superpowers:subagent-driven-development (рекомендуется) или superpowers:executing-plans, чтобы выполнять этот план по задачам. Шаги отмечаются чекбоксами (`- [ ]`).

**Цель:** создать скелет монорепозитория, gRPC-контракты и конвейер генерации заглушек для трёх концов, работоспособные скелеты четырёх подсистем, полностью зелёный CI и сквозную gRPC-проверку живости service→infrastructure.

**Архитектура:** верхнеуровневый каталог contracts/ (proto-контракты, единая точка генерации) → buf генерирует PHP-заглушки (service, admin) и Rust-заглушки (infrastructure); service (webman v2) выступает gRPC-клиентом, infrastructure (bee-rust + tonic) — gRPC-сервером; три нативных проекта (iOS/Android/HarmonyOS) инициализируются по отдельности и генерируют клиенты через OpenAPI; матричный CI на GitHub Actions.

**Технологический стек:** PHP 8.3+ / webman v2 / расширение grpc / buf / protobuf / Rust (tonic + prost, workspace bee-rust) / xcodegen / Gradle (Android) / hvigor (HarmonyOS) / GitHub Actions

**Распределение по командам (дизайн-документ §16, состав M0):**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead` (технический руководитель): T10 — финальная интеграция

---

### Задача 1: Правила репозитория и корневой README

**Файлы:**
- Создать: `README.md`
- Изменить: `.gitignore`

- [ ] **Шаг 1: Проверка покрытия .gitignore**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: каждая строка должна найтись; недостающие добавьте (PHP `vendor/`, Rust `target/`, Android `build/`, Node `node_modules/`, `.env`).

- [ ] **Шаг 2: Создание корневого README.md**

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

- [ ] **Шаг 3: Коммит**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### Задача 2: Определение gRPC-контрактов в contracts

**Файлы:**
- Создать: `contracts/buf.yaml`
- Создать: `contracts/common/types.proto`
- Создать: `contracts/infra/infra_service.proto`
- Создать: `contracts/user/user_service.proto`
- Создать: `contracts/admin/admin_service.proto`

- [ ] **Шаг 1: Написать buf.yaml**

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

- [ ] **Шаг 2: Общие типы (для сквозной проверки живости Ping/Pong)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **Шаг 3: Три сервисных контракта**

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

`contracts/user/user_service.proto` (публичный сервис service, вызывается из admin; в M0 только заглушка проверки живости):
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto` (публичный сервис admin; в M0 только заглушка проверки живости):
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **Шаг 4: Проверка**

```bash
cd contracts && buf lint && buf build
```
Expected: без ошибок в выводе, exit 0. Если buf не установлен: `go install github.com/bufbuild/buf/cmd/buf@latest` или `brew install buf`.

- [ ] **Шаг 5: Коммит**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### Задача 3: Конвейер генерации заглушек + готовность PHP gRPC

**Файлы:**
- Создать: `scripts/gen-contracts.sh`
- Создать: `service/README.grpcs.md` (инструкция по установке расширения grpc)

- [ ] **Шаг 1: Написать скрипт генерации**

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

- [ ] **Шаг 2: Генерация и проверка**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: файлы заглушек, например `Social/Infra/V1/InfraServiceClient.php`, `Social/Common/V1/Pong.php`, существуют.

- [ ] **Шаг 3: Готовность PHP gRPC-зависимостей (выполнить в service и admin по отдельности)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **Шаг 4: Коммит**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### Задача 4: Скелет gRPC-сервиса tonic в infrastructure

**Файлы:**
- Создать: `infrastructure/crates/social_grpc/Cargo.toml`
- Создать: `infrastructure/crates/social_grpc/build.rs`
- Создать: `infrastructure/crates/social_grpc/src/main.rs`
- Изменить: `infrastructure/Cargo.toml` (добавить `"crates/social_grpc"` в workspace members)

- [ ] **Шаг 1: Создать crate**

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

- [ ] **Шаг 2: build.rs компилирует контракты**

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

- [ ] **Шаг 3: Серверная реализация Ping**

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

- [ ] **Шаг 4: Добавить в workspace и собрать**

Добавьте `"crates/social_grpc"` в members файла `infrastructure/Cargo.toml`.

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: сборка успешна, без ошибок.

- [ ] **Шаг 5: Коммит**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### Задача 5: Скелет webman в service + клиент проверки живости gRPC

**Файлы:**
- Создать: `service/` (проект webman через composer)
- Создать: `service/app/controller/HealthController.php`
- Создать: `service/scripts/probe_ping.php`
- Изменить: `service/config/route.php`

- [ ] **Шаг 1: Сгенерировать проект webman**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: созданы `service/app/`, `service/config/`, `service/vendor/`, `service/start.php`.

- [ ] **Шаг 2: Маршрут проверки здоровья**

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

Добавить в `service/config/route.php`:
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **Шаг 3: Скрипт проверки живости gRPC**

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

- [ ] **Шаг 4: Локальная проверка**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: `/health` возвращает `{"status":"ok","service":"social-service"}`; проверка живости выводит `pong from service`.

- [ ] **Шаг 5: Коммит**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### Задача 6: Базовое принятие admin

**Файлы:**
- Создать: `docs/ADMIN_BASELINE.md`

- [ ] **Шаг 1: Зависимости и конфигурация**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor готов; .env настроен под локальные MySQL/Redis (не изменять пример в репозитории).

- [ ] **Шаг 2: Миграции и тесты**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: существующий тестовый набор open-admin полностью зелёный (если в проекте нет тестового входа — записать в базовый документ).

- [ ] **Шаг 3: Написать базовый документ**

`docs/ADMIN_BASELINE.md`: зафиксировать текущую версию admin, рабочее состояние, включённые модули (JWT/RBAC/аудит/файлы/i18n), готовность расширения grpc и точки будущей переработки (восемь добавлений из §3.4 дизайн-документа).

- [ ] **Шаг 4: Коммит**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### Задача 7: Инициализация проекта iOS

> Эта машина — Linux, собрать Xcode-проект нельзя; поставляем исходники + конфигурацию xcodegen, проверку сборки переносим в macOS CI (job зарезервирован в T10, задача не блокируется).

**Файлы:**
- Создать: `apps/ios/project.yml` (xcodegen)
- Создать: `apps/ios/SocialApp/SocialAppApp.swift`
- Создать: `apps/ios/SocialApp/APIClient.swift`
- Создать: `apps/ios/SocialApp/ContentView.swift`
- Создать: `apps/ios/openapi-config.json`

- [ ] **Шаг 1: Конфигурация xcodegen**

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

- [ ] **Шаг 2: Скелет SwiftUI**

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

`apps/ios/SocialApp/APIClient.swift` (скелет сетевого слоя, в M1 подключим клиент из OpenAPI):
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

- [ ] **Шаг 3: Конфигурация генерации OpenAPI**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **Шаг 4: Проверка (потолок Linux)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: сгенерировать проект локально или явно зафиксировать причину пропуска.

- [ ] **Шаг 5: Коммит**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### Задача 8: Инициализация проекта Android

**Файлы:**
- Создать: `apps/android/settings.gradle.kts`
- Создать: `apps/android/build.gradle.kts`
- Создать: `apps/android/gradle.properties`
- Создать: `apps/android/app/build.gradle.kts`
- Создать: `apps/android/app/src/main/AndroidManifest.xml`
- Создать: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- Создать: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- Создать: `apps/android/openapi-config.json`

- [ ] **Шаг 1: Скелет Gradle**

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

- [ ] **Шаг 2: Точка входа и сетевой слой**

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

- [ ] **Шаг 3: Конфигурация генерации OpenAPI**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **Шаг 4: Проверка сборки (нужен Android SDK)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL, получен `app/build/outputs/apk/debug/app-debug.apk`. Если на машине нет SDK: зафиксировать требования к окружению, проверка в CI.

- [ ] **Шаг 5: Коммит**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### Задача 9: Инициализация проекта HarmonyOS

> Если на машине нет DevEco CLI — поставляем структуру проекта + запись требований к окружению, проверку сборки выполняем позже в CI/DevEco.

**Файлы:**
- Создать: `apps/harmonyos/build-profile.json5`
- Создать: `apps/harmonyos/oh-package.json5`
- Создать: `apps/harmonyos/hvigorfile.ts`
- Создать: `apps/harmonyos/AppScope/app.json5`
- Создать: `apps/harmonyos/entry/src/main/module.json5`
- Создать: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- Создать: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- Создать: `apps/harmonyos/openapi-config.json`

- [ ] **Шаг 1: Скелет проекта (API 12+, модель Stage)**

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

- [ ] **Шаг 2: Точка входа и страницы**

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

- [ ] **Шаг 3: Конфигурация генерации OpenAPI**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **Шаг 4: Проверка (потолок окружения)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **Шаг 5: Коммит**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### Задача 10: Сквозная проверка живости + полностью зелёный CI (интеграция lead)

**Файлы:**
- Создать: `.github/workflows/ci.yml`
- Создать: `scripts/ci-probe.sh`

- [ ] **Шаг 1: Матрица CI**

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

- [ ] **Шаг 2: Интеграционный скрипт проверки живости**

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

- [ ] **Шаг 3: Прогнать сквозной сценарий локально**

```bash
bash scripts/ci-probe.sh
```
Expected: вывод `E2E OK` (и health, и gRPC ping проходят).

- [ ] **Шаг 4: Коммит**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## Записи саморевью

- Охват: контракты (T2/T3) → проверка живости (T4/T5/T10), инициализация трёх концов (T7/T8/T9), базовый admin (T6), CI (T10) — соответствует всему содержимому M0 дизайн-документа
- Заглушки-плейсхолдеры: нет (все шаги содержат реальные команды и код)
- Согласованность типов: заглушки PingRequest/Pong одинаковы на трёх концах; проверка `pong from {client}` единообразна в T5/T10
