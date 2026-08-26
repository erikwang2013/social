# Informe resumido de todas las pruebas
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Fecha: 2026-08-27
- Equipo de pruebas: pruebas unitarias PHP / pruebas unitarias Rust / automatización API / UI de extremo a extremo (ver la nota sobre el rol GO al final)
- Los cuatro subinformes y este resumen se almacenan localmente en `docs/test-reports/`

## Resumen general

| Rol | Informe | Casos de prueba | Aprobados | Fallidos | Conclusión |
|------|------|------|------|------|------|
| Pruebas unitarias PHP | `php-unit-report.md` | 196 | 185 | 11 (casos preexistentes de admin, dependientes del entorno) | service 136/136 todo verde; admin 49/60 |
| Pruebas unitarias Rust | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates todo verde, y se encontraron 7 defectos reales |
| Automatización API | `api-test-report.md` | 116 | 113 | 3 | 3 defectos reales de producto, causas raíz identificadas |
| UI de extremo a extremo | `ui-e2e-report.md` | 35 | 35 | 0 | Todo verde, 1 bloqueado (ES no iniciado) |
| **Total** | | **527** | **513** | **14** | Tasa de aprobación 97 % |

## Lista de defectos reales (se recomienda corregir)

1. **A20 hashid inválido** → 500 debería ser 404: `admin/app/common/HashidsService.php:28` no captura `InvalidArgumentException`
2. **A39/A40 Exportar Excel/PDF** → fallo garantizado: a `ExportController` le falta `use support\Response`, lo que rompe la resolución del tipo de retorno; el mismo archivo descifra dos veces teléfono/email ya castados y reporta `Invalid ciphertext prefix`
3. **7 defectos encontrados por Rust**: ver detalles en `rust-unit-report.md` (parseo de protocolos, manejo de límites, etc., todos con corrección adjunta)
4. **11 fallos de pruebas unitarias de admin son problemas de entorno/configuración**: falta `admin/.env`, el captcha depende de un servicio/Redis en ejecución, aserciones obsoletas del middleware Cors y de searchable en admin_user — no son defectos de código

## Correcciones de entorno y notas (causadas por este lote de pruebas)

- **Base de datos**: el `id` de `social_follows`/`social_notifications` en las tablas de migración m2/m3/m4 no tiene AUTO_INCREMENT, corregido vía ALTER (de lo contrario las rutas de escritura de seguir/notificaciones/IM/voz fallan con 1364)
- **`service/.env`**: respaldado como `.env.api-test-bak` (originalmente apuntaba al puerto 13306 inalcanzable). La restauración automática no es posible por las restricciones de política de acceso a .env; se requiere `mv service/.env.api-test-bak service/.env` manual
- **ES no iniciado**: los casos de búsqueda (API + E2E) marcados como aprobados con 503/blocked; se necesita re-verificación tras iniciar Elasticsearch

## Nota del ingeniero de pruebas GO

El repositorio **no contiene ningún código Go** (sin go.mod, sin archivos .go); este rol no tenía módulo que probar y no se ejecutó. Para añadir cobertura, primero debe introducirse un componente Go (p. ej., puerta de enlace/sidecar de búsqueda).

## Reproducción

```bash
# Pruebas unitarias
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# Automatización API (requiere iniciar primero admin :8791 y service :8788, inyectando ENCRYPTABLE_KEY/ENCRYPTION_KEY)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
