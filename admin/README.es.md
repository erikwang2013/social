# Panel de administración abierto (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Un panel de administración full-stack basado en webman v2 + Flutter.

> [English version](README_EN.md) | [Diagramas de arquitectura](docs/ARCHITECTURE.md) | [Documento de diseño](docs/DESIGN.md) | [Arquitectura de seguridad](docs/SECURITY.md) | [Referencia de API](docs/API.md)

## Características

| Ámbito | Función | Descripción |
|--------|------|------|
| 🔐 Autenticación | Inicio de sesión / renovación de token / cierre de sesión | Captcha de clic + JWT + lista negra |
| | Bloqueo de cuenta | 5 intentos fallidos bloquean 15 minutos |
| | Límite de sesiones simultáneas | Máx. 3 tokens válidos por usuario |
| 📊 Panel | Estadísticas en tiempo real / tendencias / distribución / acciones recientes | Caché Redis 5 minutos |
| 👥 Gestión de usuarios | CRUD + borrado masivo / activar-desactivar | Borrado suave + confirmación de contraseña |
| | Importación masiva Excel | Validación línea por línea + informe de errores |
| 🔒 Roles y permisos | CRUD de roles + árbol de permisos | Autorización RBAC con granularidad method.path |
| ⚙ Configuración del sistema | CRUD de pares clave-valor | Gestión por grupos |
| 📋 Auditoría de operaciones | Consulta de registros + detección de origen | 8 plataformas detectadas automáticamente |
| 📁 Gestión de archivos | Subida / exportación Excel / exportación PDF | Enmascarado automático de datos sensibles |
| 🛡 Seguridad | Defensa en profundidad de 18 capas | XSS/inyección SQL/traversal de rutas/inyección de comandos/CSRF/límite de tasa/CSP... |
| 🏥 Operaciones | Health check / metrics / documentación API / security.txt | Prometheus + OpenAPI 3.0 + documentación interactiva hg/apidoc |
| 🌐 Internacionalización | Alternar chino/inglés | Cabecera Accept-Language / parámetro ?lang= |

## Stack tecnológico

| Capa | Tecnología | Descripción |
|---|------|------|
| Framework backend | webman v2 (workerman) | Framework PHP de altísimo rendimiento con procesos residentes |
| Versión PHP | 8.3+ | |
| Base de datos | MySQL 8.0+ | Prefijo de tabla `erik_`, claves primarias BIGINT sin autoincremento |
| Motor de búsqueda | Elasticsearch | Sincronización y consulta mediante `webman-scout` |
| Frontend admin | Flutter 3.x | Web con estilo de panel de administración de PC (`apps/flutter/`) |
| Móvil | HarmonyOS ArkTS | Cliente nativo HarmonyOS (`apps/harmonyos/`), compatible con teléfono/tableta/2en1 |

## Dependencias principales

| Paquete | Uso |
|---|------|
| `erikwang2013/snowflake-php` | Generación de claves primarias BIGINT únicas mediante el algoritmo Snowflake |
| `erikwang2013/hashids` | Cifrado de ID a nivel de API, oculta los ID reales de la base de datos |
| `erikwang2013/jwt-webman` | Emisión y verificación de tokens JWT |
| `erikwang2013/encryption` | Cifrado de datos sensibles en la capa de transporte |
| `erikwang2013/encryptable` | Cifrado automático de campos sensibles en la base de datos |
| `erikwang2013/webman-scout` | Sincronización de datos y búsqueda de texto completo en Elasticsearch |
| `erikwang2013/season` | Datos de banderas de países |
| `erikwang2013/poster-php` | Generación/verificación de captcha de clic + generación de carteles |
| `phpoffice/phpspreadsheet` | Exportación Excel |
| `barryvdh/laravel-dompdf` | Exportación PDF (basado en Dompdf) |

## Estructura del proyecto

```
open-admin/
├── app/
│   ├── admin/controller/       # 管理端控制器
│   │   ├── DashboardController.php # 仪表盘（Redis缓存）
│   │   ├── UserController.php      # 用户 CRUD + 批量操作
│   │   ├── RoleController.php      # 角色 CRUD
│   │   ├── PermissionController.php# 权限 CRUD
│   │   ├── ConfigController.php    # 系统配置 CRUD
│   │   ├── LogController.php       # 操作日志查询
│   │   ├── ProfileController.php   # 个人中心 + 登出
│   │   ├── ExportController.php    # Excel/PDF 导出
│   │   ├── ImportController.php    # Excel 导入用户
│   │   ├── UploadController.php    # 文件上传
│   │   ├── HealthController.php    # 健康检查
│   │   ├── DocsController.php      # OpenAPI 文档
│   │   └── BaseController.php      # 基础控制器
│   ├── api/
│   │   └── v1/controller/          # API v1 控制器（版本由请求头 API-Version 控制）
│   │       ├── CaptchaController.php # 点击验证码
│   │       └── AuthController.php    # 登录/刷新令牌
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php  # ID 编解码
│   │   ├── SnowflakeService.php# Snowflake ID 生成
│   │   └── EncryptionService.php # 数据加解密 + 脱敏
│   ├── middleware/             # 中间件
│   │   ├── Cors.php            # 跨域
│   │   ├── SecurityFilter.php  # 攻击检测拦截（HTTP方法限制/XSS/SQL注入/路径遍历/命令注入/CSRF）
│   │   ├── RateLimit.php       # Redis 限流（滑动窗口 + 响应头）
│   │   ├── ApiVersion.php      # API 版本校验
│   │   ├── AdminAuth.php       # JWT 认证 + 黑名单
│   │   ├── AdminPermission.php # RBAC 权限校验
│   │   └── OperationLog.php    # 操作日志自动记录（含来源端检测）
│   └── model/                  # 数据模型
├── apps/
│   ├── flutter/                # Flutter Web 管理后台（PC 风格）
│   │   └── lib/app/
│   │       ├── pages/          # 5 个完整页面（仪表盘/用户/角色/配置/日志/个人中心）
│   │       ├── services/       # ApiService（JWT 拦截器）+ AuthService（Token 持久化）
│   │       └── layouts/        # 响应式管理后台布局（侧边栏+顶栏+内容区）
│   └── harmonyos/              # HarmonyOS 原生客户端（Token 无感刷新）
├── config/                     # 配置文件（含中文注释）
│   ├── route.php               # 路由 + API 版本策略
│   ├── middleware.php           # 全局中间件注册
│   └── ...                     # 各组件配置
├── database/migrations/        # SQL 迁移文件（含权限种子数据）
├── public/                     # 公共入口
├── runtime/                    # 运行时文件
└── vendor/                     # Composer 依赖
```

## Requisitos del entorno

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (solo necesario para desarrollo frontend)
- Elasticsearch >= 7.x (opcional, necesario para la búsqueda)

## Inicio rápido

### 1. Instalar dependencias

```bash
composer install
```

### 2. Configurar variables de entorno

Copie y modifique las variables de entorno (opcional; si no se configuran, se usan los valores por defecto de `config/*.php`):

```bash
cp .env.example .env
```

Elementos de configuración clave:

| Variable de entorno | Descripción | Valor por defecto |
|---------|------|--------|
| `JWT_SECRET` | Clave de firma JWT | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Sal de Hashids | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | Clave de cifrado de API | Valor por defecto de 32 bytes |
| `SNOWFLAKE_DATACENTER_ID` | ID del centro de datos (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | ID del nodo de trabajo (0-31) | `1` |
| `SCOUT_HOSTS` | Dirección ES | `http://localhost:9200` |

**En producción, cambie obligatoriamente todas las claves por cadenas aleatorias.**

### 3. Instalación con un clic

Tras iniciar el servicio, acceda al asistente de instalación en el navegador para completar la inicialización de la base de datos y la creación del administrador:

```bash
php start.php start
```

Escucha por defecto en `http://0.0.0.0:8787` (el puerto se puede cambiar en `config/server.php`).

Abra **`http://localhost:8787/install`** en el navegador y complete según el asistente:

| Paso | Contenido |
|------|------|
| ① Configuración de BD | Host, puerto, nombre de la base de datos, usuario, contraseña |
| ② Administrador | Usuario y contraseña de administrador (por defecto: admin / admin888) |

Al hacer clic en «Iniciar instalación» se crean automáticamente las tablas, se siembran los datos de permisos, se crea la cuenta de administrador y se escribe la configuración de BD en `.env`.

> Tras la instalación se genera el archivo de bloqueo `runtime/install.lock`. Para reinstalar, basta con eliminar este archivo.

### 4. Iniciar sesión

Visite `http://localhost:8787` e inicie sesión con las credenciales de administrador definidas durante la instalación.

### 5. Iniciar el frontend (opcional)

**Panel de administración Flutter (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**Cliente HarmonyOS (móvil):**

Abra el directorio `apps/harmonyos/` con DevEco Studio y ejecútelo en un dispositivo real o emulador.

### 6. Despliegue con un clic con Docker Compose (recomendado para producción)

El proyecto ofrece una orquestación Docker completa con 5 servicios: Nginx, PHP (webman app), MySQL, Redis, Elasticsearch.

```bash
# 1. 配置 Docker 环境变量
cp .env.docker .env

# 2. 启动所有服务
docker-compose up -d

# 3. 浏览器访问安装向导完成初始化
# http://localhost:8787/install  (填入数据库和管理员信息)
# 或手动执行 SQL 迁移（进入 app 容器）:
# docker-compose exec app mysql -h mysql -u root -p < database/migrations/open_admin.sql

# 4. 访问
# http://localhost:8787  (webman)
# http://localhost:8080  (Nginx 反向代理)
```

- `Dockerfile`: PHP 8.3 + OPcache + Composer, basado en `php:8.3-cli`
- `docker-compose.yml`: orquestación de 5 servicios, aislamiento de red, volúmenes de datos persistentes
- `.env.docker`: variables de entorno específicas de Docker


## Convenciones de base de datos

- **Prefijo de tabla**: `erik_`
- **Clave primaria**: todas las tablas usan `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT deshabilitado**
- **Generación de ID**: las claves primarias se generan en la capa de aplicación con `SnowflakeService::generate()`, únicas en entornos distribuidos
- **Campos obligatorios**: cada tabla debe incluir `id`, `created_at`, `updated_at`
- **Borrado suave**: las tablas que lo necesiten añaden `deleted_at DATETIME DEFAULT NULL`
- **Campos sensibles**: teléfono, correo, DNI, etc. se cifran automáticamente con el plugin `encryptable`; la columna usa `VARCHAR(500)` para almacenar el texto cifrado

## Referencia de API

La especificación completa de la API (formato de respuesta unificado, códigos de error de negocio, manejo de ID, versiones de API, límite de tasa, arquitectura de middleware, flujos de autenticación y captcha) y la lista completa de endpoints se encuentran en la **[Referencia de API](docs/API.md)**.

## Notas de frontend

### Panel de administración Flutter (estilo PC)

- **Diseño**: barra lateral plegable (64px/240px) + barra superior + área de contenido, tres puntos de ruptura responsivos (móvil/tableta/escritorio)
- **Páginas**: inicio de sesión, panel, gestión de usuarios, roles y permisos, configuración del sistema, registros de operaciones, perfil
- **Gestión de estado**: GetX (singleton `ApiService` + persistencia de token en `AuthService`)
- **Panel**: tarjetas de estadísticas, gráfico de líneas de tendencia (fl_chart), gráfico circular, registros de operaciones recientes
- **Exportación**: exportación Excel/PDF; los PDF incluyen información de copyright no extraíble
- **Operaciones masivas**: borrado masivo con selección múltiple, activación/desactivación masiva
- **Tema**: Material 3, tema claro/oscuro

### Cliente móvil HarmonyOS

- **Páginas**: inicio de sesión, panel, lista/detalle de usuarios, perfil
- **Autenticación**: JWT Bearer + renovación silenciosa del token en 401, redirección automática al inicio de sesión si falla
- **Almacenamiento**: el token se gestiona mediante AppStorage

## Normas de desarrollo

- Las funciones/clases globales no llevan `\` inicial y se importan con `use`
- Todos los archivos PHP deben incluir el aviso de copyright en la cabecera
- Todos los archivos de configuración deben incluir comentarios en chino
- Las claves primarias deben generarse con snowflake en la capa de aplicación; está prohibido el autoincremento
- Todos los ID de parámetros y respuestas de la API deben cifrarse/descifrarse con hashids
- El middleware AdminPermission cachea los permisos del usuario en Redis (TTL=60s), eliminando el cuello de botella de consultas N+1

## Despliegue

### Docker Compose (recomendado)

La raíz del proyecto proporciona `docker-compose.yml`, que orquesta 5 servicios:

| Servicio | Imagen | Puerto |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | Build desde el `Dockerfile` local | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

La imagen PHP se construye con `Dockerfile`, imagen base `php:8.3-cli`, con OPcache habilitado.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

Pipeline de integración continua con GitHub Actions: `.github/workflows/ci.yml`

- Verificación de sintaxis PHP (`php -l`)
- Pruebas unitarias PHPUnit
- Análisis estático de Flutter (`flutter analyze`)

### Copia de seguridad de la base de datos

Directorio `database/backup/`:

- `backup.sh` — copia de seguridad con mysqldump + gzip, limpieza automática de copias de más de 30 días
- `restore.sh` — restauración interactiva, lista las copias disponibles para elegir

### Configuración de seguridad de Nginx

Para producción, consulte `docs/nginx-security.conf` para el endurecimiento del proxy inverso.

## El código abierto es un camino difícil: agradecemos su apoyo

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
