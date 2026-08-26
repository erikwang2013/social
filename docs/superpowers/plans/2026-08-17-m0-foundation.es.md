# Plan de implementación de los cimientos M0

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **Para trabajadores agénticos:** SUB-HABILIDAD REQUERIDA: usa superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para implementar este plan tarea por tarea. Los pasos usan la sintaxis de casillas (`- [ ]`) para el seguimiento.

**Objetivo:** establecer el esqueleto del monorepo, los contratos gRPC y el pipeline de generación de stubs para los tres extremos, los esqueletos ejecutables de los cuatro subsistemas, un CI totalmente en verde y la sonda gRPC de extremo a extremo service → infrastructure.

**Arquitectura:** el directorio de nivel superior contracts/ (contratos proto, único punto de entrada de generación) → buf genera stubs PHP (service, admin) y stubs Rust (infrastructure); service (webman v2) actúa como cliente gRPC, infrastructure (bee-rust + tonic) como servidor gRPC; los tres proyectos nativos (iOS/Android/HarmonyOS) se inicializan por separado y generan clientes mediante OpenAPI; CI de matriz de GitHub Actions.

**Stack tecnológico:** PHP 8.3+ / webman v2 / extensión grpc / buf / protobuf / Rust (tonic + prost, workspace bee-rust) / xcodegen / Gradle (Android) / hvigor (HarmonyOS) / GitHub Actions

**División del equipo (doc. de diseño §16, dotación M0):**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead` (líder técnico): T10 cierre de integración

---

### Tarea 1: Convenciones del repositorio y README raíz

**Archivos:**
- Crear: `README.md`
- Modificar: `.gitignore`

- [ ] **Paso 1: Comprobar la cobertura de .gitignore**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: cada línea tiene una coincidencia; añade las que falten (PHP `vendor/`, Rust `target/`, Android `build/`, Node `node_modules/`, `.env`).

- [ ] **Paso 2: Crear el README.md raíz**

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

- [ ] **Paso 3: Commit**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### Tarea 2: Definición de contratos gRPC en contracts

**Archivos:**
- Crear: `contracts/buf.yaml`
- Crear: `contracts/common/types.proto`
- Crear: `contracts/infra/infra_service.proto`
- Crear: `contracts/user/user_service.proto`
- Crear: `contracts/admin/admin_service.proto`

- [ ] **Paso 1: Escribir buf.yaml**

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

- [ ] **Paso 2: Escribir los tipos comunes (para la sonda Ping/Pong de extremo a extremo)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **Paso 3: Escribir los tres contratos de servicio**

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

`contracts/user/user_service.proto` (servicio público de service, llamado por admin; en M0 solo stub de sonda):
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto` (servicio público de admin; en M0 solo stub de sonda):
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **Paso 4: Validar**

```bash
cd contracts && buf lint && buf build
```
Expected: sin errores de salida, exit 0. Si buf no está instalado: `go install github.com/bufbuild/buf/cmd/buf@latest` o `brew install buf`.

- [ ] **Paso 5: Commit**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### Tarea 3: Pipeline de generación de stubs + preparación de PHP gRPC

**Archivos:**
- Crear: `scripts/gen-contracts.sh`
- Crear: `service/README.grpcs.md` (notas de instalación de la extensión grpc)

- [ ] **Paso 1: Escribir el script de generación**

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

- [ ] **Paso 2: Generar y verificar**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: existen archivos de stub como `Social/Infra/V1/InfraServiceClient.php` y `Social/Common/V1/Pong.php`.

- [ ] **Paso 3: Dependencias PHP gRPC listas (ejecutar en service y admin por separado)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **Paso 4: Commit**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### Tarea 4: Esqueleto del servicio gRPC tonic en infrastructure

**Archivos:**
- Crear: `infrastructure/crates/social_grpc/Cargo.toml`
- Crear: `infrastructure/crates/social_grpc/build.rs`
- Crear: `infrastructure/crates/social_grpc/src/main.rs`
- Modificar: `infrastructure/Cargo.toml` (añadir `"crates/social_grpc"` a workspace members)

- [ ] **Paso 1: Crear el crate**

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

- [ ] **Paso 2: build.rs compila los contratos**

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

- [ ] **Paso 3: Implementación del servidor Ping**

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

- [ ] **Paso 4: Añadir al workspace y compilar**

Añade `"crates/social_grpc"` a los members de `infrastructure/Cargo.toml`.

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: compilación correcta, sin errores.

- [ ] **Paso 5: Commit**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### Tarea 5: Esqueleto webman en service + cliente de sonda gRPC

**Archivos:**
- Crear: `service/` (proyecto webman generado con composer)
- Crear: `service/app/controller/HealthController.php`
- Crear: `service/scripts/probe_ping.php`
- Modificar: `service/config/route.php`

- [ ] **Paso 1: Generar el proyecto webman**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: se generan `service/app/`, `service/config/`, `service/vendor/`, `service/start.php`.

- [ ] **Paso 2: Ruta de health check**

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

Añadir a `service/config/route.php`:
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **Paso 3: Script de sonda gRPC**

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

- [ ] **Paso 4: Verificación local**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: `/health` devuelve `{"status":"ok","service":"social-service"}`; la sonda imprime `pong from service`.

- [ ] **Paso 5: Commit**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### Tarea 6: Aceptación de la línea base de admin

**Archivos:**
- Crear: `docs/ADMIN_BASELINE.md`

- [ ] **Paso 1: Dependencias y configuración**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor listo; .env configurado para el MySQL/Redis local (no modificar el archivo de ejemplo del repositorio).

- [ ] **Paso 2: Migraciones y pruebas**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: la suite de pruebas open-admin existente está totalmente en verde (si el proyecto no tiene entrada de pruebas, anotarlo en el documento de línea base).

- [ ] **Paso 3: Escribir el documento de línea base**

`docs/ADMIN_BASELINE.md`: registrar la versión actual de admin, su estado de funcionamiento, los módulos habilitados (JWT/RBAC/auditoría/archivos/i18n), la disponibilidad de la extensión grpc y los puntos de entrada de la futura reforma (correspondientes a las ocho incorporaciones del §3.4 del doc. de diseño).

- [ ] **Paso 4: Commit**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### Tarea 7: Inicialización del proyecto iOS

> Esta máquina es Linux y no puede compilar un proyecto Xcode; entregar el código fuente + la configuración de xcodegen, y dejar la verificación de compilación al CI de macOS (job reservado en T10; esta tarea no bloquea).

**Archivos:**
- Crear: `apps/ios/project.yml` (xcodegen)
- Crear: `apps/ios/SocialApp/SocialAppApp.swift`
- Crear: `apps/ios/SocialApp/APIClient.swift`
- Crear: `apps/ios/SocialApp/ContentView.swift`
- Crear: `apps/ios/openapi-config.json`

- [ ] **Paso 1: Configuración de xcodegen**

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

- [ ] **Paso 2: Esqueleto SwiftUI**

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

`apps/ios/SocialApp/APIClient.swift` (esqueleto de la capa de red; M1 conectará el cliente generado por OpenAPI):
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

- [ ] **Paso 3: Configuración de generación OpenAPI**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **Paso 4: Verificación (límite de Linux)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: generar el proyecto localmente o registrar claramente el motivo de omitirlo.

- [ ] **Paso 5: Commit**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### Tarea 8: Inicialización del proyecto Android

**Archivos:**
- Crear: `apps/android/settings.gradle.kts`
- Crear: `apps/android/build.gradle.kts`
- Crear: `apps/android/gradle.properties`
- Crear: `apps/android/app/build.gradle.kts`
- Crear: `apps/android/app/src/main/AndroidManifest.xml`
- Crear: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- Crear: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- Crear: `apps/android/openapi-config.json`

- [ ] **Paso 1: Esqueleto Gradle**

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

- [ ] **Paso 2: Punto de entrada y capa de red**

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

- [ ] **Paso 3: Configuración de generación OpenAPI**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **Paso 4: Verificación de compilación (requiere Android SDK)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL, produciendo `app/build/outputs/apk/debug/app-debug.apk`. Si esta máquina no tiene SDK: registrar el requisito de entorno y verificar en CI.

- [ ] **Paso 5: Commit**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### Tarea 9: Inicialización del proyecto HarmonyOS

> Si esta máquina no tiene DevEco CLI, entregar la estructura del proyecto + un registro de los requisitos de entorno; la verificación de compilación se hará después en el entorno CI/DevEco.

**Archivos:**
- Crear: `apps/harmonyos/build-profile.json5`
- Crear: `apps/harmonyos/oh-package.json5`
- Crear: `apps/harmonyos/hvigorfile.ts`
- Crear: `apps/harmonyos/AppScope/app.json5`
- Crear: `apps/harmonyos/entry/src/main/module.json5`
- Crear: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- Crear: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- Crear: `apps/harmonyos/openapi-config.json`

- [ ] **Paso 1: Esqueleto del proyecto (API 12+, modelo Stage)**

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

- [ ] **Paso 2: Punto de entrada y páginas**

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

- [ ] **Paso 3: Configuración de generación OpenAPI**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **Paso 4: Verificación (límite de entorno)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **Paso 5: Commit**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### Tarea 10: Sonda de extremo a extremo + CI totalmente verde (integración lead)

**Archivos:**
- Crear: `.github/workflows/ci.yml`
- Crear: `scripts/ci-probe.sh`

- [ ] **Paso 1: Matriz CI**

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

- [ ] **Paso 2: Script de sonda de integración**

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

- [ ] **Paso 3: Ejecutar el flujo de extremo a extremo en local**

```bash
bash scripts/ci-probe.sh
```
Expected: imprime `E2E OK` (tanto health como el ping gRPC pasan).

- [ ] **Paso 4: Commit**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## Registro de auto-revisión

- Cobertura: contratos (T2/T3) → sonda (T4/T5/T10), inicialización de los tres extremos (T7/T8/T9), línea base de admin (T6), CI (T10) — corresponde a todo el contenido M0 del doc. de diseño
- Marcadores de posición: ninguno (todos los pasos contienen comandos y código reales)
- Coherencia de tipos: stubs PingRequest/Pong idénticos en los tres extremos; la aserción `pong from {client}` está unificada en T5/T10
