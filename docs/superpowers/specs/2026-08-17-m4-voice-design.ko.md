# M4 음성 마일스톤 설계 (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- 날짜: 2026-08-17
- 상태: 확정됨
- 범위: 음성 메시지 + 1v1 통화 + 음성 채팅방(3종 세트 모두 구현); API 버전 관리 메커니즘(header 방식) 우선 적용
- 상위 설계: `docs/superpowers/specs/2026-08-16-social-platform-design.md`(§8 음성 아키텍처)

## 1. 목표

M4 음성 3종 세트를 제공한다: 음성 메시지(IM 메시지 타입 확장 + 트랜스코딩), 1v1 통화(WS 시그널링 상태 머신 + P2P 미디어 플레인), 음성 채팅방(방 상태 머신 + mediasoup SFU). 동시에 API header 버전 관리 메커니즘도 적용한다.

## 2. API 버전 관리(header 방식, 태스크 0 우선)

**현황**: 모든 엔드포인트가 `/api/v1` 접두사 그룹(`config/route.php`)에 등록되어 있으며, 컨트롤러 10개, `AuthMiddleware`가 그룹 내에 마운트되어 있다.

**메커니즘**: 클라이언트는 버전 없는 경로 `/api/xxx` + `Header: X-Api-Version: v1`로 제출한다. 전역 미들웨어 `ApiVersionMiddleware`(`config/middleware.php`)가 경로를 재작성한 뒤 라우터에 넘긴다.

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- 잘못된 버전(`v1|v2|...` 아님) → 400 + `lang_key`
- 제로 마이그레이션: 기존 컨트롤러/라우트/E2E 경로 전부 변경 없음
- 향후 v2: `/api/v2` 그룹 등록 → `app\api\v2\*`, 미들웨어 수정 불필요
- M4 신규 엔드포인트는 `/api/v1/voice/*`에 등록(버전 접두사 유지, 클라이언트는 버전 없는 경로 + header 제출)

## 3. 데이터 모델(m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**새 테이블**:

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

## 4. 음성 메시지

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- 히스토리 메시지 REST가 자동으로 `voice_url/voice_duration` 포함(모델 cast)
- 트랜스코딩은 요청 내에서 동기 처리(파일당 초 단위); 볼륨이 커지면 큐 처리(ponytail 표기)
- 환경 전제: service 실행 머신에 FFmpeg 바이너리 필요(구현 시 검증, 없으면 설치)

## 5. 1v1 통화 시그널링

**WS 프레임**(기존 게이트웨이 재사용, `call_*` 접두사):

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

**상태 머신**(Redis 단일 key):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- 유휴 상호 배타: `SETNX im:callbusy:{uid}`(TTL 5분), 충돌 시 `already_in_call` 에러 프레임 반환
- 30초 무응답 → 부재중, 양쪽에 `call_timeout` 푸시, DB 저장
- accept → `call_records` status=2 + started_at
- hangup/종료 → status=5 + ended_at, busy key 해제
- 어느 한쪽 WS 연결 끊김 → 상대방에게 `call_hangup` 푸시 후 종료(ponytail: 재연결 복구 없음)
- 미디어 플레인은 P2P 직접 연결(offer/answer/ICE는 중계만, 미디어 스트림은 서버 경유 안 함); TURN 폴백(coturn은 음성 채팅방과 함께 제공)
- P2P ICE 15초 미연결 → `call_failed` + 종료(초기 버전은 SFU 자동 전환 없음, ponytail 표기); status=5 저장

**히스토리**: `GET /api/v1/voice/calls?page=` 페이지네이션 응답(caller/callee/status/시간).

## 6. 음성 채팅방

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**WS 프레임**(`room_*` 접두사):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- 마이크 슬롯 상한 8개(방장 1 + 마이크 슬롯 7, 상수, 추후 admin 설정 가능); 꽉 차면 에러 프레임 반환
- join/leave/마이크 변경은 `voice_room_members` 테이블 + Redis 방 상태에 저장; 변경 사항을 방 안의 모든 온라인 멤버에게 푸시
- 방장 퇴장 → 방 폐쇄(전원에게 `room_closed` 푸시)

**SFU 시그널링 경로**(설계 문서에 따름: "시그널링은 일률적으로 service WS 게이트웨이 재사용"):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service가 프레임을 SFU로 중계(HTTP POST로 mediasoup API 변환: rtpCapabilities, WebRtcTransport 생성/connect, produce/consume); SFU 응답 → service → WS로 클라이언트에 푸시
- 방마다 mediasoup Router 1개; 5분 유휴 시 자동 해제(ponytail 표기)

**배포**: `media/sfu` 베어 Node 프로세스(개발) + `docker-compose.yml` 프로덕션 예약; `coturn` 컨테이너는 같은 블록으로 제공.

## 7. 테스트 전략

| 레이어 | 커버리지 |
|---|---|
| 유닛 테스트 | ApiVersionMiddleware(기본/명시/잘못된/레거시 경로), 통화 상태 머신(invite/accept/reject/cancel/timeout/hangup/상호 배타), 음성 채팅방 상태 머신(join/마이크 슬롯/폐쇄/만석/퇴출), 음성 업로드 검증(타입/크기/시간) |
| 블랙박스 E2E | 음성 메시지: 업로드→프레임 전송→프레임 수신→히스토리에 duration 포함; 1v1: invite→accept→offer/answer/ICE 중계 검증→hangup→call_records 저장; 음성 채팅방: join→up_mic→down_mic→leave→방 폐쇄 |
| 빌드 | Android 빌드 실측; iOS/HarmonyOS 커밋에 Linux 빌드 불가 명시(M3 기존 패턴) |
| 실기기 수동 테스트 | 실제 SFU 오디오/비디오, P2P 통화 품질(블랙박스로는 WebRTC 자동화 불가) |

## 8. 구현 순서(의존성 역순 파이프라인)

0. API 버전 관리 미들웨어(우선, 독립적으로 제공 가능)
1. 음성 메시지(업로드 + 트랜스코딩 + 저장 + 모델 + 메시지 타입)
2. 1v1 통화 시그널링 상태 머신(+ call_records + 히스토리 REST)
3. 음성 채팅방(REST + 방 상태 머신 + 마이크 슬롯)
4. media/sfu(mediasoup + docker-compose) + coturn
5. 삼단 클라이언트(음성 녹음/재생 / 통화 UI / 음성 채팅방 UI)
6. E2E + 전체 회귀 테스트
