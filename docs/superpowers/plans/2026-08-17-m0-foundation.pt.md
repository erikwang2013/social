# Plano de implementação da fundação M0

**语言 / Languages:** [中文](2026-08-17-m0-foundation.md) · [English](2026-08-17-m0-foundation.en.md) · [한국어](2026-08-17-m0-foundation.ko.md) · [Русский](2026-08-17-m0-foundation.ru.md) · [Deutsch](2026-08-17-m0-foundation.de.md) · [Français](2026-08-17-m0-foundation.fr.md) · [Español](2026-08-17-m0-foundation.es.md) · [Português](2026-08-17-m0-foundation.pt.md) · [हिन्दी](2026-08-17-m0-foundation.hi.md) · [العربية](2026-08-17-m0-foundation.ar.md) · [বাংলা](2026-08-17-m0-foundation.bn.md) · [Bahasa Indonesia](2026-08-17-m0-foundation.id.md) · [日本語](2026-08-17-m0-foundation.ja.md)

> **Para trabalhadores agênticos:** SUB-HABILIDADE OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano tarefa por tarefa. As etapas usam a sintaxe de caixas de seleção (`- [ ]`) para acompanhamento.

**Objetivo:** estabelecer o esqueleto do monorepo, os contratos gRPC e o pipeline de geração de stubs para as três pontas, esqueletos executáveis dos quatro subsistemas, CI totalmente verde e a sonda gRPC de ponta a ponta service → infrastructure.

**Arquitetura:** o diretório de nível superior contracts/ (contratos proto, único ponto de entrada de geração) → buf gera stubs PHP (service, admin) e stubs Rust (infrastructure); service (webman v2) é o cliente gRPC, infrastructure (bee-rust + tonic) é o servidor gRPC; os três projetos nativos (iOS/Android/HarmonyOS) são inicializados separadamente e geram clientes via OpenAPI; CI de matriz do GitHub Actions.

**Stack de tecnologia:** PHP 8.3+ / webman v2 / extensão grpc / buf / protobuf / Rust (tonic + prost, workspace bee-rust) / xcodegen / Gradle (Android) / hvigor (HarmonyOS) / GitHub Actions

**Divisão de equipes (doc. de design §16, equipe M0):**
- `backend-service`：T1、T5
- `backend-admin`：T2、T3、T6
- `rust-infra`：T4
- `ios-dev` / `android-dev` / `harmonyos-dev`：T7 / T8 / T9
- `lead` (líder técnico): T10 fechamento da integração

---

### Tarefa 1: Convenções do repositório e README raiz

**Arquivos:**
- Criar: `README.md`
- Modificar: `.gitignore`

- [ ] **Passo 1: Verificar a cobertura do .gitignore**

```bash
grep -E 'vendor|node_modules|target|\.env|\.idea|build/' .gitignore
```
Expected: cada linha tem uma correspondência; adicione o que faltar (PHP `vendor/`, Rust `target/`, Android `build/`, Node `node_modules/`, `.env`).

- [ ] **Passo 2: Criar o README.md raiz**

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

- [ ] **Passo 3: Commit**

```bash
git add README.md .gitignore && git commit -m "chore: monorepo 骨架规范"
```

---

### Tarefa 2: Definição dos contratos gRPC em contracts

**Arquivos:**
- Criar: `contracts/buf.yaml`
- Criar: `contracts/common/types.proto`
- Criar: `contracts/infra/infra_service.proto`
- Criar: `contracts/user/user_service.proto`
- Criar: `contracts/admin/admin_service.proto`

- [ ] **Passo 1: Escrever o buf.yaml**

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

- [ ] **Passo 2: Escrever os tipos comuns (para a sonda Ping/Pong de ponta a ponta)**

`contracts/common/types.proto`:
```proto
syntax = "proto3";
package social.common.v1;

message Pong {
  string message = 1;
}
```

- [ ] **Passo 3: Escrever os três contratos de serviço**

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

`contracts/user/user_service.proto` (serviço público de service, chamado por admin; no M0 apenas stub de sonda):
```proto
syntax = "proto3";
package social.user.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service UserService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

`contracts/admin/admin_service.proto` (serviço público de admin; no M0 apenas stub de sonda):
```proto
syntax = "proto3";
package social.admin.v1;

import "common/types.proto";

message PingRequest { string client = 1; }

service AdminService {
  rpc Ping(PingRequest) returns (social.common.v1.Pong);
}
```

- [ ] **Passo 4: Validar**

```bash
cd contracts && buf lint && buf build
```
Expected: sem erros na saída, exit 0. Se o buf não estiver instalado: `go install github.com/bufbuild/buf/cmd/buf@latest` ou `brew install buf`.

- [ ] **Passo 5: Commit**

```bash
git add contracts/ && git commit -m "feat(contracts): gRPC 契约骨架与 Ping 探活"
```

---

### Tarefa 3: Pipeline de geração de stubs + preparação do PHP gRPC

**Arquivos:**
- Criar: `scripts/gen-contracts.sh`
- Criar: `service/README.grpcs.md` (notas de instalação da extensão grpc)

- [ ] **Passo 1: Escrever o script de geração**

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

- [ ] **Passo 2: Gerar e verificar**

```bash
chmod +x scripts/gen-contracts.sh && scripts/gen-contracts.sh
find service/generated -name '*.php' | head
```
Expected: arquivos de stub como `Social/Infra/V1/InfraServiceClient.php` e `Social/Common/V1/Pong.php` existem.

- [ ] **Passo 3: Dependências PHP gRPC prontas (executar em service e admin separadamente)**

```bash
cd service && composer require grpc/grpc google/protobuf
php -m | grep grpc || pecl install grpc
cd ../admin && composer require grpc/grpc google/protobuf
```

- [ ] **Passo 4: Commit**

```bash
git add scripts service/generated admin/generated && git commit -m "feat(contracts): buf 生成管线与 PHP 桩"
```

---

### Tarefa 4: Esqueleto do serviço gRPC tonic em infrastructure

**Arquivos:**
- Criar: `infrastructure/crates/social_grpc/Cargo.toml`
- Criar: `infrastructure/crates/social_grpc/build.rs`
- Criar: `infrastructure/crates/social_grpc/src/main.rs`
- Modificar: `infrastructure/Cargo.toml` (adicionar `"crates/social_grpc"` aos workspace members)

- [ ] **Passo 1: Criar o crate**

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

- [ ] **Passo 2: build.rs compila os contratos**

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

- [ ] **Passo 3: Implementação do servidor Ping**

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

- [ ] **Passo 4: Adicionar ao workspace e compilar**

Adicione `"crates/social_grpc"` aos members de `infrastructure/Cargo.toml`.

```bash
cd infrastructure && cargo build -p social_grpc
```
Expected: compilação bem-sucedida, sem erros.

- [ ] **Passo 5: Commit**

```bash
git add infrastructure && git commit -m "feat(infra): tonic gRPC Ping 服务骨架"
```

---

### Tarefa 5: Esqueleto webman em service + cliente de sonda gRPC

**Arquivos:**
- Criar: `service/` (projeto webman gerado via composer)
- Criar: `service/app/controller/HealthController.php`
- Criar: `service/scripts/probe_ping.php`
- Modificar: `service/config/route.php`

- [ ] **Passo 1: Gerar o projeto webman**

```bash
cd /home/wwwroot/social && composer create-project workerman/webman service
```
Expected: `service/app/`, `service/config/`, `service/vendor/`, `service/start.php` são gerados.

- [ ] **Passo 2: Rota de health check**

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

Acrescentar em `service/config/route.php`:
```php
Route::get('/health', [\app\controller\HealthController::class, 'index']);
```

- [ ] **Passo 3: Script de sonda gRPC**

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

- [ ] **Passo 4: Verificação local**

```bash
cd infrastructure && cargo run -p social_grpc &   # 终端 A
cd service && php start.php start &               # 终端 B
curl -s localhost:8787/health
php scripts/probe_ping.php
```
Expected: `/health` retorna `{"status":"ok","service":"social-service"}`; a sonda imprime `pong from service`.

- [ ] **Passo 5: Commit**

```bash
git add service && git commit -m "feat(service): webman 骨架与 gRPC 探活客户端"
```

---

### Tarefa 6: Aceitação da linha de base do admin

**Arquivos:**
- Criar: `docs/ADMIN_BASELINE.md`

- [ ] **Passo 1: Dependências e configuração**

```bash
cd admin && composer install && cp .env.example .env 2>/dev/null || true
```
Expected: vendor pronto; .env configurado para o MySQL/Redis local (não alterar o arquivo de exemplo do repositório).

- [ ] **Passo 2: Migrações e testes**

```bash
php think migrate:run 2>/dev/null || php artisan migrate 2>/dev/null || true
php tests/run.php 2>/dev/null || vendor/bin/phpunit
```
Expected: a suíte de testes open-admin existente está totalmente verde (se o projeto não tiver entrada de testes, registrar no documento de linha de base).

- [ ] **Passo 3: Escrever o documento de linha de base**

`docs/ADMIN_BASELINE.md`: registrar a versão atual do admin, o estado operacional, os módulos habilitados (JWT/RBAC/auditoria/arquivos/i18n), a prontidão da extensão grpc e os pontos de entrada da reforma futura (correspondentes às oito adições do §3.4 do doc. de design).

- [ ] **Passo 4: Commit**

```bash
git add docs/ADMIN_BASELINE.md && git commit -m "docs: admin 基线验收"
```

---

### Tarefa 7: Inicialização do projeto iOS

> Esta máquina é Linux e não consegue compilar um projeto Xcode; entregue o código-fonte + a configuração do xcodegen e deixe a verificação de build para o CI do macOS (job reservado em T10; esta tarefa não bloqueia).

**Arquivos:**
- Criar: `apps/ios/project.yml` (xcodegen)
- Criar: `apps/ios/SocialApp/SocialAppApp.swift`
- Criar: `apps/ios/SocialApp/APIClient.swift`
- Criar: `apps/ios/SocialApp/ContentView.swift`
- Criar: `apps/ios/openapi-config.json`

- [ ] **Passo 1: Configuração do xcodegen**

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

- [ ] **Passo 2: Esqueleto SwiftUI**

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

`apps/ios/SocialApp/APIClient.swift` (esqueleto da camada de rede; no M1 entra o cliente gerado por OpenAPI):
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

- [ ] **Passo 3: Configuração de geração OpenAPI**

`apps/ios/openapi-config.json`:
```json
{
  "generatorName": "swift5",
  "output": "Generated",
  "config": { "swiftPackagePath": ".", "useSPMFileStructure": true }
}
```

- [ ] **Passo 4: Verificação (limite do Linux)**

```bash
cd apps/ios && (xcodegen generate && echo "xcodeproj generated") || echo "xcodegen 不可用：跳过（macOS CI 验证）"
```
Expected: gerar o projeto localmente ou registrar claramente o motivo de pular.

- [ ] **Passo 5: Commit**

```bash
git add apps/ios && git commit -m "feat(ios): SwiftUI 工程骨架"
```

---

### Tarefa 8: Inicialização do projeto Android

**Arquivos:**
- Criar: `apps/android/settings.gradle.kts`
- Criar: `apps/android/build.gradle.kts`
- Criar: `apps/android/gradle.properties`
- Criar: `apps/android/app/build.gradle.kts`
- Criar: `apps/android/app/src/main/AndroidManifest.xml`
- Criar: `apps/android/app/src/main/java/com/social/app/MainActivity.kt`
- Criar: `apps/android/app/src/main/java/com/social/app/APIClient.kt`
- Criar: `apps/android/openapi-config.json`

- [ ] **Passo 1: Esqueleto Gradle**

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

- [ ] **Passo 2: Ponto de entrada e camada de rede**

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

- [ ] **Passo 3: Configuração de geração OpenAPI**

`apps/android/openapi-config.json`:
```json
{
  "generatorName": "kotlin",
  "output": "Generated",
  "config": { "library": "okhttp" }
}
```

- [ ] **Passo 4: Verificação de build (requer Android SDK)**

```bash
cd apps/android && ./gradlew assembleDebug
```
Expected: BUILD SUCCESSFUL, produzindo `app/build/outputs/apk/debug/app-debug.apk`. Se esta máquina não tiver SDK: registrar o requisito de ambiente e verificar no CI.

- [ ] **Passo 5: Commit**

```bash
git add apps/android && git commit -m "feat(android): Kotlin 工程骨架"
```

---

### Tarefa 9: Inicialização do projeto HarmonyOS

> Se esta máquina não tiver DevEco CLI, entregue a estrutura do projeto + um registro dos requisitos de ambiente; a verificação de build será feita depois no ambiente CI/DevEco.

**Arquivos:**
- Criar: `apps/harmonyos/build-profile.json5`
- Criar: `apps/harmonyos/oh-package.json5`
- Criar: `apps/harmonyos/hvigorfile.ts`
- Criar: `apps/harmonyos/AppScope/app.json5`
- Criar: `apps/harmonyos/entry/src/main/module.json5`
- Criar: `apps/harmonyos/entry/src/main/ets/entryability/EntryAbility.ets`
- Criar: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`
- Criar: `apps/harmonyos/openapi-config.json`

- [ ] **Passo 1: Esqueleto do projeto (API 12+, modelo Stage)**

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

- [ ] **Passo 2: Ponto de entrada e páginas**

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

- [ ] **Passo 3: Configuração de geração OpenAPI**

`apps/harmonyos/openapi-config.json`:
```json
{
  "generatorName": "typescript-fetch",
  "output": "Generated"
}
```

- [ ] **Passo 4: Verificação (limite do ambiente)**

```bash
cd apps/harmonyos && (hvigorw assembleHap && echo "HAP built") || echo "DevEco CLI 不可用：跳过（记录环境要求）"
```

- [ ] **Passo 5: Commit**

```bash
git add apps/harmonyos && git commit -m "feat(harmonyos): ArkTS 工程骨架"
```

---

### Tarefa 10: Sonda de ponta a ponta + CI totalmente verde (integração lead)

**Arquivos:**
- Criar: `.github/workflows/ci.yml`
- Criar: `scripts/ci-probe.sh`

- [ ] **Passo 1: Matriz CI**

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

- [ ] **Passo 2: Script de sonda de integração**

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

- [ ] **Passo 3: Executar o fluxo de ponta a ponta localmente**

```bash
bash scripts/ci-probe.sh
```
Expected: imprime `E2E OK` (tanto o health quanto o ping gRPC passam).

- [ ] **Passo 4: Commit**

```bash
git add .github scripts && git commit -m "ci: 矩阵 CI 与端到端探活"
```

---

## Registro de auto-revisão

- Cobertura: contratos (T2/T3) → sonda (T4/T5/T10), inicialização das três pontas (T7/T8/T9), linha de base do admin (T6), CI (T10) — corresponde a todo o conteúdo M0 do doc. de design
- Espaços reservados: nenhum (todas as etapas contêm comandos e código reais)
- Consistência de tipos: stubs PingRequest/Pong idênticos nas três pontas; a asserção `pong from {client}` é unificada em T5/T10
