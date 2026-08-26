# تصميم مرحلة الصوت M4 (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- التاريخ: 2026-08-17
- الحالة: مؤكدة
- النطاق: الرسائل الصوتية + المكالمات الفردية 1v1 + غرف المحادثة الصوتية (المكونات الثلاثة جميعًا)؛ آلية إصدارات API (عبر الترويسة) تُنفَّذ أولًا
- التصميم الأعلى: `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 بنية الصوت)

## 1. الهدف

تقديم مجموعة الصوت الثلاثية M4: الرسائل الصوتية (توسيع نوع رسالة IM + إعادة الترميز)، المكالمات الفردية 1v1 (آلة حالات إشارات WS + مستوى وسائط P2P)، غرف المحادثة الصوتية (آلة حالات الغرفة + SFU mediasoup). بالإضافة إلى تنفيذ آلية إصدارات API عبر الترويسة.

## 2. إصدارات API (عبر الترويسة، المهمة 0 أولًا)

**الوضع الحالي**: جميع نقاط النهاية مسجلة في مجموعة البادئة `/api/v1` (`config/route.php`)، 10 وحدات تحكم، مع تركيب `AuthMiddleware` داخل المجموعة.

**الآلية**: يرسل العميل مسارًا بدون إصدار `/api/xxx` + `Header: X-Api-Version: v1`؛ الوسيط العام `ApiVersionMiddleware` (`config/middleware.php`) يعيد كتابة المسار ثم يحيله إلى الموجّه.

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- إصدار غير صالح (ليس `v1|v2|...`) → 400 + `lang_key`
- هجرة صفرية: وحدات التحكم/المسارات/مسارات E2E الحالية دون تغيير
- الإصدار v2 مستقبلًا: تسجيل مجموعة `/api/v2` → `app\api\v2\*`، لا حاجة لتعديل الوسيط
- نقاط نهاية M4 الجديدة تُسجَّل تحت `/api/v1/voice/*` (يبقى بادئة الإصدار؛ يستخدم العميل مسارًا بدون إصدار + ترويسة)

## 3. نموذج البيانات (m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**الجداول الجديدة**:

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

## 4. الرسائل الصوتية

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- واجهة السجل REST تُرجع تلقائيًا `voice_url/voice_duration` (تحويل النموذج)
- إعادة الترميز تتم بشكل متزامن داخل الطلب (ثوانٍ لكل ملف)؛ عند نمو الحجم تُنقل إلى قائمة انتظار (ملاحظة ponytail)
- شرط البيئة: يحتاج مضيف service إلى ثنائي FFmpeg (تحقق أثناء التنفيذ؛ ثبّته إذا كان مفقودًا)

## 5. إشارات المكالمة الفردية 1v1

**إطارات WS** (إعادة استخدام البوابة الحالية، بادئة `call_*`):

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

**آلة الحالات** (مفتاح Redis واحد):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- كبح الخمول: `SETNX im:callbusy:{uid}` (TTL 5 دقائق)، عند التعارض يُرجع إطار الخطأ `already_in_call`
- لا رد خلال 30 ثانية → غير مُجاب، إرسال `call_timeout` للطرفين، الحفظ في قاعدة البيانات
- accept → `call_records` status=2 + started_at
- hangup/الانتهاء → status=5 + ended_at، تحرير مفتاح busy
- انقطاع WS لأي طرف → إرسال `call_hangup` للطرف الآخر والإنهاء (ponytail: دون استعادة عبر إعادة الاتصال)
- مستوى الوسائط اتصال P2P مباشر (offer/answer/ICE تُمرَّر فقط، تدفقات الوسائط لا تمر عبر الخادم)؛ احتياط TURN (coturn يُسلَّم مع غرف المحادثة الصوتية)
- عدم اتصال ICE P2P خلال 15 ثانية → `call_failed` + إنهاء (الإصدار الأول لا يتحول تلقائيًا إلى SFU، ملاحظة ponytail)؛ الحفظ status=5

**السجل**: `GET /api/v1/voice/calls?page=` استجابة مقسمة إلى صفحات (caller/callee/status/المدة).

## 6. غرف المحادثة الصوتية

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**إطارات WS** (بادئة `room_*`):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- حد مقاعد الميكروفون 8 (مالك واحد + 7 مقاعد، ثابت، قابل للتهيئة لاحقًا في admin)؛ عند الامتلاء يُرجع إطار خطأ
- join/leave/تغييرات الميكروفون تُحفظ في جدول `voice_room_members` + حالة الغرفة في Redis؛ تُرسل التغييرات لجميع الأعضاء المتصلين في الغرفة
- مغادرة المالك → إغلاق الغرفة (إرسال `room_closed` للجميع)

**مسار إشارات SFU** (وفقًا لوثيقة التصميم: «جميع الإشارات تعيد استخدام بوابة WS في service»):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service يمرر الإطارات → SFU (POST HTTP يترجم واجهة mediasoup: rtpCapabilities، إنشاء/connect لـ WebRtcTransport، produce/consume)؛ استجابة SFU → service → إرسال WS للعميل
- Router واحد من mediasoup لكل غرفة؛ تحرير تلقائي بعد 5 دقائق خمول (ملاحظة ponytail)

**النشر**: `media/sfu` عملية Node مكشوفة (تطوير) + `docker-compose.yml` محجوز للإنتاج؛ حاوية `coturn` تُسلَّم في نفس الكتلة.

## 7. استراتيجية الاختبار

| المستوى | التغطية |
|---|---|
| اختبارات الوحدة | ApiVersionMiddleware (افتراضي/صريح/غير صالح/مسار قديم)، آلة حالات المكالمة (invite/accept/reject/cancel/timeout/hangup/كبح)، آلة حالات الغرفة الصوتية (join/مقاعد الميك/إغلاق/امتلاء/طرد)، التحقق من رفع الصوت (النوع/الحجم/المدة) |
| E2E صندوق أسود | الرسائل الصوتية: رفع → إرسال إطار → استلام إطار → السجل يتضمن المدة؛ 1v1: invite→accept→التحقق من تمرير offer/answer/ICE→hangup→حفظ call_records؛ الغرف الصوتية: join→up_mic→down_mic→leave→إغلاق الغرفة |
| البناء | بناء Android يُختبر فعليًا؛ إرساليات iOS/HarmonyOS توضح أن Linux لا يستطيع البناء (نمط M3 المعتمد) |
| يدوي على جهاز حقيقي | صوت/فيديو SFU حقيقي، جودة مكالمة P2P (الصندوق الأسود لا يمكنه أتمتة WebRTC) |

## 8. ترتيب التنفيذ (خط أنابيب عكس الترتيب الاعتمادي)

0. وسيط إصدارات API (أولًا، تسليم مستقل)
1. الرسائل الصوتية (رفع + إعادة ترميز + تخزين + نموذج + نوع الرسالة)
2. آلة حالات إشارات المكالمة 1v1 (+ call_records + REST للسجل)
3. غرف المحادثة الصوتية (REST + آلة حالات الغرفة + مقاعد الميكروفون)
4. media/sfu (mediasoup + docker-compose) + coturn
5. عملاء المنصات الثلاث (تسجيل/تشغيل الصوت / واجهة المكالمة / واجهة الغرفة الصوتية)
6. E2E + انحدار كامل
