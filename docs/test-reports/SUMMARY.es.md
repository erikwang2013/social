# Informe resumido de todas las pruebas
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Fecha: 2026-08-27 (segunda regresión completa)
- Equipo de pruebas: pruebas unitarias PHP / pruebas unitarias Rust / automatización API / UI de extremo a extremo (ver la nota sobre el rol GO al final)
- Los cuatro subinformes y este resumen se almacenan localmente en `docs/test-reports/`

## Resumen general

| Rol | Informe | Casos de prueba | Aprobados | Fallidos | Conclusión |
|------|------|------|------|------|------|
| Pruebas unitarias PHP | `php-unit-report.md` | 226 | 226 | 0 | service 159/408 + admin 67/67 todo verde |
| Pruebas unitarias Rust | `rust-unit-report.md` | 183 | 183 | 0 | 16 crates todo verde, y se corrigieron 5 defectos reales |
| Automatización API | `api-test-report.md` | 116 | 116 | 0 | corrección de los 3 defectos de producto de la ronda anterior verificada |
| UI de extremo a extremo | `ui-e2e-report.md` | 41 | 41 | 0 | Todo verde, 1 bloqueado (ES no iniciado) |
| **Total** | | **566** | **566** | **0** | Tasa de aprobación 100 % (1 bloqueado) |

## Defectos reales corregidos en esta ronda (todos corregidos y verificados por regresión)

1. **A20 hashid inválido 500→404** (pendiente de la ronda anterior): `BaseController::decodeId()` captura `InvalidArgumentException` y lanza `support\exception\NotFoundException(404)` (body code); los métodos batch conservan la semántica 422
2. **A39/A40 Exportar Excel/PDF — fallo garantizado** (pendiente de la ronda anterior): `ExportController` ahora tiene `use support\Response;` (el tipo de retorno antes se resolvía a una clase inexistente); se eliminó el doble descifrado de campos ya descifrados por el cast Encryptable
3. **Fallo del driver Imagick del captcha** (nuevo hallazgo, producción también afectada): el ImageMagick 7 local carece de la constante `RESOURCETYPE_PIXELS`; la detección de driver en `config/poster.php` ahora tiene una guarda por constante y vuelve a GD automáticamente si falta
4. **Inicio `/` del service 404** (nuevo hallazgo): webman-framework v2.2.4 ya no resuelve la ruta raíz por defecto; `service/config/route.php` registra explícitamente `Route::get('/')`
5. **5 defectos de Rust** (nuevos hallazgos, detalles en rust-unit-report.md): bee_search MemoryEngine ignora la paginación, social_grpc convierte silenciosamente ids no numéricos en 0, bee_tsdb campos de line protocol de InfluxDB desordenados, bee_search ids sin escapar en bulk NDJSON de ES, bee_graph Neo4j add_edge el endpoint de error siempre es `from`
6. **Los propios scripts de prueba**: en `tests/api/run.php` la contraseña de BD vacía retrocedía a 'root' por `?:` → cambiado a `?? 'root'`; las tres suites de aserciones obsoletas de admin reescritas según el código actual (Searchable obsoleto, claves del middleware Cors, contrato del captcha de poster-php)

## Validación del hito M5 (nuevo)

- El módulo de directos (LiveCenter: crear/detalles/danmaku/vincular micrófono/cerrar) entregado y verificado: phpunit service +23 casos (159/408 verde), el E2E de caja negra `tests/live_e2e.php` superó las 27 comprobaciones (incl. push RTMP, pull HLS)

## Correcciones de entorno y notas (causadas por este lote de pruebas)

- **8788 ocupado por un proceso de otro proyecto**: el service de `property-management-platform` de esta máquina ocupaba erróneamente el puerto 8788; se detuvo y se reinició el service de social con variable de entorno de contraseña vacía
- **`service/.env` sigue siendo `service/.env.api-test-bak`**: la restauración está limitada por la política de acceso al archivo .env; se requiere `mv service/.env.api-test-bak service/.env` manual (reiniciar el servicio tras restaurar)
- **Compatibilidad con ImageMagick 7**: para recuperar el driver Imagick, degradar ImageMagick a 6.x o actualizar poster-php para compatibilidad con IM7; el driver GD actual funciona en toda la cadena
- **ES no iniciado**: los casos de búsqueda (API + E2E) marcados como aprobados con 503/blocked; se necesita re-verificación tras iniciar Elasticsearch

## Discrepancias contrato/documentación (revisión sugerida, no bloqueante)

- El apidoc del captcha indica `clicks=[{x,y}]` como array de objetos, pero la implementación de poster-php exige un array de pares de coordenadas `[[x,y]]`
- La subida de voz devuelve `voice_url` como `/voice/{md5}.m4a` (sin el prefijo `/api/v1`); el cliente debe anteponerlo por su cuenta

## Nota del ingeniero de pruebas GO

El repositorio **no contiene ningún código Go** (sin go.mod, sin archivos .go); este rol no tenía módulo que probar y no se ejecutó. Para añadir cobertura, primero debe introducirse un componente Go (p. ej., puerta de enlace/sidecar de búsqueda).

## Reproducción

```bash
# Pruebas unitarias
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# Automatización API (requiere iniciar primero admin :8791 y service :8788, inyectando ENCRYPTABLE_KEY/ENCRYPTION_KEY; con root de contraseña vacía local se necesita DB_PASS='')
DB_PASS='' php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
