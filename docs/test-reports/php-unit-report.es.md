# Informe de pruebas unitarias de PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Fecha: 2026-08-27
- Ejecución: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Alcance: admin/ (panel de administración webman) + service/ (servicio principal webman)

## Resumen general

| Proyecto | Casos de prueba | Aserciones | Resultado |
|------|------|------|------|
| service | 136 | 348 | ✅ Todo aprobado (OK) |
| admin | 60 | 136 | ⚠️ 49 aprobados / 4 errores / 7 fallidos |

## service (todo en verde)

- Nuevos archivos de prueba (este lote): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest, etc.; fusionados con los 24 archivos existentes, 136 casos en total, todos aprobados
- Módulos cubiertos: autenticación/middleware/JWT, usuarios, publicaciones, comentarios, seguimientos, notificaciones, sincronización de búsqueda, IM, salas, llamadas (CallCenter/CallState), voz, relaciones de modelos, manejo de acciones (WS)

### Corrección: bloqueo aleatorio de la suite de pruebas (importante)

- Síntoma: en ejecuciones completas el proceso se congela aleatoriamente; ejecutar un solo archivo/un subconjunto pasa
- Causa raíz: `new Worker()` en `ActionHandlerTest::setUp` registra la instancia en el **registro estático** `Worker::$workers`; después, cualquier `CallCenter::start` ve «existe un Worker» y llama a `Timer::add` → `pcntl_alarm(1)` instala un temporizador SIGALRM, y el proceso se bloquea al salir
- Corrección: setUp toma una instantánea del registro, tearDown lo restaura (`ReflectionProperty` devuelve `workers`/`pidMap`)
- Ubicación: `service/tests/ActionHandlerTest.php`

## admin (49/60; los fallos son todos pruebas preexistentes y son problemas de entorno/configuración)

| Caso de prueba | Motivo del fallo | Categoría |
|------|----------|------|
| EnvConfigTest (4 fallidos + 1 error) | `admin/.env` no existe; las aserciones getenv/dotenv fallan | Entorno de prueba sin .env |
| CaptchaTest (3 errores + 1 fallido + 1 risky) | El captcha depende de un servicio/Redis en ejecución; el entorno de pruebas unitarias devuelve null | Dependencia del entorno |
| BackendEnhancementTest (2 fallidos) | Afirma la existencia de `app/middleware/Cors` y de searchable en admin_user — la configuración actual no coincide con las aserciones | Aserciones de configuración obsoletas |

Nota: admin/tests son todos archivos históricos preexistentes; en este lote no se añadieron pruebas unitarias nuevas de admin (el foco estuvo en service).

## No cubierto / por añadir

- Los módulos de admin (model/middleware/view) carecen de pruebas unitarias
- Las rutas de service que dependen de servicios externos (ES/gRPC) solo recibieron validación unitaria con stubs; se recomienda cubrir el nivel de integración con pruebas de API
