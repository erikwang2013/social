# Diseño del hito de voz M4 (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- Fecha: 2026-08-17
- Estado: confirmado
- Alcance: mensajes de voz + llamadas 1v1 + salas de chat de voz (los tres componentes); el mecanismo de versionado de API (por header) se implementa primero
- Diseño ascendente: `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 Arquitectura de voz)

## 1. Objetivo

Entregar el trío de voz M4: mensajes de voz (extensión del tipo de mensaje IM + transcodificación), llamadas 1v1 (máquina de estados de señalización WS + plano de medios P2P), salas de chat de voz (máquina de estados de sala + SFU mediasoup). Implementar también el mecanismo de versionado de API por header.

## 2. Versionado de API (por header, tarea 0 primero)

**Estado actual**: todos los endpoints están registrados en el grupo de prefijo `/api/v1` (`config/route.php`), 10 controladores, con `AuthMiddleware` montado en el grupo.

**Mecanismo**: el cliente envía una ruta sin versión `/api/xxx` + `Header: X-Api-Version: v1`; el middleware global `ApiVersionMiddleware` (`config/middleware.php`) reescribe la ruta y la pasa al router.

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- Versión no válida (no es `v1|v2|...`) → 400 + `lang_key`
- Migración cero: los controladores/rutas/rutas E2E existentes no cambian
- V2 futura: registrar el grupo `/api/v2` → `app\api\v2\*`, sin cambios en el middleware
- Los nuevos endpoints M4 se registran bajo `/api/v1/voice/*` (se conserva el prefijo de versión; el cliente usa ruta sin versión + header)

## 3. Modelo de datos (m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**Tablas nuevas**:

```sql
CREATE TABLE `social_call_records` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  caller_id BIGINT UNSIGNED NOT NULL,
  callee_id BIGINT UNSIGNED NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1呼叫中 2接通 3未接 4取消 5结束',
  started_at TIMESTAMP NULL COMMENT '接通时间',
  ended_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), KEY idx_callee(callee_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='1v1通话记录';

CREATE TABLE `social_voice_rooms` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  owner_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1开 0关',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), KEY idx_status(status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房';

CREATE TABLE `social_voice_room_members` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  room_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role TINYINT NOT NULL DEFAULT 0 COMMENT '0听众 1麦位',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), UNIQUE KEY uk_room_uid(room_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房成员';
```

## 4. Mensajes de voz

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- La REST del historial incluye automáticamente `voice_url/voice_duration` (cast del modelo)
- La transcodificación se completa sincrónicamente dentro de la solicitud (segundos por archivo); pasar a cola cuando el volumen crezca (nota ponytail)
- Requisito de entorno: el host del servicio necesita el binario FFmpeg (verificar durante la implementación; instalar si falta)

## 5. Señalización de llamada 1v1

**Frames WS** (reutilizando la pasarela existente, prefijo `call_*`):

```
call_invite   {to_user_id}            主叫发起
call_accept   {call_id}               被叫接听
call_reject   {call_id}               被叫拒绝
call_cancel   {call_id}               主叫取消
call_timeout  {call_id}               30s 无人接听（服务端推双方）
call_offer    {call_id, sdp}          主叫 offer（经服务端转发被叫）
call_answer   {call_id, sdp}          被叫 answer 回传
call_ice      {call_id, candidate}    ICE 候选双向转发
call_hangup   {call_id}               任一方挂断 → 推双方
call_failed   {call_id}               P2P 15s 未连通 → 推双方
```

**Máquina de estados** (una sola clave de Redis):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- Mutex de inactividad: `SETNX im:callbusy:{uid}` (TTL 5 min), ante conflicto devolver el frame de error `already_in_call`
- Sin respuesta en 30 s → no contestada, enviar `call_timeout` a ambos lados, persistir
- accept → `call_records` status=2 + started_at
- hangup/fin → status=5 + ended_at, liberar la clave busy
- Desconexión WS de cualquiera de los lados → enviar `call_hangup` al otro y terminar (ponytail: sin recuperación por reconexión)
- Plano de medios en conexión P2P directa (offer/answer/ICE solo se retransmiten, los flujos de medios nunca pasan por el servidor); respaldo TURN (coturn se entrega con las salas de chat de voz)
- ICE P2P no conectado en 15 s → `call_failed` + fin (la v1 no cambia automáticamente a SFU, nota ponytail); persistir status=5

**Historial**: `GET /api/v1/voice/calls?page=` respuesta paginada (caller/callee/status/duración).

## 6. Salas de chat de voz

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**Frames WS** (prefijo `room_*`):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- Plazas de micrófono limitadas a 8 (1 propietario + 7 plazas, constante, configurable más tarde en admin); devolver frame de error cuando esté lleno
- join/leave/cambios de micrófono se persisten en la tabla `voice_room_members` + estado de sala en Redis; enviar los cambios a todos los miembros en línea de la sala
- El propietario se va → cerrar la sala (enviar `room_closed` a todos)

**Ruta de señalización SFU** (según el documento de diseño: "toda la señalización reutiliza la pasarela WS del service"):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service retransmite los frames → SFU (POST HTTP traduciendo la API de mediasoup: rtpCapabilities, creación/connect de WebRtcTransport, produce/consume); respuesta SFU → service → push WS al cliente
- Un Router de mediasoup por sala; liberación automática tras 5 min de inactividad (nota ponytail)

**Despliegue**: `media/sfu` proceso Node desnudo (desarrollo) + `docker-compose.yml` reservado para producción; el contenedor `coturn` se entrega en el mismo bloque.

## 7. Estrategia de pruebas

| Nivel | Cobertura |
|---|---|
| Unitarias | ApiVersionMiddleware (por defecto/explícito/no válido/ruta antigua), máquina de estados de llamada (invite/accept/reject/cancel/timeout/hangup/mutex), máquina de estados de sala de voz (join/plazas de mic/cierre/lleno/kick), validación de subida de voz (tipo/tamaño/duración) |
| E2E caja negra | Mensajes de voz: subida → envío de frame → recepción de frame → historial con duración; 1v1: invite→accept→verificar retransmisión offer/answer/ICE→hangup→call_records persistido; salas de voz: join→up_mic→down_mic→leave→cierre de sala |
| Build | Build de Android probado de verdad; los commits de iOS/HarmonyOS indican que Linux no puede compilar (patrón establecido en M3) |
| Manual en dispositivo | Audio/vídeo SFU reales, calidad de llamada P2P (la caja negra no puede automatizar WebRTC) |

## 8. Orden de implementación (pipeline en orden inverso de dependencias)

0. Middleware de versionado de API (primero, entregable independiente)
1. Mensajes de voz (subida + transcodificación + almacenamiento + modelo + tipo de mensaje)
2. Máquina de estados de señalización de llamada 1v1 (+ call_records + REST de historial)
3. Salas de chat de voz (REST + máquina de estados de sala + plazas de micrófono)
4. media/sfu (mediasoup + docker-compose) + coturn
5. Clientes de las tres plataformas (grabación/reproducción de voz / UI de llamada / UI de sala de voz)
6. E2E + regresión completa
