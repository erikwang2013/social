# Informe de pruebas unitarias de PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Fecha: 2026-08-27
- Ejecución: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Alcance: admin/ (panel de administración webman) + service/ (servicio principal webman)

## Resumen general

| Proyecto | Casos de prueba | Aserciones | Resultado |
|------|------|------|------|
| service | 159 | 408 | ✅ Todo aprobado (OK) |
| admin | 67 | 180 | ✅ Todo aprobado (OK) |

## Notas de entorno

- MySQL 127.0.0.1:3306 (root, sin contraseña); bases `social` (social_*) y `open_admin` (erik_*) creadas y con datos (rol super_admin, 39 permisos)
- Redis 127.0.0.1:6379 en ejecución (almacenamiento de captcha `poster:captcha:*`); Elasticsearch no iniciado (el health check degrada a unavailable, no cuenta como fallo)
- service en 8788, admin en 8791
- Ni service ni admin tienen `.env` (el repositorio eliminó los env subidos por error, commit e5379fc); las apps se apoyan en los fallbacks `getenv('X') ?: valor por defecto` de `config/*.php`
- **La extensión Imagick está cargada pero falta la constante `RESOURCETYPE_PIXELS`** (este build solo tiene el nuevo conjunto de constantes RESOURCETYPE_*); el constructor de ImagickDriver de poster-php referencia esa constante y se bloquea

## service (159/159 todo en verde)

- Coincide con la línea base del lote anterior; cubre: autenticación/middleware/JWT, usuarios, publicaciones, comentarios, seguimientos, notificaciones, sincronización de búsqueda, IM, salas, llamadas (CallCenter/CallState), voz, relaciones de modelos, manejo de acciones (WS)
- M5 añadió el módulo en vivo (LiveCenter: crear/detalle/danmaku/vínculo de micrófono/cerrar), 23 casos, sin regresiones

## admin (lote anterior 49/60 → este lote 67/67 todo en verde)

### Corrección: defecto real de código (1 lugar)

| Ubicación | Causa raíz | Corrección |
|------|------|------|
| `config/poster.php` | `image.driver` por defecto `auto`; DriverFactory elige ImagickDriver al detectar la extensión Imagick, pero el Imagick de esta máquina no tiene la constante `RESOURCETYPE_PIXELS` → la generación de captcha/cartel da 500 directo (el servicio en línea también se ve afectado) | Se añadió una guarda de constante en la detección del driver: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`; retrocede automáticamente a GD si falta la constante |

### Corrección: aserciones obsoletas (actualizadas tras revisar el código actual)

| Archivo de prueba | Caso | Causa raíz | Corrección |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 fallidos + 1 error) | Afirma que existen `.env`/`.env.example` y que getenv tiene valores; pero el repositorio eliminó los archivos env y no se pueden reconstruir | Reescrito como contrato "funcionar sin .env": cada clave `getenv()` debe tener un valor por defecto `?:`, la configuración por defecto apunta a servicios locales (127.0.0.1:3306/open_admin), tipos de la configuración crítica correctos |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser ya no usa el trait Searchable (ahora `Erikwang2013\Encryptable\Encryptable` para cifrado/descifrado transparente de campos; `toSearchableArray()` se conserva) | Aserción cambiada al trait Encryptable; la aserción toSearchableArray ya pasaba, se conserva |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` ahora usa el formato de clave de grupo global `'@'`; el array de nivel superior ya no contiene directamente las clases de middleware | Aserción cambiada para verificar que `$middlewares['@']` contiene Cors y RateLimit |
| CaptchaTest | los 7 casos (originalmente 6 errores + 1 fallido) | Doble obsolescencia: (a) constante Imagick faltante (ya corregida por poster.php); (b) aserciones basadas en el contrato antiguo de poster-php — `extra.targets` (con x/y) cambió a `extra.texts` (solo text+order), las coordenadas viven solo en la capa de almacenamiento; el formato de clic cambió de `['x'=>, 'y'=>]` a pares numéricos `[x, y]` | Reescrito según el contrato actual: estructura/cantidades de dificultad (2/3/4)/validación de campos; el clic correcto lee coordenadas de Redis (`poster:captcha:{key}` → `data.targets`) y valida; clic erróneo falla; tras max_attempts (3) la clave se consume/elimina; unicidad de la clave |

### Nuevas pruebas (1 archivo, 12 casos)

`tests/AdminControllerTest.php` (con cabecera de copyright), que cubre:

- **BaseController::decodeId** (el comportamiento 404 recién corregido): los viajes de ida y vuelta encode/decode son consistentes; un hashid inválido lanza `support\exception\NotFoundException` con code=404; encodeIds solo reescribe campos ID
- **RoleController**: la actualización del rol super_admin devuelve 403 (datos reales de DB)
- **PermissionController::buildTree**: anidamiento del árbol de permisos (2 niveles) + todos los ids de nodos con hashid
- **ConfigController**: falta group/key/value → validación 422; hashid inválido → 404
- **ExportController**: el export `admin_user` lista los campos sensibles phone/email/id_card (demás tablas vacías); el HTML del PDF escapa título/valores de celda con htmlspecialchars (protección XSS) e incluye la declaración de copyright

### Notas conocidas

- El Request de webman construido en las pruebas se pasa como mensaje HTTP crudo (buffer) — el parámetro del constructor del Request de workerman es un buffer; pasar solo method/uri no permite parsear el cuerpo POST; ver comentarios en AdminControllerTest
- El caso de clic correcto del captcha lee los objetivos almacenados de Redis; si Redis no está disponible, el caso se marca markTestSkipped y no afecta el resultado de la suite

## No cubierto / por añadir

- El cifrado/descifrado Encryptable de los modelos de admin, el middleware OperationLog/AdminPermission y las rutas de caché RBAC siguen sin pruebas unitarias; se recomienda cubrirlos con pruebas de API o en un lote posterior
- Las rutas de service que dependen de servicios externos (ES/gRPC) siguen solo con validación unitaria mediante stubs; el nivel de integración se cubre con pruebas de API
