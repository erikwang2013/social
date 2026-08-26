# M4 वॉइस माइलस्टोन डिज़ाइन (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- दिनांक: 2026-08-17
- स्थिति: पुष्टि प्राप्त
- दायरा: वॉइस संदेश + 1v1 कॉल + वॉइस चैट रूम (तीनों घटक); API वर्जनिंग तंत्र (हेडर-आधारित) सबसे पहले लागू होगा
- ऊपरी डिज़ाइन: `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 वॉइस आर्किटेक्चर)

## 1. लक्ष्य

M4 वॉइस त्रयी वितरित करें: वॉइस संदेश (IM संदेश प्रकार विस्तार + ट्रांसकोडिंग), 1v1 कॉल (WS सिग्नलिंग स्टेट मशीन + P2P मीडिया प्लेन), वॉइस चैट रूम (रूम स्टेट मशीन + mediasoup SFU)। साथ ही API हेडर वर्जनिंग तंत्र लागू करें।

## 2. API वर्जनिंग (हेडर-आधारित, कार्य 0 पहले)

**वर्तमान स्थिति**: सभी एंडपॉइंट `/api/v1` प्रीफ़िक्स समूह (`config/route.php`) में पंजीकृत हैं, 10 कंट्रोलर, `AuthMiddleware` समूह में माउंटेड है।

**तंत्र**: क्लाइंट बिना वर्जन वाला पथ `/api/xxx` + `Header: X-Api-Version: v1` भेजता है; ग्लोबल मिडलवेयर `ApiVersionMiddleware` (`config/middleware.php`) पथ को पुनर्लिखित करके राउटर को सौंपता है।

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- अमान्य वर्जन (`v1|v2|...` नहीं) → 400 + `lang_key`
- शून्य माइग्रेशन: मौजूदा कंट्रोलर/रूट/E2E पथ सभी अपरिवर्तित
- भविष्य की v2: `/api/v2` समूह पंजीकृत करें → `app\api\v2\*`, मिडलवेयर में बदलाव की आवश्यकता नहीं
- M4 नए एंडपॉइंट `/api/v1/voice/*` के अंतर्गत पंजीकृत हैं (वर्जन प्रीफ़िक्स बरकरार; क्लाइंट बिना वर्जन वाला पथ + हेडर उपयोग करता है)

## 3. डेटा मॉडल (m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**नई तालिकाएँ**:

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

## 4. वॉइस संदेश

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- इतिहास REST स्वचालित रूप से `voice_url/voice_duration` शामिल करता है (मॉडल कास्ट)
- ट्रांसकोडिंग अनुरोध के भीतर सिंक्रोनस रूप से पूरी होती है (प्रति फ़ाइल सेकंड); वॉल्यूम बढ़ने पर क्यू में डालें (ponytail नोट)
- पर्यावरण पूर्वापेक्षा: service होस्ट पर FFmpeg बाइनरी आवश्यक है (कार्यान्वयन के दौरान सत्यापित करें; अनुपस्थित होने पर स्थापित करें)

## 5. 1v1 कॉल सिग्नलिंग

**WS फ्रेम** (मौजूदा गेटवे का पुनः उपयोग, `call_*` प्रीफ़िक्स):

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

**स्टेट मशीन** (एकल Redis key):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- निष्क्रिय म्यूटेक्स: `SETNX im:callbusy:{uid}` (TTL 5 मिनट), टकराव पर `already_in_call` त्रुटि फ्रेम लौटाएँ
- 30 सेकंड में कोई उत्तर नहीं → अनुत्तरित, दोनों ओर `call_timeout` पुश करें, पर्सिस्ट करें
- accept → `call_records` status=2 + started_at
- hangup/समाप्ति → status=5 + ended_at, busy key मुक्त करें
- किसी भी ओर WS डिस्कनेक्ट → दूसरी ओर `call_hangup` पुश करके समाप्त करें (ponytail: रीकनेक्ट रिकवरी नहीं)
- मीडिया प्लेन सीधा P2P कनेक्शन है (offer/answer/ICE केवल रिले होते हैं, मीडिया स्ट्रीम सर्वर से कभी नहीं गुजरती); TURN फॉलबैक (coturn वॉइस चैट रूम के साथ वितरित होता है)
- P2P ICE 15 सेकंड में कनेक्ट नहीं → `call_failed` + समाप्ति (v1 SFU पर स्वचालित रूप से स्विच नहीं करता, ponytail नोट); status=5 पर्सिस्ट करें

**इतिहास**: `GET /api/v1/voice/calls?page=` पेजिनेटेड प्रतिक्रिया (caller/callee/status/अवधि)।

## 6. वॉइस चैट रूम

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**WS फ्रेम** (`room_*` प्रीफ़िक्स):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- माइक स्लॉट 8 तक सीमित (1 स्वामी + 7 माइक स्लॉट, स्थिरांक, बाद में admin में कॉन्फ़िगर करने योग्य); भरा होने पर त्रुटि फ्रेम लौटाएँ
- join/leave/माइक परिवर्तन `voice_room_members` तालिका + Redis रूम स्थिति में पर्सिस्ट; परिवर्तन रूम के सभी ऑनलाइन सदस्यों को पुश करें
- स्वामी का जाना → रूम बंद करें (सभी को `room_closed` पुश करें)

**SFU सिग्नलिंग पथ** (डिज़ाइन दस्तावेज़ के अनुसार: "सभी सिग्नलिंग service WS गेटवे का पुनः उपयोग करती है"):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service फ्रेम → SFU रिले करता है (HTTP POST जो mediasoup API का अनुवाद करता है: rtpCapabilities, WebRtcTransport निर्माण/connect, produce/consume); SFU प्रतिक्रिया → service → WS पुश क्लाइंट को
- प्रति रूम एक mediasoup Router; 5 मिनट निष्क्रिय रहने पर स्वचालित रिलीज़ (ponytail नोट)

**तैनाती**: `media/sfu` नग्न Node प्रक्रिया (डेवलपमेंट) + `docker-compose.yml` प्रोडक्शन के लिए आरक्षित; `coturn` कंटेनर उसी ब्लॉक में वितरित होता है।

## 7. परीक्षण रणनीति

| परत | कवरेज |
|---|---|
| यूनिट टेस्ट | ApiVersionMiddleware (डिफ़ॉल्ट/स्पष्ट/अमान्य/पुराना पथ), कॉल स्टेट मशीन (invite/accept/reject/cancel/timeout/hangup/म्यूटेक्स), वॉइस रूम स्टेट मशीन (join/माइक स्लॉट/बंद/भरा/kick), वॉइस अपलोड सत्यापन (प्रकार/आकार/अवधि) |
| ब्लैक-बॉक्स E2E | वॉइस संदेश: अपलोड → फ्रेम भेजें → फ्रेम प्राप्त करें → इतिहास में अवधि शामिल; 1v1: invite→accept→offer/answer/ICE रिले की पुष्टि→hangup→call_records पर्सिस्ट; वॉइस रूम: join→up_mic→down_mic→leave→रूम बंद |
| बिल्ड | Android बिल्ड वास्तव में परीक्षित; iOS/HarmonyOS कमिट में नोट कि Linux बिल्ड नहीं कर सकता (M3 का स्थापित पैटर्न) |
| वास्तविक डिवाइस मैनुअल | वास्तविक SFU ऑडियो/वीडियो, P2P कॉल गुणवत्ता (ब्लैक-बॉक्स WebRTC को स्वचालित नहीं कर सकता) |

## 8. कार्यान्वयन क्रम (निर्भरता-उल्टा पाइपलाइन)

0. API वर्जनिंग मिडलवेयर (पहले, स्वतंत्र रूप से वितरण योग्य)
1. वॉइस संदेश (अपलोड + ट्रांसकोडिंग + भंडारण + मॉडल + संदेश प्रकार)
2. 1v1 कॉल सिग्नलिंग स्टेट मशीन (+ call_records + इतिहास REST)
3. वॉइस चैट रूम (REST + रूम स्टेट मशीन + माइक स्लॉट)
4. media/sfu (mediasoup + docker-compose) + coturn
5. तीन-प्लेटफ़ॉर्म क्लाइंट (वॉइस रिकॉर्ड/प्लेबैक / कॉल UI / वॉइस रूम UI)
6. E2E + पूर्ण रिग्रेशन
