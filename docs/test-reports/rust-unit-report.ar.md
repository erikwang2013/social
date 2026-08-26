# تقرير اختبارات الوحدة لـ Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- التاريخ: 2026-08-27
- الموقع: `/home/wwwroot/social/infrastructure`
- الأمر: `cargo test --workspace` (الميزات الافتراضية)، بالإضافة إلى التحقق من الواجهات الخلفية المقيّدة بالميزات (tsdb/graph/search/kv)
- النتيجة: **180 ناجحًا / 0 فاشلًا** (179 اختبار وحدة+تكامل + 1 doctest)

## الملخص

| crate | عدد الاختبارات | ناجح | فاشل | الوحدات المغطاة |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire، incr، ttl، MemoryCache |
| bee_cli | 23 | 23 | 0 | تعيين rust_type، find_bin_name، validate_name، تحليل وسائط bin |
| bee_config | 14 | 14 | 0 | IniParser (تعليقات/مسافات/تبديل أقسام)، Config، ConfigSource، 6 تكامل |
| bee_config_macro | 0 | — | — | مغطى بشكل غير مباشر عبر اختبارات التكامل |
| bee_graph | 15 | 15 | 0 | StubGraphDB: اتجاه/عمق/تسميات الاجتياز، add/update/delete، مسارات الخطأ، serde (خلفية الميزة 29 إضافية) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset، المفاتيح منتهية الصلاحية، مسارات الخطأ |
| bee_logs | 4 | 4 | 0 | level_str جميع المستويات، إخراج ملف، إخراج stdout/stderr |
| bee_orm | 19 | 19 | 0 | SelectBuilder (تكامل): order/limit/offset/ربط المعاملات/إعادة الاستخدام/table_name/عرض الأخطاء (0 داخل lib) |
| bee_orm_macro | 0 | — | — | مغطى بشكل غير مباشر عبر اختبارات التكامل |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort)، router (method/404/namespace)، خط أنابيب dispatch، استعادة/إبقاء/انتهاء جلسات الكوكيز |
| bee_rust | 2 | 2 | 0 | صادرات prelude، اسم بديل لـ Result |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/الكتابة فوق/الترقيم/get/استعلام فارغ/serde (خلفية الميزة 20 إضافية) |
| bee_session | 8 | 8 | 0 | set/get/delete، save/load/refresh، TTL floor، مسارات الخطأ، تفرد UUID |
| bee_template | 6+1 | 7 | 0 | ماكرو context!، render، أخطاء قالب/متغير مفقود، محرك فارغ، أعداد عائمة غير منتهية (بما في ذلك 1 doctest) |
| bee_tsdb | 10 | 10 | 0 | تصفية Query (Neq/Regex/نطاق/AND)، Point serde، enum debug (خلفية الميزة 22 إضافية) |
| social_grpc | 5 | 5 | 0 | SearchService: رحلات ذهاب وإياب index/search/delete، الرجوع عند JSON غير صالح، فهرس فارغ |
| hello_bee | 0 | — | — | برنامج مثال، بلا اختبارات |

## قائمة غير المغطى

- **ميزة `redis` في bee_kv (RedisStore)**: تتطلب خادم Redis حيًا، غير مغطاة
- **hello_bee**: برنامج مثال، 0 اختبار
- **الواجهات الخلفية المقيّدة بالميزات** (لا تُترجم مع الميزات الافتراضية): تم التحقق من إمكانية ترجمتها واجتيازها للاختبارات بمجموعات الميزات الخاصة بها (tsdb 22، graph 29، search 20، kv 10)، لكن الواجهات الحقيقية مثل es/opensearch/clickhouse وneo4j/nebulagraph/arangodb وinfluxdb/iotdb/questdb وredis تتطلب خدمات خارجية — تحقق على مستوى الترجمة فقط
- **bee_config_macro / bee_orm_macro**: وحدات ماكرو إجرائية، مغطاة بشكل غير مباشر عبر اختبارات التكامل الخاصة بها، ولا توجد اختبارات وحدة مستقلة

## أخطاء حقيقية موثقة (لم يُعدّل كود مصدر المكتبات)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` يكرر على `&point.fields` (HashMap) دون ترتيب بينما tags مرتبة → إخراج line protocol غير حتمي عند وجود حقول متعددة
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` غير ذري (تنفيذ نقطة بنقطة مع قصر الدارة `?`)، لا يتوافق مع «atomically» المدّعى في توثيق trait
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` يعيد دائمًا `VertexNotFound(edge.from)` حتى عندما تكون النهاية المفقودة هي `to`
4. `bee_search` MemoryEngine `search` — يتجاهل from/size المرسل من طبقة gRPC (لا ترقيم صفحات)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: المعرف غير الرقمي يتحول بصمت إلى 0
6. توثيق ماكرو `context!` في `bee_template/src/lib.rs` — يدّعي أن NaN يسبب panic، لكن serde_json ≥1.0.128 في الواقع يserializeه كـ null (توثيق قديم)
7. `bee_search/src/elasticsearch.rs:64` — NDJSON المجمّع يدخل index/id الخام في JSON؛ المعرفات المحتوية على `"` تنتج NDJSON غير صالح

## ملاحظات البيئة

- cargo موجود في `~/.cargo/bin` (ليس في PATH)، يتطلب `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc يتطلب `protoc`: تم الحصول عليه عبر `apt-get download protobuf-compiler` + فك ضغط `dpkg-deb -x` إلى `/tmp/protoc-local`، `PROTOC=/tmp/protoc-local/usr/bin/protoc` (بدون حاجة إلى sudo)
