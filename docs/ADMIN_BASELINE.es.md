# Aceptación de línea base Admin (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

Estado de línea base y puntos de entrada de transformación para open-admin (webman v2 + consola de administración Flutter).

## Versión actual y estado de ejecución

| Elemento | Valor |
|---|---|
| Framework | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| Dependencias | `composer install` correcto, 69 paquetes |
| .env | **No existe** (el repositorio no tiene `.env` ni `.env.example`; hay que crearlo localmente según MySQL/Redis) |
| Entrada de migraciones | Ninguna (no hay `think`/`artisan`; webman no incluye migraciones, M0 no tiene tareas de migración) |
| Pruebas | `vendor/bin/phpunit`: 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky — no todo en verde** |

## Módulos habilitados (confirmado en el README)

- **Autenticación JWT**: inicio/refresco/cierre de sesión, captcha de clic, bloqueo de cuenta (5 intentos fallidos → bloqueo de 15 minutos), límite de sesiones concurrentes (≤3 tokens por usuario)
- **RBAC**: árbol de roles/permisos, autorización con granularidad method.path
- **Auditoría de operaciones**: consulta de logs + identificación de 8 orígenes de plataforma
- **Gestión de archivos**: subida / exportación Excel / exportación PDF (enmascarada)
- **i18n**: cambio chino/inglés (Accept-Language / ?lang=)
- Otros: panel (caché Redis), configuración del sistema, health check/metrics/OpenAPI 3.0, protección de seguridad en 18 capas

## Detalle de fallos de pruebas (todos brechas existentes del proyecto, no introducidas por este cambio)

| Grupo de pruebas | Fallo | Causa |
|---|---|---|
| `EnvConfigTest` (5 casos) | 4 failure + 1 error | Las pruebas exigen que `.env`/`.env.example` existan y que getenv tenga valores para `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` etc.; el repositorio no incluye un env de ejemplo |
| `CaptchaTest` (4 casos) | 3 error + 1 failure (además 1 risky sin aserciones) | El captcha de clic depende del almacenamiento Redis, no disponible localmente |
| `BackendEnhancementTest` (2 casos) | 2 failure | Afirma que la fuente de datos `user` contiene searchable y el middleware cors/rate_limit — desviación entre configuración y aserciones de prueba |

Pasos locales para volver a todo verde: crear `.env` según las claves de configuración en `config/` (añadir las claves de las que depende EnvConfigTest), proporcionar MySQL + Redis (para CaptchaTest) y que el responsable resuelva las dos desviaciones de configuración de BackendEnhancementTest.

## Estado de preparación de gRPC (T3)

- Paquetes de Composer instalados: `grpc/grpc 1.82.0`, `google/protobuf 5.35` (`--no-plugins` evita el bug de carga duplicada del plugin security-php)
- Stubs de PHP generados: `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` etc., incluyendo los tres conjuntos de contratos: infra/user)
- **Extensión grpc de PHP no instalada**: pecl sin permiso de escritura y sudo requiere contraseña; se necesita `sudo pecl install grpc` antes de ejecutar el cliente gRPC

## Puntos de entrada de transformación (ocho elementos nuevos del §3.4 del documento de diseño)

1. Banco de trabajo de moderación de contenido: revisión bilingüe lado a lado de publicaciones/comentarios/imágenes, plantillas multilingües de motivos de rechazo, sanciones a usuarios
2. Cola de procesamiento de denuncias
3. Mesa de solicitudes GDPR (tickets de exportación/eliminación)
4. Integración del panel de datos con bee_tsdb
5. Gestión de entradas i18n (CRUD compartido entre los cuatro clientes)
6. Gestión de la biblioteca de regalos (SKU, precio, efectos, nombres multilingües)
7. Configuración de proveedores de directos (estrategia de enrutamiento, orden de conmutación)
8. Revisión de solicitudes de retiro

**Puntos de integración gRPC**: los stubs de contratos del lado admin están en `admin/generated/` (reutilización de `Social/Admin/V1` para sondeos + futuros mensajes de negocio); las llamadas a service van por `Social\User\V1\UserServiceClient` y a infrastructure por `Social\Infra\V1\InfraServiceClient`; la cadena de sondeo con service/infrastructure se describe en `service/README.grpcs.md` y en los sondeos de integración T10.
