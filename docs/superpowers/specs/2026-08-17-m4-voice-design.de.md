# M4-Sprach-Meilenstein-Design (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- Datum: 2026-08-17
- Status: Bestätigt
- Umfang: Sprachnachrichten + 1v1-Anrufe + Voice-Chat-Räume (alle drei Komponenten); API-Versionierungsmechanismus (Header-basiert) wird zuerst umgesetzt
- Übergeordnetes Design: `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 Voice-Architektur)

## 1. Ziel

Das M4-Sprachtrio liefern: Sprachnachrichten (IM-Nachrichtentyp-Erweiterung + Transkodierung), 1v1-Anrufe (WS-Signalisierungs-Zustandsmaschine + P2P-Medienebene), Voice-Chat-Räume (Zustandsmaschine für Räume + mediasoup SFU). Gleichzeitig den Header-basierten API-Versionierungsmechanismus umsetzen.

## 2. API-Versionierung (Header-basiert, Aufgabe 0 zuerst)

**Aktueller Stand**: Alle Endpunkte sind in der Präfixgruppe `/api/v1` registriert (`config/route.php`), 10 Controller, `AuthMiddleware` ist in der Gruppe eingebunden.

**Mechanismus**: Der Client sendet einen versionslosen Pfad `/api/xxx` + `Header: X-Api-Version: v1`; die globale Middleware `ApiVersionMiddleware` (`config/middleware.php`) schreibt den Pfad um und übergibt ihn an den Router.

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- Ungültige Version (nicht `v1|v2|...`) → 400 + `lang_key`
- Null-Migration: bestehende Controller/Routen/E2E-Pfade bleiben unverändert
- Zukünftige v2: Gruppe `/api/v2` registrieren → `app\api\v2\*`, keine Middleware-Änderungen nötig
- Neue M4-Endpunkte werden unter `/api/v1/voice/*` registriert (Versionspräfix bleibt; der Client verwendet versionslosen Pfad + Header)

## 3. Datenmodell (m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**Neue Tabellen**:

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

## 4. Sprachnachrichten

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- Die REST-API für den Nachrichtenverlauf liefert automatisch `voice_url/voice_duration` mit (Model-Cast)
- Transkodierung erfolgt synchron innerhalb der Anfrage (Sekunden pro Datei); bei wachsendem Volumen in eine Queue auslagern (ponytail-Vermerk)
- Umgebungsvoraussetzung: Auf dem Service-Host muss das FFmpeg-Binary vorhanden sein (bei der Implementierung verifizieren; falls fehlend, installieren)

## 5. 1v1-Anruf-Signalisierung

**WS-Frames** (bestehendes Gateway wird wiederverwendet, Präfix `call_*`):

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

**Zustandsmaschine** (ein einzelner Redis-Key):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- Leerlauf-Mutex: `SETNX im:callbusy:{uid}` (TTL 5 Min), bei Konflikt wird der Fehler-Frame `already_in_call` zurückgegeben
- Keine Antwort innerhalb von 30 s → unbeantwortet, `call_timeout` an beide Seiten senden, persistieren
- accept → `call_records` status=2 + started_at
- hangup/Ende → status=5 + ended_at, Busy-Key freigeben
- WS-Abbruch bei einer Seite → `call_hangup` an die andere Seite senden und beenden (ponytail: keine Wiederherstellung per Reconnect)
- Medienebene ist direkte P2P-Verbindung (offer/answer/ICE werden nur weitergeleitet, Medienströme laufen nie durch den Server); TURN-Fallback (coturn wird mit den Voice-Chat-Räumen ausgeliefert)
- P2P-ICE innerhalb von 15 s nicht verbunden → `call_failed` + Ende (v1 wechselt nicht automatisch auf SFU, ponytail-Vermerk); status=5 persistieren

**Verlauf**: `GET /api/v1/voice/calls?page=` paginierte Antwort (caller/callee/status/Dauer).

## 6. Voice-Chat-Räume

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**WS-Frames** (Präfix `room_*`):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- Mikrofonplätze begrenzt auf 8 (1 Besitzer + 7 Mikrofonplätze, Konstante, später in admin konfigurierbar); bei Vollbelegung Fehler-Frame zurückgeben
- join/leave/Mikrofonänderungen werden in die Tabelle `voice_room_members` + Redis-Raumzustand persistiert; Änderungen werden allen Online-Mitgliedern im Raum gepusht
- Besitzer verlässt den Raum → Raum schließen (`room_closed` an alle senden)

**SFU-Signalisierungspfad** (laut Design-Dokument: „Die gesamte Signalisierung nutzt das WS-Gateway des Service wieder"):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service leitet Frames an SFU weiter (HTTP POST mit Übersetzung in die mediasoup-API: rtpCapabilities, WebRtcTransport create/connect, produce/consume); SFU-Antwort → service → WS-Push an den Client
- Ein mediasoup-Router pro Raum; nach 5 Min Leerlauf automatisch freigegeben (ponytail-Vermerk)

**Bereitstellung**: `media/sfu` nackter Node-Prozess (Entwicklung) + `docker-compose.yml` für Produktion reserviert; der `coturn`-Container wird im selben Block ausgeliefert.

## 7. Teststrategie

| Ebene | Abdeckung |
|---|---|
| Unit-Tests | ApiVersionMiddleware (Standard/explizit/ungültig/alter Pfad), Anruf-Zustandsmaschine (invite/accept/reject/cancel/timeout/hangup/Mutex), Voice-Room-Zustandsmaschine (join/Mikrofonplätze/schließen/voll/kick), Validierung des Sprach-Uploads (Typ/Größe/Dauer) |
| Blackbox-E2E | Sprachnachrichten: Upload → Frame senden → Frame empfangen → Verlauf enthält Dauer; 1v1: invite→accept→Weiterleitung von offer/answer/ICE prüfen→hangup→call_records persistiert; Voice-Räume: join→up_mic→down_mic→leave→Raum schließen |
| Build | Android-Build real getestet; iOS/HarmonyOS-Commits vermerken, dass Linux nicht bauen kann (etabliertes M3-Muster) |
| Manuell am Gerät | Echtes SFU-Audio/-Video, P2P-Anrufqualität (Blackbox kann WebRTC nicht automatisieren) |

## 8. Implementierungsreihenfolge (Pipeline in umgekehrter Abhängigkeitsreihenfolge)

0. API-Versionierungs-Middleware (zuerst, unabhängig lieferbar)
1. Sprachnachrichten (Upload + Transkodierung + Speicherung + Modell + Nachrichtentyp)
2. 1v1-Anruf-Signalisierungs-Zustandsmaschine (+ call_records + Verlaufs-REST)
3. Voice-Chat-Räume (REST + Raum-Zustandsmaschine + Mikrofonplätze)
4. media/sfu (mediasoup + docker-compose) + coturn
5. Drei-Plattform-Clients (Sprachaufnahme/-wiedergabe / Anruf-UI / Voice-Room-UI)
6. E2E + vollständige Regression
