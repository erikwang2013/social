# M4 ভয়েস মাইলস্টোন ডিজাইন (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- তারিখ: 2026-08-17
- অবস্থা: নিশ্চিত
- পরিধি: ভয়েস মেসেজ + 1v1 কল + ভয়েস চ্যাট রুম (তিনটি উপাদানই); API ভার্সনিং ব্যবস্থা (হেডার-ভিত্তিক) প্রথমে বাস্তবায়িত হবে
- ঊর্ধ্বতন ডিজাইন: `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 ভয়েস আর্কিটেকচার)

## 1. লক্ষ্য

M4 ভয়েস ত্রয়ী সরবরাহ করুন: ভয়েস মেসেজ (IM মেসেজ টাইপ সম্প্রসারণ + ট্রান্সকোডিং), 1v1 কল (WS সিগন্যালিং স্টেট মেশিন + P2P মিডিয়া প্লেন), ভয়েস চ্যাট রুম (রুম স্টেট মেশিন + mediasoup SFU)। একই সাথে API হেডার ভার্সনিং ব্যবস্থা বাস্তবায়ন করুন।

## 2. API ভার্সনিং (হেডার-ভিত্তিক, টাস্ক 0 প্রথমে)

**বর্তমান অবস্থা**: সব এন্ডপয়েন্ট `/api/v1` প্রিফিক্স গ্রুপে (`config/route.php`) নিবন্ধিত, ১০টি কন্ট্রোলার, `AuthMiddleware` গ্রুপের ভেতরে মাউন্ট করা।

**প্রক্রিয়া**: ক্লায়েন্ট ভার্সনবিহীন পাথ `/api/xxx` + `Header: X-Api-Version: v1` পাঠায়; গ্লোবাল মিডলওয়্যার `ApiVersionMiddleware` (`config/middleware.php`) পাথটি পুনর্লিখন করে রাউটারে হস্তান্তর করে।

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- অবৈধ ভার্সন (`v1|v2|...` নয়) → 400 + `lang_key`
- শূন্য মাইগ্রেশন: বিদ্যমান কন্ট্রোলার/রুট/E2E পাথ সব অপরিবর্তিত
- ভবিষ্যতের v2: `/api/v2` গ্রুপ নিবন্ধন → `app\api\v2\*`, মিডলওয়্যারে কোনো পরিবর্তন লাগবে না
- M4 নতুন এন্ডপয়েন্ট `/api/v1/voice/*`-এর অধীনে নিবন্ধিত (ভার্সন প্রিফিক্স রয়ে যায়; ক্লায়েন্ট ভার্সনবিহীন পাথ + হেডার ব্যবহার করে)

## 3. ডেটা মডেল (m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**নতুন টেবিল**:

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

## 4. ভয়েস মেসেজ

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- হিস্টোরি REST স্বয়ংক্রিয়ভাবে `voice_url/voice_duration` অন্তর্ভুক্ত করে (মডেল কাস্ট)
- ট্রান্সকোডিং রিকোয়েস্টের ভেতরে সিঙ্ক্রোনাসভাবে সম্পন্ন হয় (প্রতি ফাইলে সেকেন্ড); ভলিউম বাড়লে কিউতে দিন (ponytail নোট)
- পরিবেশ পূর্বশর্ত: service হোস্টে FFmpeg বাইনারি প্রয়োজন (বাস্তবায়নের সময় যাচাই করুন; না থাকলে ইনস্টল করুন)

## 5. 1v1 কল সিগন্যালিং

**WS ফ্রেম** (বিদ্যমান গেটওয়ে পুনঃব্যবহার, `call_*` প্রিফিক্স):

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

**স্টেট মেশিন** (একটি Redis কী):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- নিষ্ক্রিয়তা মিউটেক্স: `SETNX im:callbusy:{uid}` (TTL ৫ মিনিট), সংঘর্ষে `already_in_call` ত্রুটি ফ্রেম ফেরত দিন
- ৩০ সেকেন্ডে কোনো উত্তর নেই → উত্তরহীন, উভয় পক্ষে `call_timeout` পুশ, সংরক্ষণ
- accept → `call_records` status=2 + started_at
- hangup/সমাপ্তি → status=5 + ended_at, busy কী মুক্ত
- যেকোনো পক্ষের WS সংযোগ বিচ্ছিন্ন → অপর পক্ষে `call_hangup` পুশ করে সমাপ্ত (ponytail: রিকানেক্ট রিকভারি নেই)
- মিডিয়া প্লেন সরাসরি P2P সংযোগ (offer/answer/ICE শুধু রিলে হয়, মিডিয়া স্ট্রিম কখনো সার্ভার দিয়ে যায় না); TURN ফলব্যাক (coturn ভয়েস চ্যাট রুমের সাথে সরবরাহ করা হয়)
- ১৫ সেকেন্ডে P2P ICE সংযুক্ত না → `call_failed` + সমাপ্তি (v1 স্বয়ংক্রিয়ভাবে SFU-তে স্যুইচ করে না, ponytail নোট); status=5 সংরক্ষণ

**হিস্টোরি**: `GET /api/v1/voice/calls?page=` পেজিনেটেড প্রতিক্রিয়া (caller/callee/status/সময়কাল)।

## 6. ভয়েস চ্যাট রুম

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**WS ফ্রেম** (`room_*` প্রিফিক্স):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- মাইক স্লট সীমা ৮ (১ মালিক + ৭ মাইক স্লট, ধ্রুবক, পরে admin-এ কনফিগারযোগ্য); পূর্ণ হলে ত্রুটি ফ্রেম ফেরত
- join/leave/মাইক পরিবর্তন `voice_room_members` টেবিল + Redis রুম অবস্থায় সংরক্ষিত; পরিবর্তন রুমের সব অনলাইন সদস্যকে পুশ করা হয়
- মালিক চলে গেলে → রুম বন্ধ (সবাইকে `room_closed` পুশ)

**SFU সিগন্যালিং পথ** (ডিজাইন ডকুমেন্ট অনুযায়ী: "সব সিগন্যালিং service WS গেটওয়ে পুনঃব্যবহার করে"):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service ফ্রেম → SFU-তে রিলে করে (HTTP POST যা mediasoup API অনুবাদ করে: rtpCapabilities, WebRtcTransport তৈরি/connect, produce/consume); SFU প্রতিক্রিয়া → service → WS পুশ ক্লায়েন্টের কাছে
- প্রতি রুমে একটি mediasoup Router; ৫ মিনিট নিষ্ক্রিয় থাকলে স্বয়ংক্রিয় মুক্তি (ponytail নোট)

**ডিপ্লয়মেন্ট**: `media/sfu` নগ্ন Node প্রসেস (ডেভেলপমেন্ট) + `docker-compose.yml` প্রোডাকশনের জন্য সংরক্ষিত; `coturn` কন্টেইনার একই ব্লকে সরবরাহ করা হয়।

## 7. টেস্ট কৌশল

| স্তর | কভারেজ |
|---|---|
| ইউনিট টেস্ট | ApiVersionMiddleware (ডিফল্ট/স্পষ্ট/অবৈধ/পুরনো পথ), কল স্টেট মেশিন (invite/accept/reject/cancel/timeout/hangup/মিউটেক্স), ভয়েস রুম স্টেট মেশিন (join/মাইক স্লট/বন্ধ/পূর্ণ/কিক), ভয়েস আপলোড যাচাই (টাইপ/আকার/সময়কাল) |
| ব্ল্যাক-বক্স E2E | ভয়েস মেসেজ: আপলোড → ফ্রেম পাঠানো → ফ্রেম গ্রহণ → হিস্টোরিতে সময়কাল অন্তর্ভুক্ত; 1v1: invite→accept→offer/answer/ICE রিলে যাচাই→hangup→call_records সংরক্ষণ; ভয়েস রুম: join→up_mic→down_mic→leave→রুম বন্ধ |
| বিল্ড | Android বিল্ড বাস্তবে পরীক্ষিত; iOS/HarmonyOS কমিটে উল্লেখ যে Linux বিল্ড করতে পারে না (M3-এর প্রতিষ্ঠিত প্যাটার্ন) |
| বাস্তব ডিভাইসে ম্যানুয়াল | বাস্তব SFU অডিও/ভিডিও, P2P কলের গুণমান (ব্ল্যাক-বক্স WebRTC অটোমেট করতে পারে না) |

## 8. বাস্তবায়নের ক্রম (নির্ভরতা-বিপরীত পাইপলাইন)

0. API ভার্সনিং মিডলওয়্যার (প্রথমে, স্বাধীনভাবে ডেলিভারেবল)
1. ভয়েস মেসেজ (আপলোড + ট্রান্সকোডিং + স্টোরেজ + মডেল + মেসেজ টাইপ)
2. 1v1 কল সিগন্যালিং স্টেট মেশিন (+ call_records + হিস্টোরি REST)
3. ভয়েস চ্যাট রুম (REST + রুম স্টেট মেশিন + মাইক স্লট)
4. media/sfu (mediasoup + docker-compose) + coturn
5. তিন-প্ল্যাটফর্ম ক্লায়েন্ট (ভয়েস রেকর্ড/প্লেব্যাক / কল UI / ভয়েস রুম UI)
6. E2E + সম্পূর্ণ রিগ্রেশন
