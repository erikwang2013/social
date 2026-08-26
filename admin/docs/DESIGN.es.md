# Consola de administración abierta — Documento de diseño

**语言 / Languages:** [中文](DESIGN.md) · [English](DESIGN.en.md) · [한국어](DESIGN.ko.md) · [Русский](DESIGN.ru.md) · [Deutsch](DESIGN.de.md) · [Français](DESIGN.fr.md) · [Español](DESIGN.es.md) · [Português](DESIGN.pt.md) · [हिन्दी](DESIGN.hi.md) · [العربية](DESIGN.ar.md) · [বাংলা](DESIGN.bn.md) · [Bahasa Indonesia](DESIGN.id.md) · [日本語](DESIGN.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

> Para ver los diagramas Mermaid detallados, consulte [ARCHITECTURE.md](ARCHITECTURE.md) (se renderizan automáticamente en GitHub/GitLab/VS Code).

## 1. Arquitectura del sistema

> **Lista de funciones**: Autenticación (login/register/refresh/logout + bloqueo de cuenta + límite de sesiones) | Panel de control (caché Redis) | CRUD de usuarios + lote + importación | Roles y permisos (RBAC) | Configuración del sistema | Auditoría de operaciones (8 orígenes de plataforma) | Archivos (carga + exportación + enmascaramiento) | Seguridad (defensa en 18 capas) | Operaciones (health/metrics/docs/Docker/CI)

```
┌──────────────────────────────────────────────────────────────┐
│                        客户端层                               │
│  ┌──────────────────────┐  ┌──────────────────────────────┐  │
│  │  Flutter Web (PC)    │  │  HarmonyOS ArkTS (Mobile)    │  │
│  │  管理后台 (桌面风格)   │  │  客户端 (手机/平板/2in1)      │  │
│  └──────────┬───────────┘  └──────────────┬───────────────┘  │
└─────────────┼──────────────────────────────┼─────────────────┘
              │        HTTPS / JSON          │
              │   Authorization: Bearer JWT  │
┌─────────────┼──────────────────────────────┼─────────────────┐
│             ▼                              ▼                  │
│  ┌──────────────────────────────────────────────────────┐    │
│  │                   API 网关层                          │    │
│  │  AdminAuth(认证) → AdminPermission(授权) → Controller │    │
│  └──────────────────────────┬───────────────────────────┘    │
│                             │                                  │
│  ┌──────────────────────────┼───────────────────────────┐    │
│  │              业务逻辑层 (Controller/Service)           │    │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐ │    │
│  │  │Dashboard │ │  User    │ │  Role    │ │ Export  │ │    │
│  │  │Controller│ │Controller│ │Controller│ │Controller│ │    │
│  │  └────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬────┘ │    │
│  └───────┼────────────┼─────────────┼────────────┼──────┘    │
│          │            │             │            │            │
│  ┌───────┼────────────┼─────────────┼────────────┼──────┐    │
│  │       ▼            ▼             ▼            ▼       │    │
│  │                   Model 层                            │    │
│  │  ┌──────────────────────────────────────────────┐    │    │
│  │  │  Snowflake ID ← encryptable → Encryption     │    │    │
│  │  │  (主键生成)     (DB字段加密)   (API传输加密)    │    │    │
│  │  └──────────────────────────────────────────────┘    │    │
│  └──────────────────────────┬───────────────────────────┘    │
│                             │                                  │
│  ┌──────────────────────────┼───────────────────────────┐    │
│  │              数据存储层                                │    │
│  │  ┌──────────┐  ┌──────────────┐  ┌──────────┐        │    │
│  │  │  MySQL   │  │ Elasticsearch│  │  Redis   │        │    │
│  │  │ (主存储)  │  │ (全文检索)    │  │ (缓存)   │        │    │
│  │  └──────────┘  └──────────────┘  └──────────┘        │    │
│  └──────────────────────────────────────────────────────┘    │
│                       webman v2                               │
└──────────────────────────────────────────────────────────────┘
```

## 2. Arquitectura del backend

### 2.1 Diseño por capas

| Capa | Directorio | Responsabilidad |
|------|------|------|
| Rutas | `config/route.php` | Mapeo de URL a controladores, vinculación de middleware, rutas versionadas |
| Middleware | `app/middleware/` | Intercepción de ataques (SecurityFilter), límite de frecuencia (RateLimit), autenticación (JWT), autorización (RBAC), versión de API (ApiVersion) |
| Controladores | 14: Dashboard/User/Role/Permission/Config/Log/Profile/Export/Import/Upload/Health/Docs (panel de administración) + Captcha/Auth (API v1) | Validación de parámetros de solicitud, invocación de la lógica de negocio, formateo de respuestas |
| Servicios de negocio | `app/service/` | Lógica de negocio reutilizable (reservado) |
| Modelos de datos | `app/model/` | Mapeo ORM, relaciones, cifrado/descifrado de campos |
| Utilidades comunes | `app/common/` | Servicios Hashids, Snowflake, Encryption |

### 2.2 Ciclo de vida de la solicitud

```
客户端请求
  │
  ▼
webman HTTP Server (workerman)
  │
  ▼
Route 匹配
  │
  ▼
中间件链:
  Locale ──────────────► Accept-Language / ?lang= 语言检测
  │
  ▼
SecurityFilter ──────► HTTP方法检查 → 405 (仅允许 GET/POST/PUT/DELETE/OPTIONS/HEAD)
  │                     XSS/SQL注入/路径遍历/命令注入/CSRF 攻击拦截 (403)
  ▼
RateLimit ───────────► Redis 滑动窗口限流
  │ (失败返回 429 + Retry-After 头)
  ▼
ApiVersion ─────────► API-Version 头校验，注入 $request->apiVersion
  │ (失败返回 400)
  ▼
AdminAuth ──────────► JWT 验证，注入 $request->adminId
  │ (失败返回 401)
  ▼
AdminPermission ────► RBAC 权限校验（Redis 60s 缓存）
  │ (失败返回 403)
  ▼
OperationLog ───────► 操作日志记录 (POST/PUT/DELETE)，自动检测来源端
  │
  ▼
Controller::method()
  │
  ├─► 参数验证 (validator)
  ├─► 敏感操作确认 (confirmPassword)
  ├─► decodeId() — hashid → BIGINT
  ├─► Model 操作 (自动 encryptable 加解密)
  ├─► encodeId() — BIGINT → hashid
  └─► Response JSON
```

### 2.3 Ciclo de vida del ID

```
生成 (Snowflake) → 存储 (MySQL BIGINT) → 传输 (Hashids 编码) → 外部 (hash 字符串)
                                                                    │
                            HashidsService::decode() ←──────────────┘
```

### 2.4 Sistema de cifrado de datos

```
传输层 (encryption)     — AES-256-CBC，独立密钥
存储层 (encryptable)    — AES-128-ECB，独立密钥，Model $casts 自动处理
展示层 (mask)           — 手机号: 138****1234，邮箱: a***@example.com
```

## 3. Diseño de la base de datos

### 3.1 Relaciones ER

```
erik_admin_user ──┬── erik_admin_user_role ──┬── erik_admin_role
  (用户)           │    (用户-角色关联)         │     (角色)
                  │                          │
                  │                    erik_admin_role_permission
                  │                     (角色-权限关联)
                  │                          │
                  │                          ▼
                  │                    erik_admin_permission
                  │                      (权限/菜单)
                  │
                  ▼
           erik_operation_log
             (操作日志)

erik_system_config (系统配置) — 独立表
```

### 3.2 Estructura de las tablas principales

| Nombre de tabla | N.º de campos | Descripción |
|------|-------|------|
| `erik_admin_user` | 14 | Usuarios de administración, phone/email/id_card almacenados cifrados, soporta borrado suave |
| `erik_admin_role` | 7 | Roles, slug único |
| `erik_admin_permission` | 10 | Árbol de permisos (parent_id autorreferencial), type: 1=menú 2=botón 3=API |
| `erik_admin_user_role` | 2 | Tabla intermedia muchos a muchos usuario-rol |
| `erik_admin_role_permission` | 2 | Tabla intermedia muchos a muchos rol-permiso |
| `erik_system_config` | 8 | Configuración clave-valor, group+key único conjunto |
| `erik_operation_log` | 9 | Registro de auditoría de operaciones (incluye el campo source de origen) |

### 3.3 Normativa de claves primarias

- Tipo: `BIGINT UNSIGNED NOT NULL`
- Característica: **no autoincremental**, generada por el algoritmo Snowflake en la capa de aplicación
- Ventajas: único globalmente, apta para sistemas distribuidos, incremento tendencial favorable para índices, no expone el volumen de negocio
- Configuración: datacenter_id(0-31) + worker_id(0-31), soporta 1024 nodos concurrentes

## 4. Diseño de API

### 4.1 Normativa de URL

```
公开接口:  /api/captcha/{generate|verify}
           /api/auth/{login|register|refresh}

管理端:   /admin/{resource}[/{hashid}]
          /admin/export/{excel|pdf}

资源路由:
  GET    /admin/user          → 列表
  POST   /admin/user          → 创建
  GET    /admin/user/{hashid} → 详情
  PUT    /admin/user/{hashid} → 更新
  DELETE /admin/user/{hashid} → 删除（需密码确认）

系统配置:  /admin/config[/{hashid}]
操作日志:  /admin/log
个人中心:  /admin/profile[/password|/logout]
导入:     /admin/import/users
上传:     /admin/upload
批量:     /admin/user/batch/{destroy|status}
文档:     /api/docs     (OpenAPI 3.0)
健康:     /health
```

### 4.2 Estrategia de versionado de API

El versionado de la API se controla mediante cabeceras de solicitud, **no se refleja en la ruta URL**:

```http
API-Version: v1
```

| Mecanismo | Descripción |
|------|------|
| Versión por defecto | `v1` cuando no se envía la cabecera `API-Version` |
| Validación | El middleware `ApiVersion` valida; las versiones no soportadas devuelven 400 |
| Rutas | La función auxiliar `v()` resuelve dinámicamente la clase de controlador según la versión |
| Directorio | Controladores organizados por versión: `app/api/{version}/controller/` |

Ejemplo de extensión — añadir una API v2:
1. Crear `app/api/v2/controller/AuthController.php`
2. Añadir `'v2'` a la constante `SUPPORTED` del middleware `ApiVersion`
3. No es necesario modificar las definiciones de rutas

```bash
# 使用 v1
curl -H "API-Version: v1" /api/auth/login

# 使用 v2
curl -H "API-Version: v2" /api/auth/login

# 不传，默认 v1
curl /api/auth/login
```

### 4.3 Estrategia de límite de frecuencia

Basado en el algoritmo de ventana deslizante de Redis Sorted Set, ejecutado con script Lua atómico:

| Interfaz | Límite |
|------|------|
| Por defecto | 60 veces/minuto/IP/ruta |
| POST /api/auth/login | 10 veces/minuto |
| POST /api/auth/register | 5 veces/minuto |

Al superar el límite devuelve 429; las cabeceras de respuesta incluyen X-RateLimit-Limit / Remaining / Reset / Retry-After.

### 4.4 Respuesta unificada

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

| code | Significado | Escenario de activación |
|------|------|---------|
| 0 | Éxito | Respuesta normal |
| 400 | Error de parámetros | Formato de solicitud incorrecto |
| 401 | No autenticado | Token ausente/expirado/no válido |
| 403 | Sin permisos | El rol del usuario no incluye el permiso requerido |
| 404 | No existe | Recurso no encontrado |
| 422 | Error de validación | Los parámetros del formulario no cumplen las reglas / fallo de confirmación de contraseña |
| 500 | Error del servidor | Excepción inesperada |

### 4.5 Flujo de autenticación (con captcha de clic)

```
客户端                               服务端
  │                                    │
  │  ① POST /api/captcha/generate     │ captcha_create('click')
  │◄── {key, image(base64), targets}  │
  │                                    │
  │  ② 用户点击图中文字位置              │
  │                                    │
  │  ③ POST /api/auth/login           │
  │     {username, password,          │
  │      captcha_key, clicks}         │
  │────────────────────────────────►  │
  │                                    │ ① captcha_verify()
  │                                    │ ② password_verify()
  │                                    │ ③ jwt()->create()
  │◄── {access_token, refresh_token}  │
  │                                    │
  │  ④ GET /admin/dashboard           │
  │     Authorization: Bearer xxx     │
  │────────────────────────────────►  │ AdminAuth → AdminPermission
  │◄── 200 {dashboard data}           │
```

### 4.6 Modelo de permisos (RBAC)

```
  用户 ──┬── 角色 ──┬── 权限
  User     Role      Permission
                 │
                 ├── type=1: 菜单 (控制侧边栏可见)
                 ├── type=2: 按钮 (控制页面内操作)
                 └── type=3: API  (控制接口访问)

  权限标识格式: {method}.{path}
  例: get.admin/user  post.admin/user  delete.admin/user
  超级管理员标识: * (跳过所有权限检查)
```

### 4.7 Confirmación secundaria para operaciones sensibles

Las operaciones sensibles como eliminar usuarios, roles o permisos requieren enviar la contraseña del usuario actual en el cuerpo de la solicitud para re-verificar la identidad:

```
客户端                           服务端
  │                                │
  │  DELETE /admin/user/{hashid}  │
  │  { password: "******" }       │
  │────────────────────────────►  │
  │                                │ confirmPassword(adminId, password)
  │                                │ → 密码错误返回 422
  │                                │ → 密码正确继续执行
  │◄── 200 { code: 0 }           │
```

El frontend muestra un cuadro de diálogo de confirmación antes de ejecutar operaciones de eliminación, recopila la contraseña del usuario y envía la solicitud.

## 5. Diseño del frontend

### 5.1 Panel de administración Flutter Web

```
┌────────────────────────────────────────────────┐
│  Header (56px)                                 │
│  ☰ 菜单按钮           🔔 消息  👤 管理员  ▼    │
├──────────┬─────────────────────────────────────┤
│ Sidebar  │  Content Area                       │
│ (64/240) │                                     │
│          │  ┌──────────────┐ ┌──────────┐     │
│ 📊 仪表盘│  │ 统计卡片×4    │ │ 趋势图   │     │
│ 👥 用户  │  └──────────────┘ └──────────┘     │
│ 🔒 角色  │  ┌──────┐ ┌────────────────┐       │
│ ⚙ 配置  │  │饼图  │ │ 最近操作日志    │       │
│ 📋 日志  │  └──────┘ └────────────────┘       │
└──────────┴─────────────────────────────────────┘
```

Características: barra lateral plegable, doble tema Material 3, tabla de datos de alta densidad, cuadros de diálogo, interacción con hover del ratón

### 5.2 Cliente móvil HarmonyOS

Rutas de páginas:

| Página | Ruta | Descripción |
|------|------|------|
| LoginPage | `pages/LoginPage` | Nombre de usuario + contraseña + captcha de clic para iniciar sesión |
| DashboardPage | `pages/DashboardPage` | Tarjetas de estadísticas + operaciones recientes |
| UserListPage | `pages/UserListPage` | Lista de usuarios, búsqueda + refresco deslizando hacia abajo + carga deslizando hacia arriba |
| UserDetailPage | `pages/UserDetailPage` | Crear/editar/ver/eliminar (confirmación AlertDialog) |
| ProfilePage | `pages/ProfilePage` | Centro personal, cerrar sesión (confirmación AlertDialog) |

Flujo de datos: Page ← DataService ← ApiService (JWT Bearer) ← HTTP ← webman

## 6. Diseño de seguridad

### 6.1 Defensa en profundidad

| Capa | Medida |
|------|------|
| Limitación de métodos | SecurityFilter lista blanca de métodos HTTP, solo GET/POST/PUT/DELETE/OPTIONS/HEAD, los métodos no estándar devuelven 405 |
| Intercepción de ataques | Middleware SecurityFilter, detección e interceptación de XSS/inyección SQL/recorrido de rutas/inyección de comandos/CSRF |
| Verificación humano-máquina | Captcha de clic (Click Captcha), verificación obligatoria en login/registro |
| Bloqueo de cuenta | 5 intentos de login fallidos consecutivos bloquean la cuenta durante 15 minutos; durante el bloqueo devuelve 429 |
| Límite de sesiones | Máximo 3 tokens concurrentes por usuario; al excederse, el token más antiguo pasa automáticamente a la lista negra |
| Límite de frecuencia | Middleware RateLimit, ventana deslizante Redis, atómico con Lua |
| CSP | La cabecera Content-Security-Policy limita los orígenes de recursos, previene XSS e inyección de datos |
| Confirmación de operaciones | Las operaciones sensibles como la eliminación requieren confirmación secundaria con la contraseña del usuario actual |
| Transporte | HTTPS + JWT Bearer Token |
| IDs de interfaz | Hashids cifra, imposible deducir el ID real desde el exterior |
| Cuerpo de solicitud | Cifrado AES-256-CBC de campos sensibles |
| Base de datos | Claves primarias BIGINT (no exponen el autoincremento) |
| Base de datos | Cifrado AES-128-ECB de campos sensibles en almacenamiento |
| Autenticación | JWT HS256, expiración de 2 h + refresh token |
| Autorización | RBAC, control de permisos con granularidad method.path |
| Auditoría | OperationLog registra todas las operaciones (incluye detección automática del campo source de origen) |

### 6.2 Gestión de claves

```
JWT_SECRET          → 环境变量注入，64位随机字符串
HASHIDS_SALT        → 唯一盐值，泄漏后需全局更换
ENCRYPTION_KEY      → API 传输加密密钥，32字节
ENCRYPTABLE_KEY     → DB 存储加密密钥，与传输密钥独立
SCOUT_HOSTS         → ES 地址，内网部署
```

### 6.3 Protección de datos sensibles

| Escenario | Campo | Medida |
|------|------|------|
| Visualización en listas | phone | Enmascarado: 138****1234 |
| Visualización en listas | email | Enmascarado: a***@example.com |
| Ver detalle | phone/email | Requiere interfaz de descifrado |
| Exportar Excel | phone/email | Exportar tras enmascarar |
| Exportar PDF | todos los campos | Enmascarado + marca de agua de copyright no removible |
| Almacenamiento | phone/email/id_card | Cifrado a texto cifrado con encryptable |

## 7. Diseño de exportación

### 7.1 Exportación Excel

```
请求: POST /admin/export/excel { table, columns, conditions, title }
  → fetchExportData() 查询数据 (limit 10000)
  → 脱敏敏感字段
  → PhpSpreadsheet 构建（蓝底白字表头 + 冻结首行 + 自动筛选）
  → 写入 runtime/tmp/ → download 响应
```

### 7.2 Exportación PDF

```
请求: POST /admin/export/pdf { type: table|dashboard, title, data }
  → buildPdfHtml() HTML + 内联CSS + 页头版权 + 页脚不可移除版权
  → Dompdf 渲染 A4 横向
  → 写入 runtime/tmp/ → download 响应
```

## 8. Arquitectura de despliegue

### 8.1 Topología recomendada

```
Nginx (:443 HTTPS) → webman worker × N (:8787) → MySQL + ES + Redis
                    静态文件: Flutter Web build/
```

### 8.2 Docker Compose (entorno de producción recomendado)

El `docker-compose.yml` en la raíz del proyecto orquesta todos los servicios de la topología anterior:

| Servicio | Imagen/construcción | Puerto | Descripción |
|------|----------|------|------|
| `nginx` | nginx:alpine | 80, 443 | Proxy inverso + archivos estáticos + Gzip |
| `app` | Construido con `Dockerfile` local | 8787 | PHP 8.3 + OPcache + webman |
| `mysql` | mysql:8.0 | 3306 | Base de datos principal, persistencia de datos con volúmenes |
| `redis` | redis:7-alpine | 6379 | Caché / límite de frecuencia / captcha |
| `elasticsearch` | elasticsearch:8.x | 9200 | Búsqueda de texto completo |

Antes de iniciar, sustituya las claves `JWT_SECRET`, `HASHIDS_SALT`, `ENCRYPTION_KEY` etc. del `docker-compose.yml` por cadenas aleatorias.

```bash
cp .env.docker .env
docker-compose up -d
```

### 8.3 CI/CD

La integración continua de GitHub Actions se define en `.github/workflows/ci.yml`:
- Comprobación de sintaxis PHP (`php -l`)
- Pruebas unitarias PHPUnit
- Análisis estático Flutter (`flutter analyze`)

### 8.4 Copia de seguridad de la base de datos

`database/backup/backup.sh` — copia de seguridad con mysqldump + gzip, limpia automáticamente las copias de hace más de 30 días.
`database/backup/restore.sh` — selección interactiva y restauración de las copias de seguridad.

### 8.5 Monitorización

El endpoint `GET /metrics` (`MetricsController`) expone 5 métricas gauge en formato texto Prometheus: total de solicitudes HTTP, número de usuarios activos, estado de las conexiones de base de datos/Redis, uso de memoria.

### 8.6 Requisitos de entorno

| Componente | Versión mínima | Configuración recomendada |
|------|---------|---------|
| PHP | 8.3+ | 8.3+ OPcache habilitado |
| MySQL | 8.0+ | 8.0+ replicación maestro-esclavo |
| Elasticsearch | 7.x | 8.x clúster de 3 nodos |
| Redis | 6.x | 7.x modo centinela |
| Nginx | 1.20+ | Proxy inverso + gzip + SSL |
| Flutter SDK | 3.41+ | Última versión estable |
| HarmonyOS | API 12 | DevEco Studio 5.x |
