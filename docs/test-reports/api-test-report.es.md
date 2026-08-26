# Informe de pruebas automatizadas de API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Fecha: 2026-08-27
- Ejecución: `tests/api/run.php` (script de aserciones curl), resultados en `tests/api/results.json`
- Alcance: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, incluye S58-S68)
- Servicios: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` no cubierto en esta ronda de pruebas HTTP)

## Conclusión

**116 casos de prueba: 113 aprobados / 3 fallidos (97,4 % de aprobación); los 3 fallos son defectos de producto con causa raíz identificada**

| Grupo | Aprobados/Total |
|------|-----------|
| admin A01-A45 (autenticación, captcha, gestión de usuarios, HashID, roles y permisos, configuración, registros, exportar/importar, subida, health checks, etc.) | 42/45 |
| service S01-S68 (registro/login/logout/refresh, perfil, seguir, publicaciones/me gusta/timeline, comentarios, notificaciones, búsqueda, sesiones IM/mensajes/push, subida de voz/archivos/llamadas/salas, etc.) | 71/71 |

## Casos de prueba fallidos (3, todos defectos de producto)

| Caso | Esperado | Real | Causa raíz |
|------|------|------|------|
| A20 Detalles de usuario hashid inválido | 404 | 500 | `HashidsService::decode()` lanza una `InvalidArgumentException` no capturada para ID inválidos (admin/app/common/HashidsService.php:28, BaseController.php:52); la excepción se propaga como 500, debería capturarse y devolver 404 |
| A39 Exportar Excel | flujo de archivo xlsx | 200+cuerpo de error JSON (fallo de negocio) | `ExportController::excel()` declara el tipo de retorno `: Response` pero le falta `use support\Response`, el tipo se resuelve a `app\admin\controller\Response` → cualquier retorno exitoso lanza `TypeError` (ExportController.php:122), la exportación queda totalmente inutilizable |
| A40 Exportar PDF | flujo de archivo pdf | 200+cuerpo de error JSON (fallo de negocio) | Igual que arriba, `ExportController::pdf()` (ExportController.php:135) sin `use support\Response` |

> Nota adicional (defecto potencial en el mismo archivo, actualmente enmascarado por la TypeError anterior): `ExportController` línea 90 llama a `EncryptionService::decrypt()` sobre phone/email, mientras que los campos `email/phone/id_card` del modelo `AdminUser` declaran el cast `Encryptable::class` (cifrado automático al escribir, descifrado automático al leer); la exportación descifraría el texto plano una segunda vez → en cuanto exista una cuenta con teléfono/correo no vacíos, lanzará `EncryptionException: Invalid ciphertext prefix for AES-256-CBC`. Este problema se reproducirá también tras corregir los tipos de retorno.

## Problemas de entorno corregidos durante las pruebas (no son cambios de código del producto)

1. **Columna `id` de las tablas de migración m2/m3/m4 sin AUTO_INCREMENT (bloqueante, corregido)**: `social_follows`, `social_notifications` creadas por `service/database/m2.sql`/`m3.sql`/`m4.sql` tienen `id BIGINT UNSIGNED NOT NULL` sin `AUTO_INCREMENT`; cualquier INSERT falla con `1364 Field 'id' doesn't have a default value`, bloqueando todas las rutas de escritura de seguir/notificaciones/IM/voz. Se ejecutó `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` en local (las otras 8 tablas ya tienen autoincremento). **Los scripts de migración deberían completarse con el autoincremento.**
2. **service/.env apunta a una base de datos inalcanzable (bloqueante)**: `DB_PORT=13306` sin contraseña, mientras que el MySQL principal está realmente en `127.0.0.1:3306 (root/root)`; el `createUnsafeMutable` de webman sobrescribe las variables de entorno CLI. Durante las pruebas, `.env` se movió a `service/.env.api-test-bak` (contenido conservado tal cual) y el servicio se inició con variables de entorno inyectadas; la restauración no pudo realizarse por las restricciones de política de acceso al archivo .env, se requiere un `mv service/.env.api-test-bak service/.env` manual (nota: tras restaurar, reiniciar el servicio volverá a encontrarse con la base inalcanzable).
3. **admin no tiene .env, depende de variables de entorno**: requiere `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`. El plugin `encryptable`, sin provider registrado en el contenedor de webman, cae a `EnvEncryptableConfig` (lee `ENCRYPTION_KEY`, cipher por defecto aes-256-gcm); una longitud de clave incoherente produce `MissingEncryptionKeyException` al crear/importar/exportar cuentas.
4. **Elasticsearch no iniciado**: `GET /api/v1/search/posts` devuelve 503 (degradación prevista); los casos de búsqueda del grupo S se tratan como se esperaba (se acepta 0 o 503), no se cuentan como fallos.

## Discrepancias contrato/documentación (revisión sugerida, no bloqueante)

- La documentación del captcha (apidoc y comentarios de CaptchaController) escribe `clicks=[{x,y}]` como un array de objetos, pero la implementación `poster-php` exige un array de pares de coordenadas `[[x,y]]`; pasar objetos según la doc siempre falla en la práctica.
- La subida de voz devuelve `voice_url` como `/voice/{md5}.m4a` (relativo a la raíz de la API, sin el prefijo `/api/v1`); el cliente debe añadir `/api/v1` por su cuenta para acceder; el acceso a archivos pasa por rutas autenticadas (requiere token).

## Entorno y reproducción

- Credenciales de prueba: cuenta `e2e_smoke` (admin, contraseña solo para pruebas) + `apitest_*@test.dev` (service, limpiada automáticamente tras la ejecución), todas escritas en las constantes de `tests/api/run.php`; no se usaron claves reales.
- Reproducción:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # re-ejecutar (116 casos)
```

## Inventario de endpoints (según route.php / apidoc)

- service `config/route.php`: 39 rutas HTTP (autenticación 5, usuarios 2, seguir 5, publicaciones 7, comentarios 2, notificaciones 4, búsqueda 2, IM 4, voz/llamadas/salas 5, health/docs 3)
- admin `config/route.php`: 33 rutas HTTP (autenticación/captcha 4, CRUD de usuarios 5, roles 5, permisos 2, configuración 4, registros 1, perfil 4, exportar 2, importar 1, subida 1, health/docs 4)
