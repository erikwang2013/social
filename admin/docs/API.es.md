# Documentación de referencia de la API
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Descripción general

El panel de administración open-admin está construido sobre webman v2 y ofrece una API JSON RESTful. Todos los endpoints de administración requieren autenticación JWT y verificación de permisos RBAC; los endpoints públicos se enrutan a controladores versionados mediante el encabezado de versión de la API.

- **URL base**: `http://localhost:8787`
- **Versión de la API**: controlada mediante el encabezado `API-Version: v1` (v1 por defecto si falta)
- **Idioma**: se cambia mediante el encabezado `Accept-Language` o el parámetro `?lang=zh_CN|en` (por defecto zh_CN), detectado automáticamente por el middleware Locale

> **Resumen de endpoints**: Autenticación(5) | Panel(1) | Usuarios(7) | Roles(4) | Permisos(4) | Configuración(4) | Registros(1) | Perfil(3) | Importar/Exportar(3) | Subida(1) | Operación(4: health/metrics/docs/security.txt) | 37 endpoints en total
- **Autenticación**: `Authorization: Bearer <token>` (JWT)
- **Formato de respuesta**: `{ "code": 0, "message": "success", "data": {...} }`
- **Endpoint de documentación**: `GET /api/docs` devuelve la especificación JSON de OpenAPI 3.0

### Requisitos de las peticiones

- Solo se permiten los métodos `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD`; otros métodos HTTP (p. ej. TRACE, CONNECT, PATCH) devuelven 405
- Todas las peticiones `POST` / `PUT` deben establecer `Content-Type: application/json` (excepto la subida de archivos), de lo contrario se devuelve 415
- El cuerpo de la petición no debe superar los 10MB, de lo contrario se devuelve 413
- El filtro de seguridad analiza todas las entradas de las peticiones en busca de XSS, inyección SQL, path traversal e inyección de comandos; los hallazgos devuelven 403
- 5 intentos de inicio de sesión fallidos consecutivos activan el bloqueo de la cuenta (15 minutos); durante el bloqueo, las peticiones de inicio de sesión devuelven 429
- Un usuario puede tener como máximo 3 tokens válidos simultáneamente; al superarse, el token más antiguo se añade automáticamente a la lista negra

## 2. Códigos de error

| code | Significado | Escenario que lo activa |
|------|------|---------|
| 0 | Éxito | |
| 400 | Error en los parámetros de la petición | Formato de petición incorrecto |
| 401 | No autenticado | Token ausente / caducado / en lista negra |
| 403 | Sin permiso / bloqueo de seguridad | Permisos RBAC insuficientes / detección de SecurityFilter |
| 404 | Recurso no encontrado | El objetivo de consulta/actualización/eliminación no existe |
| 405 | Método no permitido | Solo se permiten GET/POST/PUT/DELETE/OPTIONS/HEAD; los métodos no estándar se rechazan |
| 413 | Cuerpo de la petición demasiado grande | Content-Length supera los 10MB |
| 415 | Tipo de medio no compatible | El Content-Type de POST/PUT no es JSON ni una subida de archivo |
| 422 | Fallo de validación de parámetros | Faltan campos obligatorios, formato incorrecto o validación de negocio fallida |
| 429 | Demasiadas peticiones | RateLimit activado / bloqueo de cuenta (5 inicios de sesión fallidos consecutivos bloquean 15 minutos) |
| 500 | Error interno del servidor | |

## 3. Endpoints públicos

Todos los endpoints públicos están montados bajo el grupo `/api`; el middleware `ApiVersion` los distribuye al controlador versionado correspondiente al encabezado `API-Version` (p. ej. `app\api\v1\controller\AuthController`).

### 3.1 Comprobación de estado

```
GET /health
```

- **Autenticación**: no requerida
- **Límite de frecuencia**: ninguno

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "app": "open-admin",
    "version": "1.0",
    "php": "8.3.0",
    "database": "ok",
    "redis": "ok",
    "elasticsearch": "ok",
    "timestamp": 1716249600
  }
}
```

Valores de `database`, `redis`, `elasticsearch`: `"ok"` | `"unavailable"`. `elasticsearch` devuelve `"unavailable"` cuando ES no es accesible; si el estado de salud del clúster no es green/yellow, se devuelve el valor real de status (p. ej. `"red"`).

### 3.2 Documentación de la API

```
GET /api/docs
```

- **Autenticación**: no requerida
- **Límite de frecuencia**: predeterminado global (60/min)
- **Respuesta**: especificación JSON de OpenAPI 3.0.3, que incluye todas las definiciones de endpoints, parámetros y esquemas

### 3.3 Generar captcha

```
POST /api/captcha/generate
```

- **Autenticación**: no requerida
- **Encabezado de petición**: `API-Version: v1` (obligatorio)
- **Límite de frecuencia**: predeterminado global (60/min)

**Cuerpo de la petición**:
```json
{
  "difficulty": "medium"
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| difficulty | string | No | `easy` / `medium` / `hard`, por defecto `medium` |

**Ejemplo de respuesta** — tipo clic (`type: "click"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "abc123def456",
    "type": "click",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "targets": [
        { "order": 1, "text": "A", "x": 120, "y": 85 },
        { "order": 2, "text": "B", "x": 310, "y": 42 }
      ]
    }
  }
}
```

**Ejemplo de respuesta** — tipo deslizador (`type: "slider"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "def456abc789",
    "type": "slider",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "x": 120,
      "y": 60,
      "puzzle_w": 50,
      "puzzle_h": 50,
      "puzzle": "data:image/png;base64,iVBORw0KGgo..."
    }
  }
}
```

**Ejemplo de respuesta** — tipo rotación (`type: "rotate"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "ghi789abc012",
    "type": "rotate",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "angle": 45
    }
  }
}
```

| Campo | Tipo | Descripción |
|------|------|------|
| key | string | Identificador del captcha, se devuelve al verificar |
| type | string | Tipo de captcha: `click` / `slider` / `rotate` |
| image | string | Imagen en data URI base64 |
| extra | object | Datos adicionales según el tipo (ver abajo) |

**`extra` según el tipo**:

| type | campos extra | Tipo | Descripción |
|------|-----------|------|------|
| click | targets | array | Objetivos de clic, con `order` (orden) `text` (texto de aviso) `x` `y` (coordenadas) |
| slider | x, y | int | Coordenadas de la esquina superior izquierda del hueco (sobre un lienzo de 300×200) |
| slider | puzzle_w, puzzle_h | int | Ancho y alto de la imagen del rompecabezas |
| slider | puzzle | string | Imagen del rompecabezas en data URI base64 |
| rotate | angle | int | Ángulo de rotación correcto (0-359); hay que girar `360-angle` para enderezar la imagen |

### 3.4 Verificar captcha

```
POST /api/captcha/verify
```

- **Autenticación**: no requerida
- **Encabezado de petición**: `API-Version: v1` (obligatorio)
- **Límite de frecuencia**: predeterminado global (60/min)

**Cuerpo de la petición** — tipo clic (`type: "click"`):
```json
{
  "key": "abc123def456",
  "type": "click",
  "clicks": [
    { "x": 120, "y": 85 },
    { "x": 310, "y": 42 }
  ]
}
```

**Cuerpo de la petición** — tipo deslizador (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**Cuerpo de la petición** — tipo rotación (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| key | string | Sí | Clave del captcha, devuelta por generate |
| type | string | Sí | Tipo de captcha, debe coincidir con el `type` devuelto por generate |
| clicks | variante | Sí | Datos de la respuesta; el formato varía según el type (ver abajo) |

**`clicks` según el tipo**:

| type | tipo de clicks | Descripción | Tolerancia de error |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | Matriz de coordenadas de clic, en orden order | radio 18px |
| slider | `int` | Desplazamiento del deslizador en el eje X | ±4px |
| rotate | `int` | Ángulo de rotación (0-359) | ±5° |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

Tras una verificación correcta, el backend escribe `captcha_verified:{key}` en Redis (TTL 300s), y el endpoint de inicio de sesión permite el paso en base a ello.
En caso de fallo, `code` es 422, `message` es `"验证失败，请重试"` y `data.valid` es `false`.

### 3.5 Inicio de sesión

```
POST /api/auth/login
```

- **Autenticación**: no requerida
- **Encabezado de petición**: `API-Version: v1` (obligatorio)
- **Límite de frecuencia**: 10/min (por IP + ruta)

**Cuerpo de la petición**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| Campo | Tipo | Obligatorio | Regla de validación | Descripción |
|------|------|------|---------|------|
| username | string | Sí | min:3, max:50 | Nombre de usuario |
| password | string | Sí | min:6, max:32 (texto plano) | Cifrado AES-256-CBC-HMAC y codificado en Base64 (compatible con texto plano) |
| captcha_key | string | Sí | | Clave del captcha (debe verificarse primero mediante `/api/captcha/verify`) |

### Protocolo de cifrado de contraseñas

Utiliza **cifrado asimétrico RSA-2048**; la clave pública reside en el código del frontend (puede exponerse con seguridad), la clave privada solo la tiene el servidor.

```
Flujo de cifrado (cliente):
  Clave pública RSA (PEM) → cifrado PKCS1v1.5 → codificación Base64 → transmisión

Flujo de descifrado (servidor, retroceso escalonado):
  1. Descifrado con clave privada RSA → éxito y UTF-8 válido → usar el resultado descifrado
  2. Descifrado AES-256-CBC-HMAC → éxito → usar el resultado descifrado (compatibilidad con clientes antiguos)
  3. Retroceso a texto plano → usar directamente la entrada original
```

La clave pública está integrada en la aplicación frontend y no necesita transmitirse por la red. La clave privada solo se guarda en `RSA_PRIVATE_KEY` en `.env` y no debe filtrarse.

> El cifrado simétrico AES es un esquema de compatibilidad con versiones antiguas y se eliminará cuando todos los clientes migren a RSA.

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "a1b2c3d4",
      "username": "admin",
      "real_name": "管理员"
    }
  }
}
```

| Campo | Tipo | Descripción |
|------|------|------|
| access_token | string | Token de acceso JWT |
| refresh_token | string | Token de actualización JWT |
| expires_in | int | Vigencia del token de acceso (segundos), por defecto 7200 |
| user.id | string | ID de usuario cifrado con hashid |
| user.username | string | Nombre de usuario |
| user.real_name | string | Nombre real |

**Errores posibles**:
- 422: Fallo de validación de parámetros (faltan campos obligatorios, formato incorrecto)
- 422: Complete primero la verificación del captcha (captcha_key no ha pasado `/api/captcha/verify`)
- 401: Nombre de usuario o contraseña incorrectos
- 403: La cuenta está deshabilitada
- 429: La cuenta está bloqueada, inténtelo de nuevo en 15 minutos (se activa tras 5 inicios de sesión fallidos consecutivos)

### 3.6 Registro

```
POST /api/auth/register
```

- **Autenticación**: no requerida
- **Encabezado de petición**: `API-Version: v1` (obligatorio)
- **Límite de frecuencia**: 5/min (por IP + ruta)

**Cuerpo de la petición**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| Campo | Tipo | Obligatorio | Regla de validación | Descripción |
|------|------|------|---------|------|
| username | string | Sí | min:3, max:50 | Nombre de usuario (único) |
| password | string | Sí | min:6, max:32 (texto plano) | Cifrado AES-256-CBC-HMAC y codificado en Base64 |
| real_name | string | Sí | max:50 | Nombre real |
| captcha_key | string | Sí | | Clave del captcha (debe verificarse primero mediante `/api/captcha/verify`) |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "注册成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "e5f6g7h8",
      "username": "newuser",
      "real_name": "新用户"
    }
  }
}
```

Tras un registro correcto se devuelven directamente los tokens JWT; el estado del usuario está habilitado por defecto (status=1).

### 3.7 Actualizar token

```
POST /api/auth/refresh
```

- **Autenticación**: no requerida
- **Encabezado de petición**: `API-Version: v1` (obligatorio)
- **Límite de frecuencia**: predeterminado global (60/min)

**Cuerpo de la petición**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| refresh_token | string | Sí | refresh_token obtenido al iniciar sesión/registrarse |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200
  }
}
```

Una actualización correcta devuelve a la vez un nuevo access_token y refresh_token; los tokens antiguos quedan invalidados automáticamente. Al actualizar se renuevan la última hora de inicio de sesión y la IP del usuario.

**Errores posibles**:
- 422: Falta el token de actualización
- 401: Token de actualización no válido o caducado

### 3.8 Métricas de Prometheus

```
GET /metrics
```

- **Autenticación**: no requerida
- **Límite de frecuencia**: ninguno
- **Formato de respuesta**: formato de texto Prometheus (`text/plain; version=0.0.4`)

Endpoint público de métricas de Prometheus para que lo raspe Grafana/Prometheus.

**Ejemplo de respuesta**:
```
# HELP openadmin_http_requests_total Total number of HTTP requests.
# TYPE openadmin_http_requests_total gauge
openadmin_http_requests_total 15234

# HELP openadmin_active_users Number of active users.
# TYPE openadmin_active_users gauge
openadmin_active_users 156

# HELP openadmin_db_connection_status Database connection status (1=ok, 0=fail).
# TYPE openadmin_db_connection_status gauge
openadmin_db_connection_status 1

# HELP openadmin_redis_connection_status Redis connection status (1=ok, 0=fail).
# TYPE openadmin_redis_connection_status gauge
openadmin_redis_connection_status 1

# HELP openadmin_memory_usage_bytes Memory usage in bytes.
# TYPE openadmin_memory_usage_bytes gauge
openadmin_memory_usage_bytes 18874368
```

| Nombre de la métrica | Tipo | Descripción |
|------|------|------|
| `openadmin_http_requests_total` | gauge | Número total acumulado de peticiones HTTP |
| `openadmin_active_users` | gauge | Usuarios activos actuales (con sesión iniciada en las últimas 24 horas) |
| `openadmin_db_connection_status` | gauge | Estado de la conexión a la base de datos, 1=correcto, 0=error |
| `openadmin_redis_connection_status` | gauge | Estado de la conexión a Redis, 1=correcto, 0=error |
| `openadmin_memory_usage_bytes` | gauge | Uso de memoria actual del proceso PHP (bytes) |

## 4. Panel de control

Todos los endpoints de administración están montados bajo el grupo `/admin` y pasan por tres middlewares: `AdminAuth` (autenticación JWT), `AdminPermission` (verificación de permisos RBAC) y `OperationLog` (registro de operaciones).

### 4.1 Datos del panel

```
GET /admin/dashboard
```

- **Autenticación**: JWT + RBAC
- **Caché**: Redis 5 minutos

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "stats": [
      {
        "label": "用户总数",
        "value": "1280",
        "icon": "people",
        "color": "#1677FF",
        "trend": 12.5
      },
      {
        "label": "今日新增",
        "value": "23",
        "icon": "person_add",
        "color": "#52C41A"
      },
      {
        "label": "活跃用户",
        "value": "156",
        "icon": "bolt",
        "color": "#FA8C16"
      },
      {
        "label": "操作日志",
        "value": "432",
        "icon": "description",
        "color": "#722ED1"
      }
    ],
    "trends": {
      "dates": ["2026-04-22", "2026-04-23", "..."],
      "series": [
        { "name": "累计用户", "data": [1200, 1210, "..."], "color": "#1677FF" },
        { "name": "操作日志", "data": [45, 52, "..."], "color": "#52C41A" }
      ]
    },
    "distribution": {
      "user_status": [
        { "name": "启用", "value": 1250 },
        { "name": "禁用", "value": 30 }
      ]
    },
    "recent_logs": [
      {
        "id": "hashid...",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "user_name": "admin",
        "created_at": "2026-05-21 10:30:00"
      }
    ]
  }
}
```

| campos stats | Tipo | Descripción |
|------|------|------|
| label | string | Nombre de la métrica |
| value | string | Valor de la métrica (tipo cadena) |
| icon | string | Nombre del icono Material |
| color | string | Color de la tarjeta |
| trend | float? | Tasa de crecimiento diaria (porcentaje); solo "total de usuarios" tiene este campo |

| campos trends | Tipo | Descripción |
|------|------|------|
| dates | array{string} | Secuencia de fechas de los últimos 30 días |
| series | array{object} | Datos de la línea de tendencia: name (nombre), data (matriz de valores), color (color) |

## 5. Gestión de usuarios

El `id` devuelto por todos los endpoints de gestión de usuarios es una cadena cifrada con hashid. Los campos de contraseña se excluyen de las respuestas. Los teléfonos y correos se muestran enmascarados en los endpoints de lista y en texto plano en los de detalle (los campos cifrados de la base de datos se descifran automáticamente con el trait Encryptable).

### 5.1 Lista de usuarios

```
GET /admin/user
```

- **Autenticación**: JWT + RBAC

**Parámetros de consulta**:

| Parámetro | Tipo | Obligatorio | Valor por defecto | Descripción |
|------|------|------|------|------|
| page | int | No | 1 | Número de página |
| limit | int | No | 15 | Elementos por página |
| keyword | string | No | | Palabra clave de búsqueda, coincide con nombre de usuario y nombre real |
| status | int | No | | Filtro de estado, 0=deshabilitado, 1=habilitado |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "a1b2c3d4",
        "username": "admin",
        "real_name": "管理员",
        "phone": "138****5678",
        "email": "a***@example.com",
        "status": 1,
        "last_login_at": "2026-05-21 10:30:00",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 100,
    "page": 1,
    "limit": 15
  }
}
```

| Campo | Tipo | Descripción |
|------|------|------|
| id | string | ID de usuario cifrado con hashid |
| username | string | Nombre de usuario |
| real_name | string | Nombre real |
| phone | string | Teléfono enmascarado (formato `138****5678`) |
| email | string | Correo enmascarado (formato `a***@example.com`) |
| status | int | 1=habilitado, 0=deshabilitado |
| last_login_at | string | Última hora de inicio de sesión (datetime) |
| created_at | string | Hora de creación (datetime) |

### 5.2 Crear usuario

```
POST /admin/user
```

- **Autenticación**: JWT + RBAC

**Cuerpo de la petición**:
```json
{
  "username": "newuser",
  "password": "123456",
  "real_name": "新用户",
  "phone": "13800138000",
  "email": "newuser@example.com",
  "status": 1
}
```

| Campo | Tipo | Obligatorio | Regla de validación | Descripción |
|------|------|------|---------|------|
| username | string | Sí | min:3, max:50 | Nombre de usuario (único) |
| password | string | Sí | min:6, max:32 | Contraseña (almacenada con bcrypt) |
| real_name | string | Sí | max:50 | Nombre real |
| phone | string | No | | Teléfono (cifrado con Encryptable) |
| email | string | No | | Correo (cifrado con Encryptable) |
| status | int | No | in:0,1 | Estado, por defecto 1 (habilitado) |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "e5f6g7h8",
    "username": "newuser",
    "real_name": "新用户",
    "phone": "13800138000",
    "email": "newuser@example.com",
    "status": 1,
    "created_at": "2026-05-21 12:00:00"
  }
}
```

**Errores posibles**:
- 422: El nombre de usuario ya existe
- 422: Fallo de validación de parámetros (faltan campos obligatorios)

### 5.3 Detalle de usuario

```
GET /admin/user/{id}
```

- **Autenticación**: JWT + RBAC
- **Parámetro de ruta**: `{id}` es el ID de usuario cifrado con hashid

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "管理员",
    "phone": "13800138000",
    "email": "admin@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00",
    "last_login_ip": "192.168.1.1",
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-05-20 08:00:00"
  }
}
```

En el detalle, `phone` y `email` se devuelven en texto plano (almacenados cifrados en la base de datos, descifrados automáticamente por el cast Encryptable) y no se enmascaran. `password` y `id_card` nunca aparecen en la respuesta.

**Errores posibles**:
- 404: El usuario no existe

### 5.4 Actualizar usuario

```
PUT /admin/user/{id}
```

- **Autenticación**: JWT + RBAC
- **Parámetro de ruta**: `{id}` es el ID de usuario cifrado con hashid

**Cuerpo de la petición**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| real_name | string | No | Nombre real; si no se envía, se mantiene el valor original |
| password | string | No | Nueva contraseña; no cambia si es cadena vacía o no se envía |
| phone | string | No | Teléfono |
| email | string | No | Correo |
| status | int | No | 0=deshabilitado, 1=habilitado |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13900139000",
    "email": "new@example.com",
    "status": 1,
    "updated_at": "2026-05-21 12:30:00"
  }
}
```

**Errores posibles**:
- 404: El usuario no existe

### 5.5 Eliminar usuario

```
DELETE /admin/user/{id}
```

- **Autenticación**: JWT + RBAC
- **Parámetro de ruta**: `{id}` es el ID de usuario cifrado con hashid
- **Operación sensible**: requiere confirmación de contraseña

**Cuerpo de la petición**:
```json
{
  "password": "admin_password"
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| password | string | Sí | Contraseña del usuario actualmente conectado (confirmación) |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Realiza un borrado lógico (Eloquent SoftDeletes); los datos se marcan con deleted_at sin eliminación física.

**Errores posibles**:
- 404: El usuario no existe
- 422: Las operaciones sensibles requieren confirmación de contraseña (password vacío)
- 422: Fallo de verificación de contraseña (la contraseña no coincide)

### 5.6 Eliminación masiva de usuarios

```
POST /admin/user/batch/destroy
```

- **Autenticación**: JWT + RBAC
- **Operación sensible**: requiere confirmación de contraseña

**Cuerpo de la petición**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| ids | array{string} | Sí | Matriz de ID de usuarios cifrados con hashid |
| password | string | Sí | Contraseña del usuario actualmente conectado (confirmación) |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

Realiza un borrado lógico; `data.count` es el número realmente eliminado.

**Errores posibles**:
- 422: Seleccione los usuarios a eliminar (ids vacío)
- 422: ID no válido (fallo de decodificación hashid)
- 422: Fallo de verificación de contraseña

### 5.7 Habilitar/deshabilitar usuarios en masa

```
POST /admin/user/batch/status
```

- **Autenticación**: JWT + RBAC

**Cuerpo de la petición**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| ids | array{string} | Sí | Matriz de ID de usuarios cifrados con hashid |
| status | int | Sí | 0=deshabilitado, 1=habilitado |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

message cambia dinámicamente según el valor de status: `"批量启用成功"` o `"批量禁用成功"`.

**Errores posibles**:
- 422: Seleccione usuarios (ids vacío)
- 422: Valor de estado no válido (status no es 0 ni 1)

## 6. Gestión de roles

### 6.1 Lista de roles

```
GET /admin/role
```

- **Autenticación**: JWT + RBAC

**Parámetros de consulta**:

| Parámetro | Tipo | Obligatorio | Valor por defecto | Descripción |
|------|------|------|------|------|
| page | int | No | 1 | Número de página |
| limit | int | No | 15 | Elementos por página |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "r1r2r3r4",
        "name": "超级管理员",
        "slug": "super_admin",
        "description": "拥有所有权限",
        "status": 1,
        "users_count": 3,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 5,
    "page": 1,
    "limit": 15
  }
}
```

| Campo | Tipo | Descripción |
|------|------|------|
| id | string | ID de rol cifrado con hashid |
| name | string | Nombre del rol |
| slug | string | Identificador del rol (único, se usa para la comprobación de permisos) |
| description | string | Descripción del rol |
| status | int | 1=habilitado, 0=deshabilitado |
| users_count | int | Número de usuarios con este rol |

### 6.2 Crear rol

```
POST /admin/role
```

- **Autenticación**: JWT + RBAC

**Cuerpo de la petición**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| Campo | Tipo | Obligatorio | Regla de validación | Descripción |
|------|------|------|---------|------|
| name | string | Sí | max:50 | Nombre del rol |
| slug | string | Sí | max:50 | Identificador del rol |
| description | string | No | | Descripción del rol, por defecto cadena vacía |
| status | int | No | | Estado, por defecto 1 |
| permission_ids | array{int} | No | | Matriz de ID de permisos (ID INT originales, no hashids) |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "编辑员",
    "slug": "editor",
    "description": "内容编辑角色",
    "status": 1
  }
}
```

### 6.3 Actualizar rol

```
PUT /admin/role/{id}
```

- **Autenticación**: JWT + RBAC

**Cuerpo de la petición**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| name | string | No | Nombre del rol |
| description | string | No | Descripción |
| status | int | No | 0=deshabilitado, 1=habilitado |
| permission_ids | array{int} | No | Matriz de ID de permisos; si se envía, los permisos del rol se sincronizan (sobrescriben) |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "内容编辑",
    "slug": "editor",
    "description": "更新后的描述",
    "status": 1
  }
}
```

### 6.4 Eliminar rol

```
DELETE /admin/role/{id}
```

- **Autenticación**: JWT + RBAC
- **Operación sensible**: requiere confirmación de contraseña

**Cuerpo de la petición**:
```json
{
  "password": "admin_password"
}
```

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Al eliminar, las asociaciones del rol con todos los permisos y usuarios se deshacen automáticamente y luego el registro del rol se elimina físicamente.

## 7. Gestión de permisos

Los permisos usan una estructura de árbol (autorreferencia parent_id) y se dividen en tres tipos. El endpoint de lista devuelve el árbol de permisos completo.

### 7.1 Árbol de permisos

```
GET /admin/permission
```

- **Autenticación**: JWT + RBAC

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "id": "p1p2p3p4",
      "parent_id": "0",
      "name": "用户管理",
      "slug": "/admin/user",
      "type": 1,
      "icon": "people",
      "path": "/user",
      "sort": 1,
      "created_at": "2026-01-01 00:00:00",
      "children": [
        {
          "id": "p5p6p7p8",
          "parent_id": "p1p2p3p4",
          "name": "用户列表",
          "slug": "/admin/user/index",
          "type": 2,
          "icon": "",
          "path": "/user/index",
          "sort": 1
        }
      ]
    }
  ]
}
```

| Campo | Tipo | Descripción |
|------|------|------|
| id | string | Cifrado con hashid |
| parent_id | string | hashid del permiso padre; "0" representa el nodo raíz |
| name | string | Nombre del permiso |
| slug | string | Identificador del permiso (ruta/botón) |
| type | int | 1=menú, 2=botón, 3=API |
| icon | string | Icono de menú (nombre de icono Material) |
| path | string | Ruta del frontend |
| sort | int | Valor de ordenación (ascendente) |
| children | array? | Lista de permisos hijos (recursiva); ausente si no hay nodos hijos |

### 7.2 Crear permiso

```
POST /admin/permission
```

- **Autenticación**: JWT + RBAC

**Cuerpo de la petición**:
```json
{
  "parent_id": 0,
  "name": "系统设置",
  "slug": "/admin/config",
  "type": 1,
  "icon": "settings",
  "path": "/config",
  "sort": 99
}
```

| Campo | Tipo | Obligatorio | Regla de validación | Descripción |
|------|------|------|---------|------|
| parent_id | int | No | | ID del permiso padre (tipo INT original), por defecto 0 |
| name | string | Sí | max:50 | Nombre del permiso |
| slug | string | Sí | max:100 | Identificador del permiso |
| type | int | Sí | in:1,2,3 | 1=menú, 2=botón, 3=API |
| icon | string | No | | Icono de menú, por defecto vacío |
| path | string | No | | Ruta del frontend, por defecto vacía |
| sort | int | No | | Valor de ordenación, por defecto 0 |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "p9p0a1b2",
    "parent_id": "0",
    "name": "系统设置",
    "slug": "/admin/config",
    "type": 1,
    "icon": "settings",
    "path": "/config",
    "sort": 99
  }
}
```

### 7.3 Actualizar permiso

```
PUT /admin/permission/{id}
```

- **Autenticación**: JWT + RBAC

**Cuerpo de la petición**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| name | string | No | Nombre del permiso |
| icon | string | No | Icono |
| path | string | No | Ruta |
| sort | int | No | Valor de ordenación |

### 7.4 Eliminar permiso

```
DELETE /admin/permission/{id}
```

- **Autenticación**: JWT + RBAC
- **Operación sensible**: requiere confirmación de contraseña

**Cuerpo de la petición**:
```json
{
  "password": "admin_password"
}
```

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Al eliminar, todos los permisos hijos se borran en cascada (registros cuyo `parent_id` es el ID del permiso actual) y se deshacen las asociaciones con todos los roles.

## 8. Configuración del sistema

Las configuraciones del sistema son únicas por la combinación de `group` + `key`.

### 8.1 Lista de configuraciones

```
GET /admin/config
```

- **Autenticación**: JWT + RBAC

**Parámetros de consulta**:

| Parámetro | Tipo | Obligatorio | Valor por defecto | Descripción |
|------|------|------|------|------|
| page | int | No | 1 | Número de página |
| limit | int | No | 15 | Elementos por página |
| group | string | No | | Filtrar por grupo de configuración |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "c1c2c3c4",
        "group": "system",
        "key": "site_name",
        "value": "开放管理后台",
        "type": "string",
        "description": "站点名称",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 20,
    "page": 1,
    "limit": 15
  }
}
```

| Campo | Tipo | Descripción |
|------|------|------|
| id | string | hashid |
| group | string | Grupo de configuración (p. ej. `system`, `email`, `storage`) |
| key | string | Clave de configuración |
| value | string | Valor de configuración |
| type | string | Indicación del tipo de valor (`string`, `integer`, `boolean`, `json`, etc.) |
| description | string | Descripción de la configuración |

### 8.2 Crear configuración

```
POST /admin/config
```

- **Autenticación**: JWT + RBAC

**Cuerpo de la petición**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| Campo | Tipo | Obligatorio | Regla de validación | Descripción |
|------|------|------|---------|------|
| group | string | Sí | max:100 | Grupo de configuración |
| key | string | Sí | max:100 | Clave de configuración (única dentro del mismo grupo) |
| value | string | Sí | | Valor de configuración |
| type | string | No | | Tipo de valor, por defecto `string` |
| description | string | No | | Descripción de la configuración, por defecto vacía |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "c5c6c7c8",
    "group": "email",
    "key": "smtp_host",
    "value": "smtp.example.com",
    "type": "string",
    "description": "SMTP 服务器地址"
  }
}
```

**Errores posibles**:
- 422: El elemento de configuración ya existe (mismo group + key)

### 8.3 Actualizar configuración

```
PUT /admin/config/{id}
```

- **Autenticación**: JWT + RBAC

**Cuerpo de la petición**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| value | string | No | Actualizar el valor de configuración |
| type | string | No | Actualizar el tipo de valor |
| description | string | No | Actualizar el texto de la descripción |

### 8.4 Eliminar configuración

```
DELETE /admin/config/{id}
```

- **Autenticación**: JWT + RBAC
- **Operación sensible**: requiere confirmación de contraseña

**Cuerpo de la petición**:
```json
{
  "password": "admin_password"
}
```

Elimina físicamente el registro de configuración.

## 9. Registro de operaciones

El registro de operaciones es de solo lectura; el middleware `OperationLog` escribe automáticamente en cada petición POST/PUT/DELETE. Los campos almacenados incluyen `user_id`, `action`, `method`, `path`, `ip`, `source`, `input`.

### 9.1 Lista del registro de operaciones

```
GET /admin/log
```

- **Autenticación**: JWT + RBAC

**Parámetros de consulta**:

| Parámetro | Tipo | Obligatorio | Valor por defecto | Descripción |
|------|------|------|------|------|
| page | int | No | 1 | Número de página |
| limit | int | No | 15 | Elementos por página |
| user_id | int | No | | Filtro exacto por ID de usuario (tipo INT original) |
| action | string | No | | Filtro exacto por acción |
| path | string | No | | Filtro difuso por ruta de petición |
| start_date | string | No | | Fecha de inicio (formato Y-m-d) |
| end_date | string | No | | Fecha de fin (formato Y-m-d) |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "l1l2l3l4",
        "user_name": "admin",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "source": "web",
        "input": "{\"username\":\"admin\"}",
        "created_at": "2026-05-21 10:30:00"
      }
    ],
    "total": 500,
    "page": 1,
    "limit": 15
  }
}
```

| Campo | Tipo | Descripción |
|------|------|------|
| id | string | hashid |
| user_name | string | Nombre de usuario de la operación (mediante la relación user; las operaciones no autenticadas muestran "系统") |
| action | string | Descripción de la acción |
| method | string | Método HTTP (POST/PUT/DELETE) |
| path | string | Ruta de la petición |
| ip | string | IP del cliente |
| source | string | Origen de la petición |
| input | string | Parámetros de la petición como cadena JSON (sin archivos) |
| created_at | string | Hora de la operación (datetime) |

## 10. Perfil personal

Los endpoints del perfil solo requieren autenticación JWT (sin verificación RBAC — el middleware `AdminPermission` debe añadirlos a la lista blanca).

### 10.1 Actualizar información personal

```
PUT /admin/profile
```

- **Autenticación**: JWT

**Cuerpo de la petición**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| real_name | string | No | Nombre real |
| phone | string | No | Teléfono (cifrado con Encryptable) |
| email | string | No | Correo (cifrado con Encryptable) |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13800138000",
    "email": "me@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00"
  }
}
```

En la respuesta, `phone` y `email` se devuelven en texto plano; `password` y `id_card` se eliminan.

### 10.2 Cambiar contraseña

```
PUT /admin/profile/password
```

- **Autenticación**: JWT

**Cuerpo de la petición**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| Campo | Tipo | Obligatorio | Regla de validación | Descripción |
|------|------|------|---------|------|
| old_password | string | Sí | | Contraseña actual |
| new_password | string | Sí | min:6, max:32 | Nueva contraseña |

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**Errores posibles**:
- 422: Introduzca la contraseña antigua y la nueva
- 422: La contraseña antigua es incorrecta
- 422: La nueva contraseña debe tener entre 6 y 32 caracteres

### 10.3 Cerrar sesión

```
POST /admin/profile/logout
```

- **Autenticación**: JWT

**Cuerpo de la petición**: ninguno (sin requestBody; el token se lee del encabezado Authorization)

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

Lógica de cierre de sesión: decodificar el JWT para obtener la vigencia restante (exp - now), escribir el hash md5 del token en la lista negra de Redis `jwt_blacklist:{md5}` con TTL = vigencia restante. Los tokens de la lista negra son bloqueados por el middleware `AdminAuth`, que devuelve 401.

Sin token se devuelve 401. Los tokens caducados/no válidos (la decodificación lanza una excepción) se consideran igualmente un cierre de sesión correcto.

## 11. Importación y exportación

### 11.1 Exportar Excel

```
POST /admin/export/excel
```

- **Autenticación**: JWT + RBAC
- **Tipo de respuesta**: descarga de archivo (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Cuerpo de la petición**:
```json
{
  "table": "admin_user",
  "columns": ["username", "real_name", "phone", "status", "created_at"],
  "conditions": {
    "status": 1
  },
  "title": "用户列表导出"
}
```

| Campo | Tipo | Obligatorio | Valor por defecto | Descripción |
|------|------|------|------|------|
| table | string | No | `admin_user` | Tabla a exportar. Compatibles: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | No | | Matriz de nombres de columnas a exportar; vacía exporta todas las columnas de la tabla |
| conditions | object | No | `{}` | Condiciones de filtrado, pares clave-valor; los valores no vacíos se usan en WHERE |
| title | string | No | `数据导出` | Título de Excel (se muestra como nombre de hoja) |

**Tablas y columnas compatibles**:

| table | Columnas disponibles |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

Los campos sensibles `phone`, `email`, `id_card` se enmascaran automáticamente al exportar. Límite de datos: 10000 filas. La primera fila de Excel queda fijada y el autofiltro está activado.

### 11.2 Exportar PDF

```
POST /admin/export/pdf
```

- **Autenticación**: JWT + RBAC
- **Tipo de respuesta**: descarga de archivo (`application/pdf`, A4 horizontal)

**Cuerpo de la petición**:
```json
{
  "type": "dashboard",
  "title": "管理仪表盘报告",
  "data": {
    "stats": [
      { "label": "用户总数", "value": "1280" }
    ]
  }
}
```

O modo tabla:
```json
{
  "type": "table",
  "title": "用户列表",
  "data": {
    "columns": ["用户名", "真实姓名", "状态"],
    "rows": [
      ["admin", "管理员", "启用"],
      ["editor", "编辑员", "启用"]
    ]
  }
}
```

| Campo | Tipo | Obligatorio | Valor por defecto | Descripción |
|------|------|------|------|------|
| type | string | No | `table` | Tipo de exportación: `table` / `dashboard` |
| title | string | No | `数据导出` | Título del PDF |
| data | object | No | `{}` | Datos a exportar |

Con `type=dashboard`, `data` debe contener una matriz `stats` (se renderiza como tarjetas); con `type=table`, `data` debe contener las matrices `columns` y `rows`.

La plantilla del PDF incluye la información de copyright y una marca de tiempo de exportación.

### 11.3 Importar usuarios (Excel)

```
POST /admin/import/users
```

- **Autenticación**: JWT + RBAC
- **Tipo de petición**: `multipart/form-data` (subida de archivo)

**Campos del formulario**:

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| file | file | Sí | Formato `.xlsx` o `.xls` |

**Requisitos de las columnas de Excel**:

| Nombre de columna | Obligatorio | Descripción |
|------|------|------|
| username | Sí | Nombre de usuario (único) |
| password | Sí | Contraseña (almacenada como hash bcrypt) |
| real_name | Sí | Nombre real |
| phone | No | Teléfono |
| email | No | Correo |
| status | No | Estado, por defecto 1 |

La fila 1 es el encabezado de columnas (no distingue mayúsculas); los datos empiezan en la fila 2.

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "导入完成",
  "data": {
    "total": 10,
    "success": 8,
    "failed": 2,
    "errors": [
      { "row": 3, "reason": "用户名为空" },
      { "row": 7, "reason": "用户名 zhangsan 已存在" }
    ]
  }
}
```

| Campo | Tipo | Descripción |
|------|------|------|
| total | int | Número total de filas (sin la fila de encabezado) |
| success | int | Número importado correctamente |
| failed | int | Número de fallos |
| errors | array | Detalles de los fallos: row (número de fila de Excel) y reason (causa) |

## 12. Subida de archivos

```
POST /admin/upload
```

- **Autenticación**: JWT + RBAC
- **Tipo de petición**: `multipart/form-data`

**Campos del formulario**:

| Campo | Tipo | Obligatorio | Descripción |
|------|------|------|------|
| file | file | Sí | El archivo a subir |

**Tipos de archivo permitidos**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**Tamaño máximo de archivo**: 10MB

**Ejemplo de respuesta**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

Los archivos se guardan en directorios por fecha `public/upload/{Y-m-d}/` con nombre `md5(uniqid) + extensión original`. `url` es una ruta relativa a la raíz del sitio.

**Errores posibles**:
- 422: Seleccione un archivo (no subido)
- 422: Tipo de archivo no compatible
- 422: El tamaño del archivo no puede superar los 10MB
- 500: Fallo en la subida del archivo (archivo no válido)

## 13. Encabezados de respuesta

Todos los endpoints (inyectados a nivel de middleware global) incluyen los siguientes encabezados de respuesta:

| Encabezado | Descripción |
|----|------|
| `X-RateLimit-Limit` | Límite máximo de frecuencia (número) |
| `X-RateLimit-Remaining` | Número de peticiones restantes |
| `X-RateLimit-Reset` | Marca de tiempo de reinicio de la ventana de límite |
| `Retry-After` | Solo se devuelve al activarse el límite; segundos de espera recomendados |
| `X-Content-Type-Options` | `nosniff` (predeterminado de webman, desactiva el sniffing MIME) |
| `X-Frame-Options` | `DENY` (proporcionado por el middleware CORS/configuración base de webman) |

Detalles del límite de frecuencia:
- Límite global por defecto: 60/min / IP+ruta
- Endpoint de inicio de sesión `/api/auth/login`: 10/min
- Endpoint de registro `/api/auth/register`: 5/min
- Usa el algoritmo de ventana deslizante atómica de Redis (Lua ZSET), evita las condiciones de carrera TOCTOU
- Si Redis no está disponible, fail open (dejar pasar), las peticiones no se bloquean

## 14. Flujo de autenticación

Secuencia de autenticación completa:

```
1. El cliente solicita POST /api/captcha/generate
   (Encabezado de petición: API-Version: v1)
    ↓
   El servidor devuelve: key + type(click|slider|rotate) + imagen base64 + extra(datos según el tipo)
   
2. El usuario completa la interacción del captcha (clic/arrastrar/girar) y el cliente recoge la respuesta
   
3. El cliente solicita POST /api/captcha/verify
   (Encabezado de petición: API-Version: v1, Content-Type: application/json)
   Cuerpo de la petición: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // matriz de coordenadas
   - type=slider: clicks = 120                   // desplazamiento en X
   - type=rotate: clicks = 315                   // ángulo de rotación
    ↓
   Servidor:
   a. Lee los datos captcha:key del almacenamiento (TTL 300s)
   b. Valida la respuesta según el type (click: distancia euclidiana ≤18px / slider: ±4px / rotate: ±5°)
   c. Validación correcta → escribe Redis `captcha_verified:{key}` = 1 (TTL 300s)
   d. Validación fallida → devuelve 422, contador +1, key invalidada tras 3 intentos
    ↓
   El servidor devuelve: { valid: true/false }

4. El cliente solicita POST /api/auth/login
   (Encabezado de petición: API-Version: v1, Content-Type: application/json)
   Cuerpo de la petición: { username, password(cifrado), captcha_key }
    ↓
   Servidor:
   a. Validación de parámetros → 422
   b. Comprueba si existe captcha_verified:{key} → 422
   c. Elimina captcha_verified:{key} (uso único)
   d. Descifra la contraseña: EncryptionService::decrypt(password) → texto plano
   e. Valida las credenciales (password_verify) → 401
   f. Comprueba el estado de la cuenta → 403/429
   g. Emite el JWT (access + refresh) → 200
   h. Actualiza last_login_at / last_login_ip
    ↓
   El cliente guarda: access_token, refresh_token, expires_in

5. Las peticiones posteriores llevan el JWT
   Encabezado de petición: Authorization: Bearer <access_token>
    ↓
   Middleware AdminAuth:
   a. Extrae el token Bearer
   b. Comprueba la lista negra (Redis jwt_blacklist:{md5}) → 401
   c. Decodifica el JWT y comprueba la caducidad → 401
   d. Establece $request->adminId = campo sub
    ↓
   Middleware AdminPermission:
   a. Resuelve el identificador de permiso de la ruta del recurso
   b. Consulta los roles del usuario → permisos de los roles y hace la correspondencia
   c. Sin permiso → 403
    ↓
   Controller procesa la petición
    ↓
   Response + encabezados X-RateLimit-*

6. Actualizar antes de que caduque el access token
   El cliente solicita POST /api/auth/refresh
   Cuerpo de la petición: { refresh_token: "..." }
    ↓
   El servidor decodifica refresh_token → emite nuevos access + refresh
    ↓
   El cliente actualiza sus tokens locales

7. Cierre de sesión
   El cliente solicita POST /admin/profile/logout
   Encabezado de petición: Authorization: Bearer <access_token>
    ↓
   Servidor:
   a. Decodifica el JWT para obtener el TTL restante
   b. Escribe en la lista negra de Redis: jwt_blacklist:{md5(token)} = 1, TTL = vigencia restante
   c. Devuelve éxito
```

### Estructura del JWT

- **access_token**: `{ sub: <user_id>, username: "<name>" }`, TTL por defecto 7200 segundos (controlado por la configuración JWT `default_expire`)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`, TTL por defecto 1209600 segundos (controlado por la configuración JWT `refresh_expire`, es decir, 14 días)

### Gestión de seguridad

- Las contraseñas se almacenan como hash `PASSWORD_BCRYPT`
- Las contraseñas se cifran en tránsito con AES-256-CBC-HMAC (el cliente cifra → el servidor descifra), con retroceso a texto plano
- Los campos sensibles (phone, email, id_card) se cifran/descifran de forma transparente a nivel de base de datos con `erikwang2013/encryptable`
- Los ID a nivel de API se transmiten cifrados con `erikwang2013/hashids`, evitando exponer la secuencia original de ID snowflake
- SecurityFilter analiza globalmente XSS, inyección SQL, path traversal e inyección de comandos; misma IP 5 veces/60s → lista negra temporal de 15 minutos
- Las operaciones sensibles (eliminar usuarios, roles, permisos, configuraciones) requieren confirmación de contraseña del usuario conectado
- Límite de sesiones simultáneas: como máximo 3 tokens válidos por usuario; al iniciar sesión desde un 4.º dispositivo, el token más antiguo se añade forzosamente a la lista negra
- Bloqueo de cuenta: 5 inicios de sesión fallidos consecutivos activan un bloqueo de 15 minutos, durante el cual se devuelve 429

## 15. Despliegue y operación

### Docker Compose

La raíz del proyecto proporciona `docker-compose.yml` que orquesta 5 servicios (Nginx, webman app, MySQL, Redis, Elasticsearch). PHP se construye mediante el `Dockerfile` (basado en `php:8.3-cli`, con OPcache activado).

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` define el pipeline de integración continua de GitHub Actions:
- Comprobación de sintaxis `php -l`
- Pruebas unitarias PHPUnit
- Análisis estático `flutter analyze`

### Copia de seguridad de la base de datos

El directorio `database/backup/` proporciona scripts de copia de seguridad y restauración:
- `backup.sh` — copias comprimidas con mysqldump + gzip, limpia automáticamente las copias de más de 30 días
- `restore.sh` — restauración interactiva que lista las copias disponibles

### Configuración de seguridad de Nginx

Para el despliegue en producción, consulte `docs/nginx-security.conf` para el endurecimiento de seguridad del proxy inverso.
