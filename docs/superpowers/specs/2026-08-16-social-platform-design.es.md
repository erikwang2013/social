# Diseño general de la plataforma social (Social Platform Design)

**语言 / Languages:** [中文](2026-08-16-social-platform-design.md) · [English](2026-08-16-social-platform-design.en.md) · [한국어](2026-08-16-social-platform-design.ko.md) · [Русский](2026-08-16-social-platform-design.ru.md) · [Deutsch](2026-08-16-social-platform-design.de.md) · [Français](2026-08-16-social-platform-design.fr.md) · [Español](2026-08-16-social-platform-design.es.md) · [Português](2026-08-16-social-platform-design.pt.md) · [हिन्दी](2026-08-16-social-platform-design.hi.md) · [العربية](2026-08-16-social-platform-design.ar.md) · [বাংলা](2026-08-16-social-platform-design.bn.md) · [Bahasa Indonesia](2026-08-16-social-platform-design.id.md) · [日本語](2026-08-16-social-platform-design.ja.md)

- Fecha: 2026-08-16
- Estado: confirmado, pendiente de implementación
- Alcance: comunidad de contenido corto (imagen+texto) + mensajería instantánea + streaming/voz + economía virtual, multilingüe, multirregional global

## 1. Objetivos y alcance

Construir una plataforma social que combine contenido corto de imagen+texto e IM, con streaming (vídeo + danmaku + micrófono compartido), voz (mensajes / llamadas 1v1 / salas de voz) y una economía virtual de regalos-propinas. Soporte de UI multilingüe, traducción de contenido y cumplimiento multirregional, con despliegue en múltiples regiones del mundo. Desarrollo nativo paralelo en tres plataformas: iOS / Android / HarmonyOS.

## 2. Visión general del sistema

```
                    ┌─────────────────────────────────────────────┐
                    │   iOS (SwiftUI) │ Android (Kotlin+Compose)  │
                    │            HarmonyOS (ArkTS)                │
                    └───────┬─────────────────────────┬───────────┘
                            │  HTTPS / WSS（多区域就近接入）
                  ┌─────────▼──────────┐   ┌──────────────────────┐
                  │  CDN + 区域接入层   │   │ 厂商推送 APNs/FCM/华为 │
                  └─────────┬──────────┘   └──────────────────────┘
              ┌─────────────▼─────────────┐
              │   service (webman v2)     │──gRPC──▶ infrastructure
              │  业务单体：认证/资料/动态/ │          (bee-rust)
              │  点赞/评论/关注/IM 网关/   │  gRPC      搜索/推荐/图/
              │  翻译调度/审核/直播/语音/  │  ▲        时序/热数据
              │  虚拟经济                 │  │ gRPC
              └─────────────┬─────────────┘  │
                  ┌─────────▼─────────┐      │
                  │ MySQL + Redis     │      │
                  │ S3 对象存储       │      │
                  └───────────────────┘      │
              ┌──────────────────────────────┴──┐
              │   admin (open-admin 改造, webman) │
              │  审核台/举报/GDPR/看板/词条/礼物库/ │
              │  直播配置/提现审核                 │
              └──────────────────────────────────┘

  media/（自建媒体层，信令走 service WS 网关）
  ├── sfu/    mediasoup：1v1 通话、语聊房
  ├── srs/    SRS：自建直播（RTMP → FFmpeg 转码 → FLV/HLS）
  └── coturn/ TURN 中继

  外部：第三方直播云（推流/转码/CDN/实时审核）、第三方 RTC（连麦）、
        第三方审核 API、商店 IAP（App Store / Google Play / 华为）
```

## 3. Responsabilidades de los subsistemas

### 3.1 contracts (contratos gRPC, nuevo directorio de nivel superior)

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- Pipeline de generación: la CI genera con buf tres tipos de stubs y los commitea en sus respectivos sub-repos (los builds no dependen de la red)
  - service/, admin/ → stubs PHP (grpc/grpc + google/protobuf)
  - infrastructure/ → stubs Rust (tonic)
- Regla de versionado: solo añadir campos, nunca modificar ni eliminar; el nombre del paquete lleva la versión mayor (`social.user.v1`)

### 3.2 service (webman v2) — monolito de negocio del lado del usuario

- **Dominios de API**: auth (JWT doble token + lista negra), profile, posts, likes, comments, follows, IM (conversaciones/mensajes/pasarela WS), notifications, programación de traducción, señalización de salas/danmaku/micrófono compartido, señalización de llamadas de voz/salas de voz, economía virtual (monedero/regalos/verificación IAP/participación de ingresos), exportación/borrado GDPR
- **Sistema de errores multilingüe**: los errores devuelven `{code, lang_key, params}`; los textos los renderiza el cliente según locale
- **Colas** (redis-queue): disparadores de moderación, programación de traducción, entrega de push, estadísticas asíncronas, difusión de efectos de regalos
- **Tareas programadas** (webman-crontab): precalentamiento de traducciones, limpieza de tokens/mensajes caducados, archivado de auditoría, liquidación de participaciones
- **IDs**: `erikwang2013/snowflake-php` (coherente con admin)
- **Contratos**: exportación automática OpenAPI 3.0 → generación de clientes tipados para las tres plataformas

### 3.3 infrastructure (bee-rust) — capa de cómputo de alto rendimiento

No almacena datos primarios de negocio (MySQL es la única fuente de verdad); asume capacidades pesadas en cómputo/consultas:

- `bee_search`: búsqueda de texto completo de publicaciones/usuarios (segmentación de palabras chinas, indexación multilingüe)
- `bee_graph`: grafo social → feed de recomendaciones
- `bee_tsdb`: estadísticas de series temporales: DAU, publicaciones, interacciones, visionado de streaming, duración de llamadas de voz, etc.
- `bee_cache/bee_kv`: caché de timeline, contadores (likes, visualizaciones, usuarios en línea)
- Desplegado por región, muchas lecturas/pocas escrituras, datos replicados desde el sitio central

### 3.4 admin (reforma de open-admin)

**Reutilizado**: infraestructura JWT/RBAC/auditoría/gestión de archivos/health checks/i18n zh-en

**Nuevo**:
- Workbench de moderación de contenido: revisión bilingüe lado a lado de publicaciones/comentarios/imágenes, plantillas multilingües de motivos de rechazo, sanciones a usuarios
- Cola de gestión de denuncias
- Mostrador de solicitudes GDPR (tickets de exportación/borrado)
- Paneles de datos respaldados por bee_tsdb
- Gestión de términos i18n (CRUD de términos compartidos por los cuatro clientes)
- Gestión del catálogo de regalos (SKU, precios, efectos, nombres multilingües)
- Configuración de providers de streaming (estrategia de enrutamiento, orden de conmutación)
- Revisión de solicitudes de retiro

### 3.5 media (capa de medios autoalojada, Node.js + servicios de sistema)

- `sfu/`: mediasoup; soporta el plano de medios de llamadas 1v1 y salas de voz; solo reenvío de medios, sin lógica de negocio
- `srs/`: SRS autoalojado para streaming; ingesta RTMP → transcodificación FFmpeg → distribución HTTP-FLV/HLS
- `coturn/`: relay TURN, respaldo para el cruce de NAT
- Toda la señalización se reenvía a través de la pasarela WS de service

### 3.6 apps — desarrollo nativo paralelo en tres plataformas

- Contrato OpenAPI compartido; cada plataforma genera su propio cliente tipado
- Módulos de infraestructura unificados: capa de red (reintentos/refresco de autenticación), cliente WS (señalización IM/danmaku/llamadas), i18n (recursos locales + términos remotos incrementales), registro de push, temas
- Notas HarmonyOS: Huawei Push Kit, adaptación al modelo de concurrencia de ArkTS

## 4. Comunicación de backend (gRPC)

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| Llamante → Llamado | Contenido |
|------|------|
| service → infra | búsqueda de texto completo, feed de recomendaciones, caché caliente de timeline, lectura/escritura de contadores, escritura de estadísticas temporales |
| admin → infra | consultas de estadísticas para paneles, búsqueda de backend |
| admin → service | sanciones a usuarios, borrado de contenido, entrega de resultados de moderación |
| service → admin | eventos de denuncia, encolado de tareas de moderación (async) |

Frontera: las apps de las tres plataformas y el frontend de administración (Flutter) usan HTTPS REST + WS y nunca tocan gRPC directamente.

**Requisito operativo**: gRPC en el lado PHP requiere la extensión oficial `grpc` (extensión C) + el paquete composer `grpc/grpc`; el modo servidor sigue el esquema oficial walkor/grpc de workerman; la documentación de despliegue debe especificarlo.

## 5. Arquitectura multilingüe (tres capas)

| Capa | Enfoque |
|----|------|
| **Capa UI** | recursos de locale por plataforma (inicio zh/en; el sistema soporta cualquier idioma); el servidor solo envía códigos de error + claves de plantilla |
| **Capa de contenido** | al publicar, guardar el original + detección automática de idioma escrita en el campo `lang`; al leer, reader.lang ≠ author.lang → servicio de traducción (abstracción LLM/MT provider), resultados cacheados en Redis (bee_cache, TTL), bandera `is_translated` para volver al original; precalentamiento programado del contenido popular |
| **Capa de cumplimiento** | reglas de moderación aplicadas por región (reglas GDPR de la UE vs otras regiones); UI bilingüe de denuncias/moderación |

El danmaku es texto corto en tiempo real: sin traducción de contenido, solo i18n de UI + filtrado multilingüe de palabras sensibles.

## 6. Arquitectura de IM

- **Pasarela**: pasarela WS de webman, múltiples instancias con reenvío entre nodos por Redis pub/sub, deduplicación idempotente con `client_msg_id`
- **Datos**: conversations / conversation_members / messages / message_reads; chats privados + grupales (límite de grupo 500)
- **Entrega**: en línea → push directo por WS; sin conexión → push APNs/FCM/Huawei
- **Capacidades**: confirmaciones de lectura, indicador de escritura, retiro con límite de tiempo, mensajes de imagen/voz (subida a S3 + transcodificación)
- Comparte el sistema de usuarios y notificaciones con el feed

## 7. Arquitectura de streaming (vídeo + danmaku + micrófono compartido, doble vía)

### 7.1 Abstracción de provider (dentro de service)

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| Mecanismo | Diseño |
|------|------|
| Estrategia de enrutamiento | provider por defecto elegido por región al crear la sala (sobrescribible por admin); regiones sin cobertura de terceros o sensibles al coste → autoalojado |
| Tolerancia a fallos | doble ingesta con el SDK del streamer (principal = terceros, respaldo = SRS propio); los reproductores resuelven la URL por provider y cambian automáticamente al flujo propio si falla el tercero |
| Danmaku/micrófono compartido | desacoplados del pipeline de vídeo: el danmaku va por el WS de service, el micrófono compartido por RTC de terceros |
| Cumplimiento | la moderación de audio/vídeo en tiempo real del pipeline propio reutiliza las APIs de moderación de terceros (solo se compra la moderación, no el transporte) |

### 7.2 Salas de streaming

CRUD de salas, máquina de estados de inicio/fin de emisión, portada, anuncios (multilingües), contadores de visualización (bee_tsdb), canales de danmaku de la sala (Redis pub/sub), gestión de roles de micrófono compartido (anfitrión/asientos, service emite tokens RTC de terceros), estadísticas en línea/pico/duración → paneles de admin.

## 8. Arquitectura de voz (trío)

| Forma | Implementación |
|------|------|
| Mensajes de voz | extensión del tipo de mensaje IM: almacenamiento S3 + transcodificación (m4a) + duración |
| Llamadas 1v1 | señalización por la pasarela WS (offer/answer/ICE), máquina de estados de llamada/contestación/colgado (Redis), plano de medios por mediasoup, registros de llamadas en base de datos |
| Salas de voz | la gestión de salas reutiliza el patrón de salas de streaming; estados de micrófono/oyentes gestionados por service; plano de medios por mediasoup |

## 9. Economía virtual (recargas + propinas-regalos + retiros)

```
移动端 IAP（App Store/Google Play/华为）──┐
国内：微信支付 / 支付宝（APP/H5）          ├─▶ PaymentProvider ─▶ 钱包
国外：微信国际 / 支付宝国际 / Stripe / PayPal│    （按 region 选路）
                                          └─▶ payments 支付单（幂等+验签+对账）
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
主播钱包 ──▶ payouts 提现单 ──▶ 国内：商家转账 │ 国外：Stripe Connect/PayPal
```

### 9.1 Canales de pago (nacional vs internacional)

```
PaymentProvider 接口（admin 配置）
├── 国内（CNY）
│   ├── wechat_cn    微信支付（APP/H5）
│   ├── alipay_cn    支付宝（APP/WAP）
│   └── 提现：商家转账（零钱/银行卡）
├── 国外（USD/EUR/...）
│   ├── wechat_global  微信国际支付（境外商户）
│   ├── alipay_global  支付宝国际（Alipay+）
│   ├── stripe         卡 / Apple Pay / Google Pay / SEPA
│   ├── paypal
│   └── 提现：Stripe Connect / PayPal 批量打款
└── 移动端虚拟币充值：App Store / Google Play / 华为 IAP（商店政策强制，服务端凭证校验）
```

| Mecanismo | Diseño |
|------|------|
| Enrutamiento de canales | elección de canal por región del usuario + moneda + reglas de merchant de admin, con orden de respaldo configurable (separación natural nacional/internacional) |
| Orden de pago | modelo payments unificado: usuario/canal/importe/moneda/máquina de estados, idempotente en todos los canales |
| Callbacks | envoltorio unificado de verificación de firma (RSA/HMAC), callbacks idempotentes, tarea diaria de conciliación (contraste con los extractos de los canales) |
| Retiros | órdenes payouts: transferencia de merchant nacional, pago internacional Stripe Connect/PayPal; modo de división/desembolso según la capacidad del canal |
| Precios | tablas de precios regionales (admin): moneda virtual × precios en divisas, tipos de cambio gestionados centralmente |
| Control de riesgo | límites/frecuencia/alertas de órdenes anómalas, auditoría completa del flujo (reutiliza el sistema de auditoría) |
| SKU de regalos | catálogo de regalos (precios, identificadores de efectos, nombres multilingües) gestionado por admin |

Cumplimiento: las recargas de moneda virtual en móvil deben pasar por el IAP de las tiendas (comisión de Apple/Google/Huawei); WeChat/Alipay se usan para H5/Web y escenarios regionales específicos; los retiros implican liquidación de fondos, por lo que la plataforma los resuelve mediante interfaces de división/desembolso de canales con licencia; la cualificación contractual de los canales se confirma antes de M6b; los límites para menores entran en la fase de cumplimiento.

## 10. Modelos de datos principales

- Usuarios: users, user_profiles (campos multilingües)
- Social: follows, posts, post_translations, comments, comment_translations, likes, reports
- IM: conversations, conversation_members, messages, message_reads
- Streaming: live_rooms, live_streams (con provider), danmaku_archive
- Voz: call_records, voice_rooms, voice_room_members
- Economía virtual: wallets, currency_transactions, gift_catalog, gifts_given, streamer_earnings, withdrawals, payments, payouts, price_plans (precios regionales/tipos de cambio), merchant_configs (configuraciones de merchant por canal), products (SKU IAP)
- Plataforma: i18n_terms (términos compartidos por los cuatro clientes), moderation_queue, provider_configs, audit_logs

## 11. Elección de bases de datos y almacenamiento

| Uso | Almacenamiento | Componente |
|------|------|----------|
| Datos primarios de negocio (usuarios/publicaciones/IM/monedero/moderación/denuncias) | MySQL 8 (maestro central + réplicas de solo lectura regionales) | compartido por service y admin; única fuente de verdad |
| Datos calientes/sesiones/estados en línea/contadores/canales de danmaku/máquinas de estado de llamadas | Redis 7 | bee_kv / bee_cache (feature redis) |
| Búsqueda de texto completo (publicaciones/usuarios, búsqueda del backend de admin) | OpenSearch (inicio de un solo nodo) | bee_search (feature opensearch) |
| Estadísticas temporales (DAU/tendencias/visionado de streaming/duración de llamadas/paneles) | QuestDB (inicio con un binario) | bee_tsdb (feature questdb, sustituible por influxdb) |
| Grafo social → feed de recomendaciones | Neo4j Community (inicio de un solo nodo) | bee_graph (feature neo4j, sustituible por nebulagraph) |
| Archivos de objetos (imágenes/vídeos/voz/paquetes de exportación) | S3 (MinIO o proveedor de nube) | acceso directo de service + distribución por CDN |
| Registros de auditoría | MySQL audit_logs, archivados en almacenamiento de objetos al vencer | reutiliza el sistema de auditoría de admin |

Principios de selección: los componentes de bee-rust son abstracciones con feature flags — inicio de un solo nodo, sustitución por backends distribuidos al crecer, sin bloqueo; MySQL siempre es la única fuente de verdad; la capa de cómputo (índices/estadísticas/grafo/caché) solo almacena datos derivados reconstruibles. El frontend de administración (Flutter) nunca toca la base de datos directamente; todo pasa por el backend de admin.

## 12. Despliegue y operación (multirregional global)

- **Arquitectura inicial**: dos grandes regiones — China + extranjero; cada región con clúster webman + clúster bee-rust + Redis local + media (SFU/SRS/TURN); maestro central MySQL + réplicas de solo lectura por región; CDN por región
- **Acceso WS más cercano**, mensajes entre regiones coordinados centralmente; push por el proveedor correspondiente según la región
- **Ruta de evolución**: tras el crecimiento del tráfico, sharding de bases por hash de usuario
- **Monitorización**: métricas Prometheus (siguiendo el patrón de open-admin), logs centralizados, alertas (tasa de error/latencia/acumulación de colas/salud de los servicios de medios)

## 13. Seguridad y cumplimiento

- service replica el modelo de defensa de 18 capas de open-admin (XSS/SQLi/CSRF/límite de peticiones/CSP)
- Pipeline de moderación: filtro multilingüe de palabras sensibles al publicar → moderación de imagen/audio-vídeo (APIs de terceros) → moderación humana
- GDPR: exportación de datos, derecho de cancelación/borrado, política de retención de logs, umbral de edad para menores, reglas diferenciadas por región

## 14. Hitos (full-stack en solitario, ~9–10 meses)

| Fase | Contenido | Duración |
|------|------|------|
| M0 Cimientos | esqueleto monorepo, contracts(gRPC)+generación de stubs de las tres plataformas+sondas de vida de extremo a extremo, inicialización de los proyectos de las tres plataformas, CI (build+test), esqueleto de servicios bee-rust | 1–2 semanas |
| M1 Bucle cerrado | registro/login/perfil, publicación/detalle, timeline simplificado, likes y comentarios | 3–4 semanas |
| M2 Social completo | sistema de seguidores, feed completo, búsqueda de texto completo (bee_search), notificaciones | 3–4 semanas |
| M3 IM | pasarela WS, conversaciones, mensajes, push offline, lectura/retiro | 4–6 semanas |
| M4 Voz | componentes media (mediasoup+coturn), mensajes de voz, llamadas 1v1, salas de voz | 4–5 semanas |
| M5a Streaming principal | pipeline de terceros, salas de streaming, danmaku, micrófono compartido | 3–4 semanas |
| M5b Streaming complementario | integración SRS propio, conmutación por error de doble ingesta, configuración de enrutamiento | 2 semanas |
| M6a Moneda virtual+regalos | IAP, monedero, regalos, participación de ingresos | 2–3 semanas |
| M6b Canales de pago | WeChat/Alipay/WeChat Global/Alipay Global/Stripe/PayPal, retiros, conciliación | 3–4 semanas |
| M7 Multilingüe+cumplimiento | i18n en todas las plataformas, traducción de contenido, workbench de moderación, GDPR, integración de moderación de audio/vídeo | 3–4 semanas |
| M8 Lanzamiento | despliegue en dos regiones (incl. TURN regional), monitorización/alertas, pruebas de carga, revisión de seguridad | 2–3 semanas |

Cada hito es una rebanada entregable de forma independiente; el proyecto puede detenerse en cualquier momento y el producto queda siempre totalmente utilizable.

## 15. Resumen del stack tecnológico

| Subsistema | Tecnología |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / extensión grpc / snowflake-php |
| infrastructure | Rust / workspace bee-rust (search/graph/tsdb/kv/cache) / tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| Externo | nube de streaming de terceros, RTC de terceros, APIs de moderación de terceros, WeChat Pay/Alipay/WeChat Pay Global/Alipay Global/Stripe/PayPal, IAP de App Store/Google Play/Huawei, push APNs/FCM/Huawei |

## 16. Planificación del equipo (personal real, ritmo estable)

### 16.1 Estructura organizativa

```
技术负责人 / PM（1人，兼任 contracts 契约 owner）
├── 后端组（2人）       webman service 主力 + admin 改造/支付专项
├── 平台组（2人）       Rust ×1（infrastructure）、音视频 ×1（media）
├── 客户端组（3人）     iOS、Android、HarmonyOS 各 1
├── 质量与运维（2人）   QA ×1、DevOps ×1
└── 支持（弹性）        UI/UX ×1（常驻）、支付/合规顾问（按需）、本地化（外包）
```

### 16.2 Detalle de roles

| Rol | Personas | Responsabilidades | Habilidades clave | Incorporación |
|------|---|------|----------|------|
| Tech lead/PM | 1 | owner de contracts(gRPC), coordinación entre subsistemas, avance de hitos | PHP/arquitectura/gestión de proyectos | M0 |
| Backend PHP · service | 1 | auth/publicaciones/pasarela WS IM/señalización de streaming y voz/programación de traducción/disparadores de moderación/GDPR | webman/Redis/MySQL/WS | M0 |
| Backend PHP · admin+pagos | 1 | reforma de los 8 módulos de open-admin, PaymentProvider todos los canales, conciliación, retiros | PHP/experiencia en canales de pago | M0 (pagos M6) |
| Ingeniero iOS | 1 | cliente SwiftUI, APNs, WS, integración WebRTC, i18n | Swift/SwiftUI | M0 |
| Ingeniero Android | 1 | Kotlin+Compose, FCM, WS, WebRTC, i18n | Kotlin/Compose | M0 |
| Ingeniero HarmonyOS | 1 | cliente ArkTS, Push Kit, i18n | ArkTS/ecosistema HarmonyOS | M0 |
| Ingeniero Rust | 1 | servicificación de bee-rust (search/graph/tsdb) + gRPC tonic | Rust/axum/tonic | final de M1 |
| Ingeniero de audio/vídeo | 1 | componentes media (mediasoup/SRS/FFmpeg/coturn), conmutación de doble ingesta, despliegue regional de TURN | Node.js/WebRTC/SRS/transcodificación | final de M3 |
| Diseñador UI/UX | 1 | sistema de diseño de las tres plataformas, visuales de streaming/regalos/voz, normas de textos i18n | Figma/diseño multilingüe | M0 |
| QA | 1 | regresión de tres plataformas+backend+medios, pruebas de carga, validación de moderación/pagos | pruebas móvil/API | M1 |
| DevOps | 1 | CI/CD, despliegue en dos regiones, monitorización Prometheus, operación de servicios de medios, logs | Docker/K8s/Prometheus | M2 |
| Asesor de pagos/finanzas | flexible | cualificación contractual de canales, reglas de conciliación, límites de riesgo, liquidación de participaciones | industria de pagos/finanzas | desde M6 |
| Asesor de cumplimiento/legal | flexible | GDPR, regulaciones regionales, reglas de moderación de contenido, políticas de tiendas | cumplimiento de datos | desde M7 |
| Localización | externalizada | traducción y revisión de términos, textos multilingües | traducción/revisión | desde M7 |

### 16.3 Ritmo de hitos

| Fase | Equipo | Foco paralelo |
|------|------|----------|
| M0–M2 | líder+2 backend+3 móvil+diseño+QA | contratos primero; tres plataformas en paralelo sobre OpenAPI; Rust se incorpora para la búsqueda |
| M3–M4 | +audio/vídeo, DevOps | audio/vídeo construye media en paralelo con IM/voz |
| M5 | equipo completo | streaming de doble vía; el backend apoya a media |
| M6 | +asesor de pagos | vía de pagos+conciliación |
| M7 | +asesor de cumplimiento, localización | i18n en todas las plataformas+cierre de cumplimiento |
| M8 | equipo completo, garantía | lanzamiento en dos regiones, pruebas de carga, revisión de seguridad |

### 16.4 Prioridades de contratación

1. Backend PHP ×2 + tech lead (núcleo del periodo de cimientos; el backend es el área de mayor volumen de trabajo)
2. Móvil ×3 (el paralelismo de las tres plataformas es la restricción dura del plazo total — cuanto antes, mejor)
3. UI/UX, QA
4. Rust, DevOps (incorporación antes de M1–M2)
5. Audio/vídeo (final de M3)
6. Asesores de pagos/cumplimiento, localización (bajo demanda en M6/M7)

### 16.5 Riesgos y respaldos

- Audio/vídeo y canales de pago son los dos roles más difíciles de contratar (expertos escasos); reservar planes de respaldo por externalización/asesores
- Si es difícil contratar un ingeniero de HarmonyOS, un ingeniero de Android puede cubrirlo primero (ArkTS comparte raíces con TS y se aprende rápido); el ritmo paralelo de las tres plataformas no se ve afectado
