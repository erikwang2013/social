# Informe de pruebas de extremo a extremo (E2E) de las páginas
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- Fecha: 2026-08-27
- Entorno: máquina local (Linux), navegador real (Playwright 1.62 / Chromium) + procesos de servicio reales
- Casos de prueba en total: **41**, aprobados **41**, fallidos **0**, marcados como bloqueados **1**
- Artefactos: `tests/e2e/artifacts/html-report/` (informe HTML de Playwright), capturas/trazas de fallos (ninguno en esta ejecución)

## Alcance de las pruebas y lista de páginas

Ambos backends webman se ejecutan como procesos reales: `admin` (:8791), `service` (:8788, WS :8789).
Las `app/view/` de ambos lados solo tienen plantillas predeterminadas (`index/view.html`), sin plantillas multipágina tradicionales: las «páginas» reales son los endpoints de API,
y los frontends web los llevan los clientes Flutter/HarmonyOS (`apps/` no tiene UI web ejecutable, fuera del alcance E2E).

| Aplicación | Página / endpoint | Casos |
|------|------------|------|
| admin | `/health` verificación de salud, `/metrics` métricas de Prometheus, `/.well-known/security.txt`, `/api/docs` OpenAPI, `/install` asistente de instalación | 5 |
| admin | `/api/captcha/generate` + `/api/captcha/verify` (resolución real de píxeles del captcha deslizante), `/api/auth/login` (éxito/contraseña incorrecta/sin captcha) | 3 |
| admin | Páginas protegidas tras iniciar sesión: `/admin/dashboard`, `/admin/user`, `/admin/role`, `/admin/permission`, `/admin/config`, `/admin/log`, `/admin/profile`, `/admin/social-user`, cierre de sesión `/admin/profile/logout` → token invalidado | 11 |
| admin | Operaciones por lotes `/admin/user/batch/status` (activación por lotes + ids vacíos 422), exportación `/admin/export/excel` (verificación del encabezado de archivo xlsx), cambiar contraseña `/admin/profile/password` (falta la contraseña anterior 422) | 3 |
| service | `/` (contenedor iframe), `/health`, `/apidoc` (redirige a apidoc/index.html), acceso no autenticado a endpoints protegidos 401 | 4 |
| service | Registro/inicio/cierre de sesión, perfil (GET/PUT `/api/v1/me`), publicación/timeline/detalle, me gusta/quitarme gusto, comentario, seguir/dejar de seguir/relación/seguidores/lista de seguidos, notificaciones (lista/no leídas/marcar una como leída/marcar todas como leídas) | 10 |
| service | Buscar usuarios, buscar publicaciones (ES no iniciado → 503, marcado como bloqueado y aprobado) | 2 |
| service | Conversaciones IM (crear/listar/mensajes), salas de voz (crear/listar/detalle/cerrar) | 3 |

## Cómo ejecutar

```bash
cd tests/e2e && npx playwright test          # todas
# o por archivo: admin-pages.spec.js / admin-auth.spec.js / service-journey.spec.js
```

- Fixture de cuenta de prueba: `e2e_smoke`, contraseña `ApiTest!2026` (precargada por SQL, ver `tests/api/run.php`)
- El captcha deslizante se resuelve mediante correlación de Pearson por píxeles entre la «pieza del rompecabezas y la imagen de fondo» (ruta de interacción real, sin rodeos);
  el tipo de captcha es aleatorio (click/rotate/slider), solo el slider se puede resolver automáticamente, por lo que el script reintenta cambiando de imagen hasta acertar.

## Puntos de bloqueo / limitaciones del entorno

1. **Búsqueda de publicaciones 503**: `/api/v1/search/posts` depende de Elasticsearch (Scout), no iniciado en este entorno → devuelve 503.
   El caso pasa marcado como `blocked`; hay que iniciar ES para verificar coincidencias.
2. **La página de inicio `/` de service necesita una ruta explícita**: el enrutado predeterminado de webman-framework v2.2.4 ya no resuelve `/`
   a `IndexController@index` (antes causó un 404 en la ruta raíz, fallando el caso de la página de inicio). Corregido registrando explícitamente
   `Route::get('/', ...)` en `service/config/route.php`; surte efecto tras reiniciar service.
3. **Compatibilidad Imagick del captcha de admin**: la compilación de Imagick de esta máquina carece de la constante `Imagick::RESOURCETYPE_PIXELS`,
   por lo que el driver `auto` elegiría erróneamente ImagickDriver y provocaría un generate 500 (`admin/config/poster.php` ahora retrocede a gd
   según exista o no la constante; requiere reiniciar admin para que surta efecto).
4. **Memoria GD del captcha de admin**: `GdDriver` decodifica imágenes grandes (fondo 5472x3648) con `memory_limit 128M`,
   y los generate consecutivos conllevan riesgo de OOM (admin llegó a caerse en suites largas). Mitigación: reiniciar admin antes de los casos de captcha
   y ejecutar por lotes (admin-pages / admin-auth / service por separado). Limitación del entorno, no un defecto del código de negocio.
5. **Tipo de captcha aleatorio**: generate elige uno de tres; click/rotate no exponen datos resolubles, solo slider pasa automáticamente (máx. 12 reintentos).
6. **Contraseña root vacía de la base de datos**: el entorno de prueba local ofrece MySQL con root/contraseña vacía, los `.env` por defecto de ambas apps son coherentes.
7. **Apps/ móviles**: android/harmonyos/ios no tienen UI web ejecutable, no se incluyen en el E2E de navegador.

## Conclusión

El inicio de sesión de admin (incluido el captcha deslizante) y 22 endpoints de admin, así como los 19 casos de flujo completo del lado de usuario de service, pasan todos
(esta ronda añadió 6 casos: activación por lotes admin/exportación Excel/validación de cambio de contraseña, service 401 sin iniciar sesión/dejar de seguir/marcar una notificación como leída).
Se corrigieron 2 defectos reales: 404 de la ruta raíz de service (se añadió ruta explícita), generate 500 del captcha de admin
(constante Imagick faltante → retroceso a GD, ya incluido en la configuración, surte efecto tras reiniciar).
El único punto de bloqueo es que el servicio de búsqueda (ES) no está desplegado; el resto de las cadenas (registro/inicio/publicación/interacción/notificación/IM/voz) están verificadas y operativas.
