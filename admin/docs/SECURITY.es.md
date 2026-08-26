# Documento de Arquitectura de Seguridad

**语言 / Languages:** [中文](SECURITY.md) · [English](SECURITY.en.md) · [한국어](SECURITY.ko.md) · [Русский](SECURITY.ru.md) · [Deutsch](SECURITY.de.md) · [Français](SECURITY.fr.md) · [Español](SECURITY.es.md) · [Português](SECURITY.pt.md) · [हिन्दी](SECURITY.hi.md) · [العربية](SECURITY.ar.md) · [বাংলা](SECURITY.bn.md) · [Bahasa Indonesia](SECURITY.id.md) · [日本語](SECURITY.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Visión general de la defensa en profundidad

El sistema adopta un modelo de defensa en profundidad de 7 capas. Las solicitudes maliciosas se filtran capa por capa de fuera hacia dentro, de modo que incluso si falla una sola capa, las líneas de defensa posteriores siguen cubriendo.

Toda la cadena de middleware se ejecuta en el siguiente orden (ver `config/middleware.php`):

```
请求 → Cors → Locale(Accept-Language) → SecurityMiddleware (erikwang2013/security-php, 31种检测器) → RateLimit → [路由组中间件: AdminAuth → AdminPermission → OperationLog] → Controller
```

| Capa | Middleware / mecanismo | Objetivo de protección |
|----|--------|---------|
| 1 | SecurityMiddleware (erikwang2013/security-php) | 31 detecciones de ataques + validación de métodos HTTP + límite de tamaño del cuerpo de la solicitud + validación de Content-Type + CSRF + lista negra de escalada de ataques por IP |
| 2 | Cors | Seguridad entre orígenes + inyección de cabeceras de respuesta de seguridad |
| 3 | RateLimit | Limitación de frecuencia de ventana deslizante Redis, anti fuerza bruta |
| 4 | AdminAuth | Autenticación JWT + cierre de sesión mediante lista negra |
| 5 | AdminPermission | Autorización RBAC con granularidad method.path |
| 6 | OperationLog | Auditoría de operaciones + seguimiento del origen del cliente |
| 7 | Cifrado de datos | Ofuscación de IDs Hashids + cifrado de BD Encryptable + cifrado de transporte EncryptionService |

Las capas de frontend (Flutter) cuentan con su propia validación de entrada independiente; el backend no confía en nada, y cada capa se defiende de forma independiente.

---

## 2. Motor de detección de ataques

## 2. 攻击检测引擎 (erikwang2013/security-php)

La detección de ataques se ha migrado del SecurityMiddleware propio al paquete de seguridad dedicado `erikwang2013/security-php` v1.1+, que proporciona **31 detectores** que cubren 5 grandes categorías de ataques.

### 2.1 Clasificación de detectores

**Ataques de inyección (11):** XSS, inyección SQL, inyección de comandos, inyección NoSQL, inyección LDAP, inyección XPath, JNDI/Log4Shell, inclusión del lado del servidor SSI, inyección GraphQL, inyección de plantillas SSTI

**Ataques de protocolo y solicitud (9):** SSRF, XXE, inyección de cabeceras de respuesta HTTP, ataque de cabecera Host, Request Smuggling, Open Redirect, evasión de CORS, secuestro de WebSocket, DNS Rebinding

**Validación de la capa de protocolo HTTP (6):** validación de métodos HTTP (405), límite de tamaño del cuerpo de la solicitud (413), validación de Content-Type (415), verificación de Origin CSRF, lista negra de escalada de ataques por IP, detección de fugas de datos sensibles

**Ataques de datos y serialización (5):** deserialización PHP, inyección de fórmulas CSV, inyección de cabeceras de correo, ataques JWT (análisis estructurado), JS Prototype Pollution

**Ataques de archivos y rutas (2):** path traversal, subida de archivos maliciosos

### 2.2 Modos de tratamiento

Cada detector admite de forma independiente dos modos:
- `block` — bloquear al detectar un ataque, devolver el código de estado configurado
- `log` — solo registrar, sin bloquear (`header_injection`, `ssti`, `nosql_injection` están por defecto en modo log para evitar falsos positivos)

### 2.3 Lista negra de escalada de ataques por IP

Si una misma IP dispara 5 detecciones de ataque en 60 segundos, se banea automáticamente durante 15 minutos. El backend de almacenamiento puede ser Redis (distribuido), File (JSON de un solo nodo) o Cache (archivos independientes para alta concurrencia); la configuración actual usa Redis.

### 2.4 Registros de seguridad

Ubicación del archivo: `runtime/logs/security.log` (rotación automática, 10 MB por archivo)

---

## 4. Cabeceras de respuesta de seguridad

Todas las cabeceras se inyectan en el middleware `Cors` y se añaden a cada respuesta mediante `$response->withHeaders()`.

| Cabecera | Valor | Función |
|----|-----|------|
| Access-Control-Allow-Origin | `*` | Permitir solicitudes entre orígenes desde cualquier fuente (escenario de consola de administración de intranet) |
| Access-Control-Allow-Methods | `GET,POST,PUT,DELETE,OPTIONS` | Conjunto de métodos permitidos |
| Access-Control-Allow-Headers | `Authorization,Content-Type,API-Version` | Cabeceras personalizadas permitidas |
| Access-Control-Max-Age | `86400` | Caché de la respuesta de pre-vuelo durante 24 horas |
| X-Content-Type-Options | `nosniff` | Evitar el sniffing MIME del navegador |
| X-Frame-Options | `DENY` | Denegar todo embebido en iframe, anti clickjacking |
| X-XSS-Protection | `1; mode=block` | Activar el filtro XSS integrado del navegador y bloquear el renderizado de la página |
| Referrer-Policy | `strict-origin-when-cross-origin` | Mismo origen: URL completa; entre orígenes: solo el dominio |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | Deshabilitar las API de cámara/micrófono/geolocalización en todo el sitio |

Las solicitudes de pre-vuelo OPTIONS devuelven directamente una respuesta 204 vacía y no entran en la cadena de middleware posterior.

### 4.2 Content-Security-Policy (CSP)

Se inyecta junto con las demás cabeceras de seguridad en el middleware Cors, ofreciendo defensa en profundidad al restringir los orígenes de los recursos que el navegador puede cargar y ejecutar.

| Cabecera | Valor | Función |
|----|-----|------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | Restringir los orígenes de scripts/estilos/imágenes/conexiones/frames/formularios y otros recursos |
| X-Permitted-Cross-Domain-Policies | `none` | Prohibir la carga de archivos de políticas entre dominios como Adobe Flash/PDF |

Puntos clave de la política CSP:
- `default-src 'self'`: solo se permiten por defecto recursos del mismo origen
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'`: permite scripts del mismo origen + scripts en línea (necesario para Flutter Web) + eval (necesario para la depuración de Flutter Web)
- `frame-ancestors 'none'`: prohibir el embebido en iframes de cualquier página, doble protección con X-Frame-Options: DENY
- `base-uri 'self'`: limitar la etiqueta `<base>` al mismo origen
- `form-action 'self'`: limitar el envío de formularios al mismo origen

---

## 5. Estrategia de limitación de frecuencia

### Algoritmo

Ventana deslizante Redis Sorted Set + script Lua atómico, para operaciones críticas:

```lua
-- 1. 清理窗口外的旧记录
redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, windowStart)
-- 2. 检查当前窗口计数
local count = redis.call('ZCARD', KEYS[1])
-- 3. 超限则返回 {0, count}，未超限则 ZADD 并返回 {1, count+1}
if count >= limit then return {0, count} end
redis.call('ZADD', KEYS[1], now, now . '.' . random)  -- 随机后缀避免同毫秒覆盖
redis.call('EXPIRE', KEYS[1], window + 10)
```

El script Lua se ejecuta en un solo hilo en el servidor Redis, **naturalmente atómico**, eliminando las condiciones de carrera TOCTOU (Time-of-check to Time-of-use).

### Configuración de limitación

| Ruta | Límite | Ventana | Escenario |
|------|------|------|------|
| Por defecto (todas las rutas) | 60 veces/minuto | 60s | API general |
| `/api/auth/login` | 10 veces/minuto | 60s | Inicio de sesión (anti fuerza bruta) |
| `/api/auth/register` | 5 veces/minuto | 60s | Registro (anti registro masivo) |

### Cabeceras de respuesta

Al dispararse la limitación, se devuelve HTTP 429 con cuerpo JSON:
```json
{"code": 429, "message": "请求过于频繁，请稍后再试", "data": []}
```

Todas las respuestas (incluidas las normales) llevan las siguientes cabeceras:

| Cabecera | Descripción |
|----|------|
| X-RateLimit-Limit | Número máximo de solicitudes permitidas en la ventana actual |
| X-RateLimit-Remaining | Solicitudes restantes disponibles en la ventana actual |
| X-RateLimit-Reset | Marca de tiempo Unix del restablecimiento de la ventana |
| Retry-After | Solo presente al limitar; segundos de espera recomendados |

### Estrategia de degradación

Cuando Redis está anómalo (timeout de conexión, no disponible, etc.), **fail-open**:

```php
try {
    $result = Redis::eval($lua, ...);
} catch (\Throwable $e) {
    return $handler($request); // Redis down, 放行所有请求
}
```

Mejor perder temporalmente la protección de limitación que bloquear las solicitudes de negocio normales.

### 5.4 Mecanismo de bloqueo de cuenta

Además de la limitación de frecuencia, el endpoint de inicio de sesión añade un mecanismo de **bloqueo de cuenta** para prevenir la fuerza bruta dirigida contra usuarios específicos.

**Proceso de bloqueo**:

```
登录失败 → Redis INCR account_lockout:{userId} TTL=900s
连续 5 次失败 → Redis SETEX account_locked:{userId} 900 1
            → 返回 429 "账号已被锁定，请15分钟后再试"
            → 清除计数器 DEL account_lockout:{userId}
```

**Comportamiento durante el bloqueo**:

Durante el periodo de bloqueo, todas las solicitudes de inicio de sesión devuelven directamente 429 sin verificar la contraseña, bloqueando por completo los intentos de fuerza bruta.

**Constantes de configuración**:

| Constante | Valor | Significado |
|------|-----|------|
| MAX_LOGIN_ATTEMPTS | 5 | Número máximo de fallos consecutivos |
| LOCKOUT_DURATION | 900 | Duración del bloqueo en segundos, es decir, 15 minutos |

Nota: el bloqueo de cuenta se basa en `userId` y no en la IP; cambiar de IP no permite evadirlo. Combinado con la limitación de frecuencia por IP (10 veces/minuto), forma una doble protección:
- Nivel IP: la limitación de 10 veces/minuto bloquea la fuerza bruta distribuida
- Nivel cuenta: el bloqueo tras 5 fallos bloquea la fuerza bruta dirigida

---

## 6. Autenticación y autorización

### 6.1 Autenticación JWT

Implementada por el middleware AdminAuth, montado en los grupos de rutas que requieren autenticación.

**Configuración de parámetros** (`config/plugin/erikwang2013/jwt/jwt`, inyectada mediante `.env`):

| Parámetro | Valor | Descripción |
|------|-----|------|
| Algoritmo | HS256 | Firma simétrica HMAC-SHA256 |
| Secreto | `JWT_SECRET` | Inyectado mediante variable de entorno; debe cambiarse en producción |
| TTL access_token | 7200s (2h) | `JWT_TTL` |
| TTL refresh_token | 1209600s (14d) | `JWT_REFRESH_TTL` |
| Emisor | `open-admin` | `JWT_ISSUER` |
| Audiencia | `open-admin` | `JWT_AUDIENCE` |

**Extracción del token**: se extrae de la cabecera `Authorization: Bearer <token>`; eliminar el prefijo `Bearer ` para obtener el JWT crudo.

**Proceso de autenticación**:
1. Token vacío → directamente 401 `{"code": 401, "message": "未登录"}`
2. Comprobar la lista negra de Redis `jwt_blacklist:{md5(token)}` → coincidencia → 401 `Token已失效，请重新登录`
3. Decodificación JWT → error (caducado/firma no coincidente) → 401 `Token已过期或无效`
4. Éxito → inyectar `$request->adminId` y `$request->adminUsername`

**Mecanismo de lista negra**: al cerrar sesión, `md5(token)` se escribe en Redis con TTL igual a la validez restante del JWT. Cuando Redis falla, la comprobación de la lista negra se omite (fail-open); en ese caso, un token con sesión cerrada puede usarse brevemente, pero la corta validez del propio JWT (2h) sirve como protección de respaldo.

### 6.2 Límite de sesiones simultáneas

Para evitar el uso indebido de un token filtrado en múltiples dispositivos, el sistema limita el número de tokens válidos que un mismo usuario puede tener simultáneamente.

**Lógica de limitación**:

```
登录成功 → 签发新 Token
         → 查询当前用户有效 Token 数量: Redis SCARD user_tokens:{userId}
         → 若数量 >= 3（MAX_CONCURRENT_SESSIONS）:
            → 按创建时间升序排列，移除最旧的 Token:
              Redis SREM user_tokens:{userId} <oldest_token_id>
              Redis SETEX jwt_blacklist:{md5(oldest_token)} TTL remaining
         → 将新 Token 加入集合: Redis SADD user_tokens:{userId} <new_token_id>
            Redis SET user_token:{token_id} {userId} EX {TTL}
```

**Constante de configuración**:

| Constante | Valor | Significado |
|------|-----|------|
| MAX_CONCURRENT_SESSIONS | 3 | Número máximo de tokens simultáneos por usuario |

**Escenario de cierre forzado**: cuando el usuario inicia sesión en un 4º dispositivo, el token del 1er dispositivo se añade forzosamente a la lista negra; las solicitudes posteriores devuelven 401 "Token已失效，请重新登录".

Al cerrar sesión, el token actual se elimina del conjunto. Cuando un token caduca naturalmente, la clave Redis expira automáticamente y el conjunto se reduce en consecuencia.

### 6.3 Modelo de permisos RBAC

Implementado por el middleware AdminPermission.

**Modelo de datos**: asociación de tres niveles User -> Role -> Permission

- `erik_admin_user` (tabla de usuarios)
- `erik_admin_user_role` (tabla de asociación usuario-rol)
- `erik_admin_role` (tabla de roles)
- `erik_admin_role_permission` (tabla de asociación rol-permiso)
- `erik_admin_permission` (tabla de permisos)

**Tipos de permisos**:
| type | Significado | Ejemplo |
|------|------|------|
| 1 | Permiso de menú | Controla la visibilidad de la navegación izquierda |
| 2 | Permiso de botón | Controla los botones de acción de la página (añadir/editar/eliminar) |
| 3 | Permiso de API | Controla las llamadas a interfaces del backend |

Formato del identificador de permiso de API: `{method}.{path}`

Por ejemplo:
- `post.admin/user` — crear usuario
- `put.admin/user` — editar usuario
- `delete.admin/user` — eliminar usuario
- `get.admin/user` — ver lista de usuarios

**Proceso de autorización**:
1. `$request->adminId` vacío → dejar pasar (la ruta no tiene prerrequisito de autenticación configurado)
2. Obtener usuario → roles (omitir roles deshabilitados con `status=0`) → lista de permisos
3. Superadministrador (`slug = '*'`) → dejar pasar directamente
4. Construir `strtolower(method) . '.' . trim(path, '/')` → comparar con la lista de permisos
5. Sin coincidencia → 403 `{"code": 403, "message": "无权限访问"}`

**Doble confirmación**: BaseController proporciona el método `confirmPassword()`; las operaciones sensibles (eliminar usuario, exportar datos, etc.) exigen además introducir la contraseña actual en la capa de Controller, evitando operaciones no autorizadas tras el secuestro de sesión.

---

## 7. Registros de auditoría

### 7.1 Registros de operaciones

El middleware OperationLog registra automáticamente los registros de operaciones de las solicitudes POST / PUT / DELETE. Las solicitudes GET no se registran.

**Campos registrados**:

| Campo | Fuente | Descripción |
|------|------|------|
| id | SnowflakeService::generate() | ID globalmente único |
| user_id | `$request->adminId` | ID del operador, 0 si no ha iniciado sesión |
| action | `$request->method()` | Equivalente a method |
| method | `$request->method()` | POST / PUT / DELETE |
| path | `$request->path()` | Ruta de la solicitud |
| ip | `$request->getRealIp()` | IP real del cliente |
| source | detectSource() | Plataforma de origen del cliente |
| input | Cuerpo de la solicitud (JSON enmascarado) | Datos de la operación enviada |
| created_at | `date('Y-m-d H:i:s')` | Hora de la operación |

**Filtrado de campos sensibles**: recorre recursivamente el cuerpo de la solicitud; los valores de los siguientes campos se reemplazan por `***`:

`password`, `old_password`, `new_password`, `new_password_confirmation`, `token`, `secret`, `access_token`, `refresh_token`

**Detección del origen** (`detectSource()`): por prioridad:

1. Leer primero la cabecera personalizada `X-Client-Platform` (declarada explícitamente por los clientes nativos)
2. Recurrir a la inferencia por la cadena User-Agent (orden de detección del método `detectSource()`):

| Plataforma | Palabras clave UA |
|------|----------|
| iPadOS | `iPad` |
| macOS | `Macintosh`, `Mac OS` |
| Windows | `Windows` |
| Linux | `Linux` |
| iOS | `iPhone`, `iOS`, `CFNetwork` |
| Android | `Android` |
| HarmonyOS | `HarmonyOS`, `OpenHarmony` |
| Web | Valor por defecto de respaldo |

**Tolerancia a fallos**: las excepciones de escritura de registro no bloquean las solicitudes de negocio (`catch (\Throwable)` tragado silenciosamente).

### 7.2 Registros de seguridad

**Ubicación del archivo**: `runtime/logs/security.log`

**Contenido registrado**:
- Registros de intercepción de ataques: categoría del ataque, IP, ruta, campo, origen, fragmento del payload (primeros 200 caracteres)
- Notificaciones de baneo de IP: IP baneada, número de disparos

El permiso del registro es `FILE_APPEND | LOCK_EX`, lo que garantiza escrituras seguras en concurrencia.

---

## 8. Protección de datos

El sistema adopta una estrategia de protección de datos de tres niveles, correspondientes a las tres fases del flujo de datos.

### 8.1 Capa de transporte — EncryptionService

`EncryptionService` utiliza el paquete `erikwang2013/encryption` para cifrar/descifrar campos sensibles en las solicitudes/respuestas de la API.

**Detalles técnicos**:
- Algoritmo: `aes-256-cbc-hmac` (firma HMAC integrada anti manipulación)
- Clave: variable de entorno `ENCRYPTION_KEY`, alineada automáticamente a 32 bytes
- Uso: transporte entre cliente y API de campos como números de teléfono y números de DNI

**Métodos de utilidad de enmascaramiento**:
- `maskPhone('13812341234')` → `138****1234`
- `maskEmail('abc@example.com')` → `a***@example.com` (nombre de usuario de más de 2 caracteres) o `a**@example.com`

### 8.2 Capa de almacenamiento — Encryptable Cast

El modelo `AdminUser` utiliza el cast Eloquent `Erikwang2013\Encryptable\Encryptable`, con los campos correspondientes:

- `email` → cast a Encryptable, cifrado/descifrado automático
- `phone` → cast a Encryptable, cifrado/descifrado automático
- `id_card` → cast a Encryptable, cifrado/descifrado automático

Se cifra automáticamente a texto cifrado al escribir en la base de datos, y se descifra automáticamente a texto plano al leer. El tipo de columna de almacenamiento es `VARCHAR(500)`, y el texto cifrado se almacena en base64.

**Sistema de claves**: utiliza `ENCRYPTABLE_KEY` de forma independiente al cifrado de transporte (`ENCRYPTION_KEY`); la fuga de una clave no compromete la otra capa.

Rotación de claves: la variable de entorno `ENCRYPTION_PREVIOUS_KEYS` admite una lista de claves históricas (separadas por comas). Al leer datos antiguos, se intenta descifrar con las claves históricas; al reescribir, se vuelve a cifrar con la clave actual.

### 8.3 Capa de presentación — Ofuscación de IDs y enmascaramiento

**Ofuscación de IDs Hashids**: `HashidsService` utiliza el paquete `erikwang2013/hashids`.

- Los IDs BIGINT de la base devueltos por las API externas se codifican como cadenas hash (p. ej. `xK3mN9qR2pL7wV8b`)
- Los clientes envían la cadena hash en las solicitudes; el backend la decodifica automáticamente al ID original
- La sal se inyecta mediante la variable de entorno `HASHIDS_SALT`; con distintas sales, los resultados de codificación/decodificación son completamente diferentes
- Longitud mínima del hash: 16 caracteres, con un juego alfanumérico de 62 caracteres
- BaseController proporciona los métodos de conveniencia `encodeId()`, `decodeId()`, `encodeIds()`

**Enmascaramiento en exportación**: al exportar Excel/PDF (ExportController), los campos sensibles se enmascaran de forma uniforme:
- Teléfono: `138****1234`
- Correo: `a***@example.com`
- DNI: totalmente cubierto como `********`

---

## 9. Gestión de claves

Todas las claves se inyectan mediante variables de entorno `.env`; los archivos de configuración las leen con `getenv()` y tienen valores por defecto de respaldo integrados (seguros solo en entornos de desarrollo).

| Variable de entorno | Uso | Paquete | Requisito de producción |
|----------|------|-----|---------|
| JWT_SECRET | Clave de firma JWT | erikwang2013/jwt-webman | Cadena aleatoria de 64+ caracteres |
| JWT_ALGORITHM | Algoritmo de firma JWT | el mismo | Mantener HS256 |
| HASHIDS_SALT | Sal de codificación de IDs | erikwang2013/hashids | Cadena aleatoria |
| SNOWFLAKE_DATACENTER_ID | ID del centro de datos (0-31) | erikwang2013/snowflake-php | Mantener el valor por defecto para un solo CPD |
| ENCRYPTION_KEY | Clave de cifrado de la capa de transporte API | erikwang2013/encryption | Cadena aleatoria de 32 bytes |
| ENCRYPTABLE_KEY | Clave de cifrado de la capa de almacenamiento BD | erikwang2013/encryptable | Cadena aleatoria de 32 bytes, distinta de la clave de transporte |

**Requisitos de seguridad**:
- El archivo `.env` está en `.gitignore`; está estrictamente prohibido subirlo al repositorio
- `.env.example` es un archivo de plantilla público que no contiene claves reales
- En producción, **obligatoriamente** hay que sustituir todas las claves por defecto por cadenas aleatorias
- Se recomienda generar claves con `openssl rand -base64 32`

### Aislamiento del almacenamiento de claves

| Capa | Clave de configuración | Variable de entorno de clave |
|----|--------|-------------|
| Cifrado de transporte | `config/encryption.php` → `key` | `ENCRYPTION_KEY` |
| Cifrado de almacenamiento | `config/encryptable.php` → `key` | `ENCRYPTABLE_KEY` |
| Ofuscación de IDs | `config/hashids.php` → `connections.main.salt` | `HASHIDS_SALT` |
| Firma JWT | `config/plugin/erikwang2013/jwt/jwt` | `JWT_SECRET` |

---

## 10. security.txt (RFC 9116)

El sistema ofrece en `/.well-known/security.txt` un endpoint de información de contacto de seguridad conforme a la norma RFC 9116, para que los investigadores de seguridad encuentren rápidamente el canal de reporte al descubrir vulnerabilidades.

**Modo de acceso**:

```
GET /.well-known/security.txt
```

**Contenido de la respuesta**:

```text
Contact: mailto:security@erik.xyz
Expires: 2027-05-20T00:00:00.000Z
Preferred-Languages: zh, en
Canonical: https://erik.xyz/.well-known/security.txt
Policy: https://erik.xyz/security-policy
```

**Descripción de los campos**:

| Campo | Descripción |
|------|------|
| Contact | Contacto para reportar vulnerabilidades de seguridad |
| Expires | Fecha de caducidad del archivo, requiere actualización periódica |
| Preferred-Languages | Idiomas de comunicación preferidos |
| Canonical | URL canónica de este archivo |
| Policy | Enlace a la política de seguridad / de divulgación de vulnerabilidades |

Este endpoint no está sujeto a limitación de frecuencia, autenticación ni otro middleware; cualquiera puede acceder a él directamente.

---

## 11. Configuración de seguridad de Nginx

El proyecto proporciona `docs/nginx-security.conf` como configuración de referencia para el endurecimiento del proxy inverso Nginx en producción.

**Medidas de seguridad incluidas**:

| Elemento de configuración | Función |
|--------|------|
| `server_tokens off` | Ocultar el número de versión de Nginx |
| `client_max_body_size 10m` | Limitar el tamaño del cuerpo de la solicitud, en coordinación con SecurityMiddleware (erikwang2013/security-php) |
| `limit_req_zone` | Limitación de frecuencia de solicitudes a nivel de Nginx |
| `limit_conn_zone` | Limitación del número de conexiones simultáneas |
| Cabeceras de seguridad `add_header` | Añadir cabeceras de seguridad como X-XSS-Protection a nivel de Nginx |
| `if ($request_method)` | Rechazar métodos HTTP no estándar a nivel de Nginx |
| Configuración SSL/TLS | Configuración moderna TLS 1.2/1.3, suites de cifrado débiles deshabilitadas |
| Ocultar cabeceras del backend | `proxy_hide_header` elimina cabeceras sensibles como la versión de webman |

**Modo de uso**: fusionar la configuración de `docs/nginx-security.conf` en su bloque server de Nginx, ajustándola a su dominio real y rutas de certificados.

---

## 12. Modelo de amenazas

### 12.1 Amenazas protegidas

| Tipo de amenaza | Vector de ataque | Capa de defensa |
|----------|---------|---------|
| Abuso de métodos HTTP | Ataques TRACE/TRACK XST, proxy túnel CONNECT, sondeo de métodos WebDAV | Detector http_method de SecurityMiddleware, lista blanca de métodos 405 |
| Fuerza bruta dirigida | Intentos repetidos de contraseña contra un usuario específico | Bloqueo de cuenta (15 min tras 5 fallos) + RateLimit (login 10/min) + Captcha |
| Fuerza bruta | Intentos repetidos de usuario/contraseña desde IPs distribuidas | RateLimit (login 10/min) + Captcha |
| XSS (scripting entre sitios) | `<script>`, onerror, javascript: | SecurityMiddleware (erikwang2013/security-php) (5 patrones) + cabecera de respuesta X-XSS-Protection + CSP |
| Inyección SQL | UNION SELECT, OR 1=1, evasión por comentarios | SecurityMiddleware (erikwang2013/security-php) (6 patrones) + consultas parametrizadas Eloquent ORM |
| CSRF (falsificación de solicitudes entre sitios) | Sitios maliciosos que forjan solicitudes | Validación Origin/Referer en SecurityMiddleware (erikwang2013/security-php) |
| Path traversal | `../../etc/passwd` | Patrón de path traversal de SecurityMiddleware (erikwang2013/security-php) + lista blanca de extensiones UploadController |
| Inyección de comandos | `;ls`, `` `whoami` ``, `$(cat ...)` | SecurityMiddleware (erikwang2013/security-php) (4 patrones) |
| Secuestro de sesión | Robo de tokens JWT | Validez corta del JWT (2h) + cierre de sesión por lista negra + doble confirmación de contraseña para operaciones sensibles |
| Enumeración de IDs | Adivinar el volumen de datos recorriendo IDs numéricos | Ofuscación Hashids en cadenas aleatorias |
| Fuga de datos | Exfiltración de BD / hombre en el medio / fuga de registros | Cifrado/enmascaramiento de tres niveles + filtrado de campos sensibles OperationLog |
| Ataques DoS | Cuerpos de solicitud sobredimensionados / solicitudes de alta frecuencia | Límite de cuerpo de 10 MB + RateLimit 60/min + lista negra de IPs |
| Elevación de privilegios | Usuarios con bajos privilegios accediendo a interfaces de administración | Autorización RBAC con granularidad method.path |
| Ataques de subida de archivos | Doble extensión shell.php.png | Detección de archivos maliciosos en SecurityMiddleware (erikwang2013/security-php) |

### 12.2 Limitaciones conocidas

| Limitación | Ámbito de impacto | Medidas de mitigación |
|------|---------|---------|
| La protección CSRF solo funciona en navegadores | Los clientes no-navegador (curl, Postman, apps móviles) pueden saltarse las comprobaciones Origin/Referer | Los clientes no-navegador son naturalmente inmunes al CSRF; confiar en la autenticación JWT en lugar de cookies |
| Con Redis no disponible, la limitación y la lista negra degradan a fail-open | Los atacantes pueden evadir la limitación de frecuencia y el bloqueo de alta frecuencia | Vigilar la disponibilidad de Redis con alertas; la lista negra de IPs admite tres backends file/redis/cache para degradar |
| Sin motor WAF independiente | Detección basada en expresiones regulares, no un motor de reglas WAF dedicado | Recomendar Nginx ModSecurity o Cloudflare WAF delante en producción |
| JWT sin estado no puede invalidarse activamente | Los tokens no pueden revocarse en el servidor antes de caducar (salvo lista negra) | Lista negra + TTL corto de 2h reduce la ventana de riesgo |
| Los endpoints de administración no tienen limitación especial | Las API de administración comparten el límite por defecto de 60/min con las API normales | La frecuencia de operaciones de administración es naturalmente baja; por ahora no se necesita diferenciación |
| Límite de retroceso PCRE | El paquete tiene un límite integrado de 1.000.000 de retrocesos + recuperación mediante finally; las entradas extremadamente complejas siguen suponiendo un riesgo de rendimiento | Límite de tamaño del cuerpo de solicitud (10 MB) como respaldo |
