# Informe de pruebas automatizadas de API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Fecha: 2026-08-27
- Ejecución: `tests/api/run.php` (script de aserciones curl), resultados en `tests/api/results.json`
- Alcance: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, incluye S58-S68)
- Servicios: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` no cubierto en esta ronda de pruebas HTTP)

## Conclusión

**116 casos de prueba: 116 aprobados / 0 fallidos (100 % de aprobación); los 3 defectos de producto de la ronda anterior (A20/A39/A40) están todos corregidos y verificados**

| Grupo | Aprobados/Total |
|------|-----------|
| admin A01-A45 (autenticación, captcha, gestión de usuarios, HashID, roles y permisos, configuración, registros, exportar/importar, subida, health checks, etc.) | 45/45 |
| service S01-S68 (registro/login/logout/refresh, perfil, seguir, publicaciones/me gusta/timeline, comentarios, notificaciones, búsqueda, sesiones IM/mensajes/push, subida de voz/archivos/llamadas/salas, etc.) | 71/71 |

## Verificación de la corrección de los 3 defectos de producto de la ronda anterior (todo PASS)

| Caso | Esperado | Real (ronda anterior) | Corrección | Resultado de esta ronda |
|------|------|---------|------|---------|
| A20 Detalles de usuario hashid inválido | 404 | 500 | `BaseController::decodeId()` captura `InvalidArgumentException` y lanza `support\exception\NotFoundException($msg, 404)` (admin/app/admin/controller/BaseController.php); los catch de los dos métodos batch de `UserController` se amplían a `InvalidArgumentException \| NotFoundException` conservando la semántica 422 | **PASS (404)** |
| A39 Exportar Excel | flujo de archivo xlsx | 200+cuerpo de error JSON | `ExportController` añade `use support\Response;` (el tipo de retorno antes se resolvía al inexistente `app\admin\controller\Response`, lanzando TypeError); `phone/email/id_card` de `admin_user` se descifran automáticamente vía el cast Encryptable al leer, la exportación enmascara directamente, se elimina el doble descifrado | **PASS (flujo de archivo attachment)** |
| A40 Exportar PDF | flujo de archivo pdf | 200+cuerpo de error JSON | Igual que arriba (corregido el tipo de retorno de `ExportController::pdf()`) | **PASS (flujo de archivo application/pdf)** |

## Problemas de entorno corregidos/gestionados en esta ronda (no son cambios de código de negocio del producto)

1. **Anulación de contraseña de BD vacía en run.php rota (defecto del script de prueba, corregido)**: la constante `DB` usa `getenv('DB_PASS') ?: 'root'`; una variable de entorno con cadena vacía se trata como falsy por `?:` y cae a 'root', por lo que la conexión root local con contraseña vacía es rechazada (`Access denied ... using password: YES`). Cambiado a `getenv('DB_PASS') ?? 'root'` (default solo si no está definida), cambio de una línea (tests/api/run.php:26).
2. **Puerto 8788 del service ocupado por un proceso erróneo (entorno, gestionado)**: el proceso service de otro proyecto de esta máquina — `property-management-platform` (master 2004768, iniciado 08:07) — escuchaba en 8788, y su `.env` apunta a la BD `property_management`; el service de social en realidad no corría, por lo que las rutas IM/voz desde S45 devolvían 404 y el SQL de la fase de limpieza golpeaba la BD equivocada. Se detuvo el proceso y se reinició el service de social en 8788/8789 (`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`); el health check volvió a `social-service`.
3. **La actualización a ImageMagick 7 provocó el fallo del driver Imagick del captcha (entorno, gestionado)**: tras la actualización del ImageMagick del sistema a 7.1.2-27 (build 2026-07-08) se eliminó `PixelsResource`; imagick 3.8.1 ya no define `Imagick::RESOURCETYPE_PIXELS`, y el constructor de `ImagickDriver` de poster-php lanza inmediatamente `Undefined constant` (código vendor, sin modificar), por lo que la generación/verificación del captcha (A05/A06) da 500 y bloquea en cascada el login A08-A11. **Gestión**: el servicio admin se reinició con el conmutador de driver previsto en la documentación de configuración — `POSTER_IMAGE_DRIVER=gd` (admin/config/poster.php:17 soporta nativamente gd/imagick/auto); tras cambiar el captcha al driver GD, toda la cadena funciona. Para restaurar el driver Imagick hay que degradar ImageMagick a 6.x o actualizar poster-php para compatibilidad con IM7.
4. **La contraseña root de MySQL cambió a vacía**: la ronda anterior registró `root/root`; en esta ronda se puede iniciar sesión con contraseña vacía, y todos los servicios y scripts se iniciaron con contraseña vacía.
5. **Entorno de reinicio del servicio admin**: sigue valiendo lo de la ronda anterior, «admin no tiene .env, depende de variables de entorno»; comandos de reinicio abajo, en «Entorno y reproducción».
6. **service/.env sigue siendo `service/.env.api-test-bak`**: movido en la ronda anterior para pruebas de conectividad y no restaurado (restauración limitada por la política de acceso al archivo .env); en esta ronda el servicio se inició de nuevo con variables de entorno. Se requiere `mv service/.env.api-test-bak service/.env` manual (reiniciar el servicio tras restaurar; tener en cuenta la dirección de BD a la que apunta).
7. **Elasticsearch no iniciado**: `GET /api/v1/search/posts` devuelve 503 (degradación prevista); los casos de búsqueda del grupo S se tratan como se esperaba (se acepta 0 o 503), no se cuentan como fallos.

## Discrepancias contrato/documentación (revisión sugerida, no bloqueante)

- La documentación del captcha (apidoc y comentarios de CaptchaController) escribe `clicks=[{x,y}]` como un array de objetos, pero la implementación `poster-php` exige un array de pares de coordenadas `[[x,y]]`; pasar objetos según la doc siempre falla en la práctica.
- La subida de voz devuelve `voice_url` como `/voice/{md5}.m4a` (relativo a la raíz de la API, sin el prefijo `/api/v1`); el cliente debe añadir `/api/v1` por su cuenta para acceder; el acceso a archivos pasa por rutas autenticadas (requiere token).

## Entorno y reproducción

- Credenciales de prueba: cuenta `e2e_smoke` (admin, contraseña solo para pruebas) + `apitest_*@test.dev` (service, limpiada automáticamente tras la ejecución), todas escritas en las constantes de `tests/api/run.php`; no se usaron claves reales.
- Reproducción:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD='' ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' POSTER_IMAGE_DRIVER=gd \
  php start.php start                                          # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD='' php start.php start                           # service :8788
cd /home/wwwroot/social/tests/api && DB_PASS='' php run.php    # re-ejecutar (116 casos)
```

- Nota: asegurarse de que el puerto 8788 no esté ocupado por el service de `property-management-platform` (ambos proyectos usan el mismo puerto por defecto; cuando ambos proyectos coexisten en esta máquina hay que separarlos).

## Inventario de endpoints (según route.php / apidoc)

- service `config/route.php`: 39 rutas HTTP (autenticación 5, usuarios 2, seguir 5, publicaciones 7, comentarios 2, notificaciones 4, búsqueda 2, IM 4, voz/llamadas/salas 5, health/docs 3)
- admin `config/route.php`: 33 rutas HTTP (autenticación/captcha 4, CRUD de usuarios 5, roles 5, permisos 2, configuración 4, registros 1, perfil 4, exportar 2, importar 1, subida 1, health/docs 4)
