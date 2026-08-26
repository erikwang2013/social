# Plan d'implémentation du socle M0

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **Pour les agents autonomes :** COMPÉTENCE REQUISE : utilisez superpowers:subagent-driven-development (recommandé) ou superpowers:executing-plans pour implémenter ce plan tâche par tâche. Les étapes utilisent la syntaxe à cases (`- [ ]`) pour le suivi.

**Objectif :** établir le squelette du monorepo, les contrats gRPC et le pipeline de génération de stubs pour les trois extrémités, les squelettes exécutables des quatre sous-systèmes, un CI entièrement vert, et la sonde gRPC de bout en bout service → infrastructure.

**Architecture :** le répertoire de premier niveau contracts/ (contrats proto, point d'entrée unique de génération) → buf génère les stubs PHP (service, admin) et les stubs Rust (infrastructure) ; service (webman v2) est le client gRPC, infrastructure (bee-rust + tonic) le serveur gRPC ; les trois projets natifs (iOS/Android/HarmonyOS) sont chacun initialisés et génèrent leur client via OpenAPI ; CI matriciel GitHub Actions.

**Pile technique :** PHP 8.3+ / webman v2 / extension grpc / buf / protobuf / Rust (tonic + prost, workspace bee-rust) / xcodegen / Gradle (Android) / hvigor (HarmonyOS) / GitHub Actions

**Répartition des équipes (doc. de conception §16, effectif M0) :**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead` (responsable technique) : T10 intégration finale

---

### Tâche 1 : Conventions du dépôt et README racine

**Fichiers :**
- Créer : `README.md`
- Modifier : `.gitignore`

- [ ] **Étape 1 : Vérifier la couverture de .gitignore**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected : chaque ligne doit avoir une correspondance ; compléter ce qui manque (PHP `vendor/`, Rust `target/`, Android `build/`, Node `node_modules/`, `.env`).

- [ ] **Étape 2 : Créer le README.md racine**

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

- [ ] **Étape 3 : Commiter**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### Tâche 2 : Définition des contrats gRPC dans contracts

**Fichiers :**
- Créer : `contracts/buf.yaml`
- Créer : `contracts/common/types.proto`
- Créer : `contracts/infra/infra_service.proto`
- Créer : `contracts/user/user_service.proto`
- Créer : `contracts/admin/admin_service.proto`

- [ ] **Étape 1 : Écrire buf.yaml**

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

- [ ] **Étape 2 : Écrire les types communs (pour la sonde Ping/Pong de bout en bout)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **Étape 3 : Écrire les trois contrats de services**

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

`contracts/user/user_service.proto` (service public de service, appelé par admin ; en M0, stub de sonde uniquement) :
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto` (service public de admin ; en M0, stub de sonde uniquement) :
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **Étape 4 : Valider**

```bash
cd contracts && buf lint && buf build
```
Expected : aucune erreur en sortie, exit 0. Si buf n'est pas installé : `go install github.com/bufbuild/buf/cmd/buf@latest` ou `brew install buf`.

- [ ] **Étape 5 : Commiter**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### Tâche 3 : Pipeline de génération de stubs + préparation PHP gRPC

**Fichiers :**
- Créer : `scripts/gen-contracts.sh`
- Créer : `service/README.grpcs.md` (notes d'installation de l'extension grpc)

- [ ] **Étape 1 : Écrire le script de génération**

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

- [ ] **Étape 2 : Générer et vérifier**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected : des fichiers de stubs tels que `Social/Infra/V1/InfraServiceClient.php` et `Social/Common/V1/Pong.php` existent.

- [ ] **Étape 3 : Dépendances PHP gRPC prêtes (à exécuter dans service et admin séparément)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **Étape 4 : Commiter**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### Tâche 4 : Squelette du service gRPC tonic dans infrastructure

**Fichiers :**
- Créer : `infrastructure/crates/social_grpc/Cargo.toml`
- Créer : `infrastructure/crates/social_grpc/build.rs`
- Créer : `infrastructure/crates/social_grpc/src/main.rs`
- Modifier : `infrastructure/Cargo.toml` (ajouter `"crates/social_grpc"` aux workspace members)

- [ ] **Étape 1 : Créer le crate**

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

- [ ] **Étape 2 : build.rs compile les contrats**

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

- [ ] **Étape 3 : Implémentation serveur de Ping**

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

- [ ] **Étape 4 : Ajouter au workspace et compiler**

Ajoutez `"crates/social_grpc"` aux members de `infrastructure/Cargo.toml`.

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected : compilation réussie, sans erreur.

- [ ] **Étape 5 : Commiter**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### Tâche 5 : Squelette webman dans service + client de sonde gRPC

**Fichiers :**
- Créer : `service/` (projet webman généré via composer)
- Créer : `service/app/controller/HealthController.php`
- Créer : `service/scripts/probe_ping.php`
- Modifier : `service/config/route.php`

- [ ] **Étape 1 : Générer le projet webman**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected : `service/app/`, `service/config/`, `service/vendor/`, `service/start.php` sont générés.

- [ ] **Étape 2 : Route de health check**

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

Ajouter à `service/config/route.php` :
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **Étape 3 : Script de sonde gRPC**

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

- [ ] **Étape 4 : Vérification locale**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected : `/health` renvoie `{"status":"ok","service":"social-service"}` ; la sonde affiche `pong from service`.

- [ ] **Étape 5 : Commiter**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### Tâche 6 : Recette de base d'admin

**Fichiers :**
- Créer : `docs/ADMIN_BASELINE.md`

- [ ] **Étape 1 : Dépendances et configuration**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected : vendor prêt ; .env configuré pour le MySQL/Redis local (ne pas modifier le fichier d'exemple du dépôt).

- [ ] **Étape 2 : Migrations et tests**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected : la suite de tests open-admin existante est entièrement verte (si le projet n'a pas d'entrée de test, le noter dans le document de base).

- [ ] **Étape 3 : Rédiger le document de base**

`docs/ADMIN_BASELINE.md` : consigner la version actuelle d'admin, son état de fonctionnement, les modules activés (JWT/RBAC/audit/fichiers/i18n), la disponibilité de l'extension grpc et les points d'entrée des évolutions futures (correspondant aux huit ajouts du §3.4 du doc. de conception).

- [ ] **Étape 4 : Commiter**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### Tâche 7 : Initialisation du projet iOS

> Cette machine est sous Linux et ne peut pas compiler un projet Xcode ; livrer le code source + la configuration xcodegen, et reporter la vérification de build au CI macOS (job réservé en T10 ; cette tâche ne bloque pas).

**Fichiers :**
- Créer : `apps/ios/project.yml` (xcodegen)
- Créer : `apps/ios/SocialApp/SocialAppApp.swift`
- Créer : `apps/ios/SocialApp/APIClient.swift`
- Créer : `apps/ios/SocialApp/ContentView.swift`
- Créer : `apps/ios/openapi-config.json`

- [ ] **Étape 1 : Configuration xcodegen**

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

- [ ] **Étape 2 : Squelette SwiftUI**

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

`apps/ios/SocialApp/APIClient.swift` (squelette de la couche réseau ; M1 branchera le client généré par OpenAPI) :
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

- [ ] **Étape 3 : Configuration de génération OpenAPI**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **Étape 4 : Vérification (plafond Linux)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected : générer le projet localement ou documenter clairement la raison du report.

- [ ] **Étape 5 : Commiter**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### Tâche 8 : Initialisation du projet Android

**Fichiers :**
- Créer : `apps/android/settings.gradle.kts`
- Créer : `apps/android/build.gradle.kts`
- Créer : `apps/android/gradle.properties`
- Créer : `apps/android/app/build.gradle.kts`
- Créer : `apps/android/app/src/main/AndroidManifest.xml`
- Créer : `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- Créer : `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- Créer : `apps/android/openapi-config.json`

- [ ] **Étape 1 : Squelette Gradle**

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

- [ ] **Étape 2 : Point d'entrée et couche réseau**

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

- [ ] **Étape 3 : Configuration de génération OpenAPI**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **Étape 4 : Vérification du build (Android SDK requis)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected : BUILD SUCCESSFUL, produisant `app/build/outputs/apk/debug/app-debug.apk`. Si cette machine n'a pas de SDK : noter l'exigence d'environnement et vérifier dans le CI.

- [ ] **Étape 5 : Commiter**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### Tâche 9 : Initialisation du projet HarmonyOS

> Si le DevEco CLI n'est pas disponible sur cette machine, livrer la structure du projet + un relevé des exigences d'environnement ; la vérification du build sera effectuée plus tard en environnement CI/DevEco.

**Fichiers :**
- Créer : `apps/harmonyos/build-profile.json5`
- Créer : `apps/harmonyos/oh-package.json5`
- Créer : `apps/harmonyos/hvigorfile.ts`
- Créer : `apps/harmonyos/AppScope/app.json5`
- Créer : `apps/harmonyos/entry/src/main/module.json5`
- Créer : `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- Créer : `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- Créer : `apps/harmonyos/openapi-config.json`

- [ ] **Étape 1 : Squelette du projet (API 12+, modèle Stage)**

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

- [ ] **Étape 2 : Point d'entrée et pages**

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

- [ ] **Étape 3 : Configuration de génération OpenAPI**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **Étape 4 : Vérification (plafond d'environnement)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **Étape 5 : Commiter**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### Tâche 10 : Sonde de bout en bout + CI entièrement vert (intégration lead)

**Fichiers :**
- Créer : `.github/workflows/ci.yml`
- Créer : `scripts/ci-probe.sh`

- [ ] **Étape 1 : Matrice CI**

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

- [ ] **Étape 2 : Script de sonde d'intégration**

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

- [ ] **Étape 3 : Exécuter le bout en bout en local**

```bash
bash scripts/ci-probe.sh
```
Expected : sortie `E2E OK` (health et ping gRPC passent tous les deux).

- [ ] **Étape 4 : Commiter**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## Notes d'auto-revue

- Couverture : contrats (T2/T3) → sonde (T4/T5/T10), initialisation des trois extrémités (T7/T8/T9), base d'admin (T6), CI (T10) — correspond à tout le contenu M0 du doc. de conception
- Espaces réservés : aucun (toutes les étapes contiennent de vraies commandes et du code)
- Cohérence des types : stubs PingRequest/Pong identiques sur les trois extrémités ; l'assertion `pong from {client}` est unifiée en T5/T10
