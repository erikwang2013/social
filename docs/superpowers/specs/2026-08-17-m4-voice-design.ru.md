# Дизайн голосового этапа M4 (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- Дата: 2026-08-17
- Статус: подтверждено
- Объём: голосовые сообщения + звонки 1v1 + голосовые чат-комнаты (все три компонента); механизм версионирования API (через header) внедряется первым
- Вышестоящий дизайн: `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 Голосовая архитектура)

## 1. Цель

Реализовать голосовой комплект M4: голосовые сообщения (расширение типа сообщений IM + транскодирование), звонки 1v1 (конечный автомат сигнализации WS + медиаплоскость P2P), голосовые чат-комнаты (конечный автомат комнаты + SFU mediasoup). Параллельно внедрить механизм версионирования API через header.

## 2. Версионирование API (через header, задача 0 выполняется первой)

**Текущее состояние**: все эндпоинты зарегистрированы в группе с префиксом `/api/v1` (`config/route.php`), 10 контроллеров, `AuthMiddleware` подключён внутри группы.

**Механизм**: клиент отправляет запрос на путь без версии `/api/xxx` + `Header: X-Api-Version: v1`; глобальное промежуточное ПО `ApiVersionMiddleware` (`config/middleware.php`) переписывает путь и передаёт его в роутер.

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- Недопустимая версия (не `v1|v2|...`) → 400 + `lang_key`
- Нулевая миграция: существующие контроллеры/маршруты/E2E-пути не меняются
- Будущая v2: регистрация группы `/api/v2` → `app\api\v2\*`, промежуточное ПО менять не нужно
- Новые эндпоинты M4 регистрируются в `/api/v1/voice/*` (префикс версии сохраняется; клиент использует путь без версии + header)

## 3. Модель данных (m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**Новые таблицы**:

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

## 4. Голосовые сообщения

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- REST истории сообщений автоматически включает `voice_url/voice_duration` (cast модели)
- Транскодирование выполняется синхронно в рамках запроса (секунды на файл); при росте объёма — перевести в очередь (пометка ponytail)
- Предпосылка окружения: на хосте service требуется бинарник FFmpeg (проверить при реализации; установить при отсутствии)

## 5. Сигнализация звонков 1v1

**WS-фреймы** (переиспользуется существующий шлюз, префикс `call_*`):

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

**Конечный автомат** (один ключ Redis):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- Мьютекс свободного состояния: `SETNX im:callbusy:{uid}` (TTL 5 мин), при конфликте возвращается фрейм ошибки `already_in_call`
- Нет ответа за 30 с → не отвечен, обеим сторонам отправляется `call_timeout`, запись в БД
- accept → `call_records` status=2 + started_at
- hangup/завершение → status=5 + ended_at, освобождение busy-ключа
- Обрыв WS у любой стороны → отправить второй стороне `call_hangup` и завершить (ponytail: восстановление после переподключения не делается)
- Медиаплоскость — прямое соединение P2P (offer/answer/ICE только ретранслируются, медиапотоки не проходят через сервер); фолбэк TURN (coturn поставляется вместе с голосовыми чат-комнатами)
- ICE P2P не установился за 15 с → `call_failed` + завершение (в первой версии автоматический переход на SFU не делается, пометка ponytail); запись status=5

**История**: `GET /api/v1/voice/calls?page=` постраничный ответ (caller/callee/status/длительность).

## 6. Голосовые чат-комнаты

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**WS-фреймы** (префикс `room_*`):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- Лимит микрофонных слотов — 8 (1 владелец + 7 слотов, константа, позже настраивается в admin); при заполнении возвращается фрейм ошибки
- join/leave/смены микрофона сохраняются в таблицу `voice_room_members` + состояние комнаты в Redis; изменения рассылаются всем онлайн-участникам комнаты
- Владелец покидает комнату → закрытие комнаты (всем отправляется `room_closed`)

**Путь сигнализации SFU** (согласно документу дизайна: «вся сигнализация переиспользует WS-шлюз service»):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service ретранслирует фреймы в SFU (HTTP POST с трансляцией в API mediasoup: rtpCapabilities, создание/connect WebRtcTransport, produce/consume); ответ SFU → service → WS-пуш клиенту
- Один Router mediasoup на комнату; автоосвобождение после 5 мин простоя (пометка ponytail)

**Развёртывание**: `media/sfu` — голый Node-процесс (разработка) + `docker-compose.yml` зарезервирован для продакшена; контейнер `coturn` поставляется тем же блоком.

## 7. Стратегия тестирования

| Уровень | Покрытие |
|---|---|
| Модульные тесты | ApiVersionMiddleware (по умолчанию/явно/недопустимо/старый путь), конечный автомат звонка (invite/accept/reject/cancel/timeout/hangup/мьютекс), конечный автомат голосовой комнаты (join/микрофонные слоты/закрытие/заполнение/кик), валидация загрузки голоса (тип/размер/длительность) |
| Чёрный ящик E2E | Голосовые сообщения: загрузка → отправка фрейма → получение фрейма → история с duration; 1v1: invite→accept→проверка ретрансляции offer/answer/ICE→hangup→запись в call_records; голосовые комнаты: join→up_mic→down_mic→leave→закрытие комнаты |
| Сборка | Android собирается и проверяется; в коммитах iOS/HarmonyOS указывается, что на Linux сборка невозможна (устоявшийся паттерн M3) |
| Ручное тестирование на устройствах | Реальные аудио/видео SFU, качество звонка P2P (WebRTC нельзя автоматизировать чёрным ящиком) |

## 8. Порядок реализации (конвейер в обратном порядке зависимостей)

0. Промежуточное ПО версионирования API (сначала, независимо поставляемый результат)
1. Голосовые сообщения (загрузка + транскодирование + хранение + модель + тип сообщения)
2. Конечный автомат сигнализации звонков 1v1 (+ call_records + REST истории)
3. Голосовые чат-комнаты (REST + конечный автомат комнаты + микрофонные слоты)
4. media/sfu (mediasoup + docker-compose) + coturn
5. Клиенты трёх платформ (запись/воспроизведение голоса / UI звонка / UI голосовой комнаты)
6. E2E + полная регрессия
