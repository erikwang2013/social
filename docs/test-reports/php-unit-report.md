# PHP 单元测试报告
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- 日期: 2026-08-27
- 执行: `vendor/bin/phpunit`(PHPUnit 10.5.64, PHP 8.3.7)
- 范围: admin/(webman 后台) + service/(webman 主服务)

## 结论总览

| 项目 | 用例 | 断言 | 结果 |
|------|------|------|------|
| service | 136 | 348 | ✅ 全部通过(OK) |
| admin | 67 | 180 | ✅ 全部通过(OK) |

## 环境说明

- MySQL 127.0.0.1:3306(root,空密码),库 `social`(social_*)与 `open_admin`(erik_*)已建好并灌有数据(super_admin 角色、39 条权限)
- Redis 127.0.0.1:6379 运行中(验证码存储 `poster:captcha:*`);Elasticsearch 未启动(健康检查按 unavailable 降级,不视为失败)
- service 在 8788、admin 在 8791 运行中
- service 与 admin 均无 `.env`(仓库已移除误入库的 env,commit e5379fc),应用依赖 `config/*.php` 中 `getenv('X') ?: 默认值` 兜底运行
- **Imagick 扩展已加载但缺 `RESOURCETYPE_PIXELS` 常量**(本机构建仅有 RESOURCETYPE_* 新常量集),poster-php 的 ImagickDriver 构造时引用该常量即崩

## service(136/136 全绿)

- 与上批次基线一致,覆盖: 认证/中间件/JWT、用户、帖子、评论、关注、通知、搜索同步、IM、房间、通话(CallCenter/CallState)、语音、模型关系、动作处理(WS)
- 本批次无代码改动、无失败

## admin(上批次 49/60 → 本批次 67/67 全绿)

### 修复:真实代码缺陷(1 处)

| 位置 | 根因 | 修复 |
|------|------|------|
| `config/poster.php` | `image.driver` 默认 `auto`,DriverFactory 检测到 Imagick 扩展即选 ImagickDriver,而本机 Imagick 缺 `RESOURCETYPE_PIXELS` 常量 → 验证码生成/海报直接 500(线上服务同样受影响) | 驱动检测增加常量守卫:`getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`,常量缺失自动回退 GD |

### 修复:过期断言(核对当前代码后更新)

| 测试文件 | 用例 | 根因 | 修正 |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv(4 失败+1 错误) | 断言 `.env`/`.env.example` 存在及 getenv 有值;但仓库已移除 env 文件且不可重建 | 重写为"无 .env 运行"契约:每个 `getenv()` 键必须有 `?:` 默认值兜底、默认配置指向本地服务(127.0.0.1:3306/open_admin)、关键配置类型正确 |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser 已不用 Searchable trait(改用 `Erikwang2013\Encryptable\Encryptable` 做字段透明加解密;`toSearchableArray()` 方法保留) | 改为断言 Encryptable trait;toSearchableArray 断言本就通过,保留 |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` 已改为 `'@'` 全局组键格式,顶层数组不再直接包含中间件类 | 断言改为检查 `$middlewares['@']` 包含 Cors 与 RateLimit |
| CaptchaTest | 全部 7 用例(原 6 错误+1 失败) | 双重过期:(a) Imagick 常量缺失(已由 poster.php 修复);(b) 断言基于旧版 poster-php 契约——`extra.targets`(含 x/y)已改为 `extra.texts`(仅 text+order),坐标只存存储层;验证点击格式由 `['x'=>, 'y'=>]` 改为 `[x, y]` 数字对 | 按当前契约重写:结构/难度数量(2/3/4)/字段校验,正确点击从 Redis(`poster:captcha:{key}` 的 `data.targets`)读取坐标验证,错误点击失败,超 max_attempts(3)后 key 被消费删除,key 唯一性 |

### 新增测试(1 个文件,12 用例)

`tests/AdminControllerTest.php`(带版权头),覆盖:

- **BaseController::decodeId**(刚修复的 404 行为):encode/decode 往返一致;非法 hashid 抛 `support\exception\NotFoundException` 且 code=404;encodeIds 只改写 ID 字段
- **RoleController**:super_admin 角色 update 返回 403(真实 DB 数据)
- **PermissionController::buildTree**:权限树嵌套(2 层)+ 节点 id 全部 hashid 化
- **ConfigController**:缺 group/key/value 时校验返回 422;非法 hashid 抛 404
- **ExportController**:`admin_user` 导出敏感字段清单为 phone/email/id_card(其余表为空);PDF HTML 对标题/单元格值做 htmlspecialchars 转义(防 XSS)且包含版权声明

### 已知说明

- 测试中构造的 webman Request 以原始 HTTP 报文(buffer)传入(workerman Request 构造参数为 buffer,仅传 method/uri 无法解析 POST 体),详见 AdminControllerTest 注释
- 验证码正确点击用例依赖 Redis 读取存储目标;Redis 不可用时该用例 markTestSkipped,不影响套件结果

## 未覆盖/待补

- admin 各 model 的 Encryptable 加解密、OperationLog/AdminPermission 中间件与 RBAC 缓存路径仍缺单测,建议由 API 测试或后续批次覆盖
- service 中依赖外部服务(ES/gRPC)的路径仍为单元级 stub 验证,集成级由 API 测试覆盖
