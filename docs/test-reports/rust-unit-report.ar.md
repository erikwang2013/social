# تقرير اختبارات الوحدة لـ Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- التاريخ: 2026-08-27
- الموقع: `/home/wwwroot/social/infrastructure`
- الأمر: `cargo test --workspace` (الميزات الافتراضية)، بالإضافة إلى التحقق من الواجهات الخلفية المقيّدة بالميزات (tsdb/graph/search/kv)
- النتيجة: **183 ناجحًا / 0 فاشلًا** (178 وحدة+تكامل + 5 inline تحت feature + 1 doctest، إلخ؛ يتضمن workspace الافتراضي حالات bee_search الست لأن social_grpc يعتمد على feature `elasticsearch` الخاصة بها)

## الملخص

| crate | عدد الاختبارات | ناجح | فاشل | الوحدات المغطاة |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire، incr، ttl، MemoryCache |
| bee_cli | 14 | 14 | 0 | تعيين rust_type، find_bin_name، validate_name، تحليل وسائط bin |
| bee_config | 8 + 6 تكامل | 14 | 0 | IniParser (تعليقات/مسافات/تبديل أقسام)، Config، ConfigSource، أخطاء إعادة التحميل |
| bee_config_macro | 0 | — | — | مغطى بشكل غير مباشر عبر اختبارات التكامل |
| bee_graph | 15 | 15 | 0 | StubGraphDB: اتجاه/عمق/تسميات الاجتياز، add/update/delete، مسارات الخطأ، serde (feature neo4j 5 إضافية) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset، المفاتيح منتهية الصلاحية، مسارات الخطأ (feature redis 3 حالات Redis حقيقية إضافية) |
| bee_logs | 4 | 4 | 0 | level_str جميع المستويات، إخراج ملف، إخراج stdout/stderr |
| bee_orm | 19 تكامل | 19 | 0 | SelectBuilder: order/limit/offset/ربط المعاملات/إعادة الاستخدام/table_name/Display الأخطاء (0 داخل lib) |
| bee_orm_macro | 0 | — | — | مغطى بشكل غير مباشر عبر اختبارات التكامل |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort)، router (method/404/namespace)، خط أنابيب dispatch، استعادة/إبقاء/انتهاء جلسات الكوكيز |
| bee_rust | 2 (bin 9 إضافية) | 11 | 0 | صادرات prelude، اسم بديل لـ Result، تحليل وسائط CLI |
| bee_search | 20 (بما فيها 6 inline تحت feature) | 20 | 0 | MemoryEngine: index/delete/الكتابة فوق/الترقيم/get/استعلام فارغ/serde؛ مشغّل Elasticsearch: get/search/bulk/aggregate، تهريب NDJSON |
| bee_session | 8 | 8 | 0 | set/get/delete، save/load/refresh، TTL floor، مسارات الخطأ، تفرد UUID |
| bee_template | 6 + 1 doctest | 7 | 0 | ماكرو context!، render، أخطاء قالب/متغير مفقود، محرك فارغ، أعداد عائمة غير منتهية (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | كتابة/كتابة مجمعة، حدود استعلامات النطاق، تصفية Eq/Neq/Regex/AND، Point serde، CQ (feature influxdb 5 إضافية، بما فيها حتمية line protocol) |
| social_grpc | 6 | 6 | 0 | SearchService: رحلات ذهاب وإياب index/search/delete، الرجوع عند JSON غير صالح، فهرس فارغ، خطأ المعرف غير الرقمي |
| hello_bee | 0 | — | — | برنامج مثال، بلا اختبارات |

## عيوب حقيقية أُصلحت في هذه الجولة (إصلاح أدنى + اختبارات انحدار)

1. **bee_search MemoryEngine `search` يتجاهل ترقيم الصفحات** (`crates/bee_search/src/lib.rs`) — كان `from`/`size` المرسلان من طبقة gRPC يُتجاهلان، فيُعاد دائمًا كل النتائج. الإصلاح: يقرأ `from`/`size` من JSON الاستعلام ويطبق skip/truncate على النتائج، مع بقاء `total` يحسب كل التطابقات. جديد: `test_search_honors_from_size_pagination` (مقاوم للتكرار غير المرتب لـ HashMap: مقارنة بشريحة من النتيجة الكاملة للمحرك نفسه).
2. **social_grpc `search` يحوّل المعرفات غير الرقمية بصمت إلى 0** (`crates/social_grpc/src/search.rs:53-60`) — كان `h.id.parse().unwrap_or(0)` يعيد معرفات المستندات غير الرقمية بصمت كـ 0. الإصلاح: فشل التحليل يعيد `Status::invalid_argument`. جديد: `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb حقول line protocol في InfluxDB غير مرتبة** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags مرتبة لكن fields ليست كذلك؛ المخرجات غير حتمية مع حقول متعددة. الإصلاح: ترتيب fields حسب المفتاح. جديد: `line_protocol_is_deterministic_across_field_insertion_order` (ترتيبات إدراج مختلفة تنتج أسطرًا متطابقة، مرتبة حسب a,b).
4. **bee_search NDJSON المجمّع في Elasticsearch لا يهرب المعرفات** (`crates/bee_search/src/elasticsearch.rs`) — كان index/id يُدرجون نصًا خامًا؛ المعرفات التي تحتوي `"` تنتج NDJSON غير صالح. الإصلاح: استُخرج `bulk_ndjson()`، وسطور الإجراءات تُسلسل عبر serde_json. جديد: `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge` يبلغ دائمًا عن الطرف `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — عندما يكون الطرف المفقود هو `to`، تكون رسالة الخطأ مضللة. الإصلاح: عندما `nodes-matched < 2`، استخدم `get_vertex` لتحديد الطرف المفقود فعليًا قبل الإبلاغ. جديد: `add_edge_reports_the_missing_endpoint` (خدمة HTTP محاكاة تتحقق من الإبلاغ عن `to1` بدلًا من `from1`).
6. **bee_template توثيق `context!` غير متسق مع السلوك** (`crates/bee_template/src/lib.rs`) — يدّعي التوثيق أن الأعداد العائمة غير المنتهية تسبب panic، لكن serde_json في الواقع يserializeها كـ `null` (مثبت باختبار قائم). الإصلاح: تحديث التوثيق.

## تغطية جديدة

- **اختبارات تكامل bee_kv RedisStore مع Redis حقيقي** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — يسد الفجوة المسماة صراحة في التقرير السابق. Redis محلي متاح (127.0.0.1:6379)؛ 3 حالات: رحلة ذهاب وإياب set/get/del، incr/expire، mset/mget؛ المفاتيح ببادئة pid+نانو ثانية، والحالات تنظّف نفسها. إذا لم يتوفر Redis، تُتخطى الحالات بأناقة (تطبع SKIP وتنجح).

## فجوات التغطية (دون تغيير)

- **bee_tsdb IoTDB `write_batch` غير ذري** (`crates/bee_tsdb/src/iotdb.rs`) — كتابات نقطة بنقطة مع قصر الدارة `?`، غير متسقة مع «atomically» في توثيق trait. الإصلاح يتطلب دعم المعاملات من الخلفية؛ لا توجد نسخة IoTDB محلية، لذلك لا تغييرات عمياء هذه الجولة؛ مذكورة كقيود معروفة.
- **الواجهات الخلفية الخارجية** (es/opensearch/clickhouse، neo4j/nebulagraph/arangodb، influxdb/iotdb/questdb، memcached) — المشغّلات الرئيسية (elasticsearch، neo4j، influxdb: مسارات الكتابة/الاستعلام/CQ) مغطاة بخدمات HTTP محاكاة محلية؛ البقية دون خدمة محلية يُتحقق منها على مستوى الترجمة فقط.
- **MySQL**: محلي 127.0.0.1:3306 متاح (root، بدون كلمة مرور)، لكن لا يوجد crate في workspace يُدخل مشغّل MySQL — bee_orm منشئ SQL مستقل عن المشغّل، حالات QuerySet لا تعتمد على قاعدة بيانات حقيقية؛ لا حاجة لاعتماد مشغّل ولا ينبغي إضافته.
- **bee_config_macro / bee_orm_macro**: وحدات ماكرو إجرائية، مغطاة بشكل غير مباشر عبر اختبارات التكامل الخاصة بها، ولا توجد اختبارات وحدة مستقلة.

## فحص الجودة

- `cargo fmt --check`: ناجح (في هذه الجولة شُغّل `cargo fmt` على كامل workspace، مصححًا 20+ انحراف تنسيق خلفتها الجلسات السابقة).
- `cargo clippy --workspace --all-targets`: صفر تحذيرات في الكود الجديد؛ الثلاثة المتبقية تحذيرات سابقة الوجود (bee_config `get("default").is_none()`، bee_rust `unwrap()` على Ok، bee_search MemoryEngine بدون impl لـ Default)، خارج نطاق هذه الجولة.

## ملاحظات البيئة

- cargo موجود في `~/.cargo/bin` (ليس في PATH افتراضيًا)، يتطلب `export PATH="$HOME/.cargo/bin:$PATH"`.
- `protoc` متاح الآن (`/home/erik/.local/bin/protoc`).
- social_grpc يعمل في الخلفية (منفذ 50051)؛ هذا التقرير نفّذ `cargo test` فقط، دون `cargo run` عليه.
- Redis (6379) وMySQL (3306) متاحان محليًا؛ قائمة حالات الميزات:
  - `cargo test -p bee_tsdb --features influxdb` → 16 ناجحًا
  - `cargo test -p bee_search --features elasticsearch` → 20 ناجحًا
  - `cargo test -p bee_graph --features neo4j` → 20 ناجحًا
  - `cargo test -p bee_kv --features redis` → 13 ناجحًا (بما فيها 3 حالات Redis حقيقية)
