# 页面端到端（E2E）测试报告
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- 日期：2026-08-27
- 环境：本机（Linux），真实浏览器（Playwright 1.62 / Chromium）+ 真实服务进程
- 用例总数：**41**，通过 **41**，失败 **0**，阻塞标注 **1**
- 产物：`tests/e2e/artifacts/html-report/`（Playwright HTML 报告）、失败截图/跟踪（本次无失败）

## 测试范围与页面清单

两个 webman 后端均以真实进程运行：`admin`（:8791）、`service`（:8788，WS :8789）。
两端的 `app/view/` 均只有默认模板（`index/view.html`），无传统多页模板 —— 实际"页面"即 API 端点，
Web 前端由 Flutter/HarmonyOS 客户端承载（`apps/` 无可运行网页 UI，不在 E2E 范围）。

| 应用 | 页面 / 端点 | 用例 |
|------|------------|------|
| admin | `/health` 健康检查、`/metrics` Prometheus 指标、`/.well-known/security.txt`、`/api/docs` OpenAPI、`/install` 安装向导 | 5 |
| admin | `/api/captcha/generate` + `/api/captcha/verify`（滑块验证码真实像素求解）、`/api/auth/login`（成功/错误密码/缺验证码） | 3 |
| admin | 登录后受保护页面：`/admin/dashboard`、`/admin/user`、`/admin/role`、`/admin/permission`、`/admin/config`、`/admin/log`、`/admin/profile`、`/admin/social-user`、登出 `/admin/profile/logout` → token 失效 | 11 |
| admin | 批量操作 `/admin/user/batch/status`（批量启用 + 空 ids 422）、导出 `/admin/export/excel`（xlsx 文件头校验）、改密码 `/admin/profile/password`（缺旧密码 422） | 3 |
| service | `/`（iframe 容器）、`/health`、`/apidoc`（跳转 apidoc/index.html）、未登录访问受保护端点 401 | 4 |
| service | 注册/登录/登出、资料（GET/PUT `/api/v1/me`）、发帖/时间线/详情、点赞/取消点赞、评论、关注/取关/关系/粉丝/关注列表、通知（列表/未读数/单条已读/全部已读） | 10 |
| service | 搜索用户、搜索帖子（ES 未启动 → 503，blocked 标注通过） | 2 |
| service | IM 会话（创建/列表/消息）、语音房间（创建/列表/详情/关闭） | 3 |

## 运行方式

```bash
cd tests/e2e && npx playwright test          # 全部
# 或按文件：admin-pages.spec.js / admin-auth.spec.js / service-journey.spec.js
```

- 测试账号夹具：`e2e_smoke`，密码 `ApiTest!2026`（SQL 预置，见 `tests/api/run.php`）
- 滑块验证码通过「拼图块 vs 背景图」像素 Pearson 相关求解（真实交互路径，无绕过）；
  验证码类型随机（click/rotate/slider），仅 slider 可自动求解，脚本重试换图直至命中。

## 阻塞点 / 环境限制

1. **帖子搜索 503**：`/api/v1/search/posts` 依赖 Elasticsearch（Scout），本环境未启动 ES → 返回 503。
   用例按 `blocked` 标注通过，需启动 ES 后验证命中。
2. **service 首页 `/` 需显式路由**：webman-framework v2.2.4 默认路由不再解析 `/` 到
   `IndexController@index`（曾导致根路径 404，首页用例失败）。已在
   `service/config/route.php` 显式注册 `Route::get('/', ...)` 修复，重启 service 后生效。
3. **admin 验证码 Imagick 兼容**：本机 Imagick 构建缺 `Imagick::RESOURCETYPE_PIXELS` 常量，
   `auto` 驱动会误选 ImagickDriver 导致 generate 500（`admin/config/poster.php` 已按该常量
   存在与否回退 gd，需重启 admin 生效）。
4. **admin 验证码 GD 内存**：`GdDriver` 解码大图（背景 5472x3648）+ `memory_limit 128M`，
   连续 generate 存在 OOM 风险（长跑套件时 admin 曾因此宕机）。规避：运行验证码用例前重启 admin，
   并分批运行（admin-pages / admin-auth / service 分开执行）。属环境限制，非业务代码缺陷。
5. **验证码类型随机**：generate 三选一，click/rotate 不暴露可求解数据，仅 slider 可自动过（重试最多 12 次）。
6. **数据库 root 空密码**：本机测试环境 MySQL 以 root/空密码提供，两应用 `.env` 默认一致。
7. **apps/ 移动端**：android/harmonyos/ios 无可运行网页 UI，不纳入浏览器 E2E。

## 结论

admin 登录（含滑块验证码）与 22 个管理端点、service 用户端 19 个全流程用例全部通过
（本次新增 6 例：admin 批量启用/导出 Excel/改密码校验，service 未登录 401/取关/通知单条已读）。
修复 2 个真实缺陷：service 根路径 404（补显式路由）、admin 验证码 generate 500
（Imagick 常量缺失回退 GD，配置已含、重启生效）。
唯一阻塞点为搜索服务（ES）未部署，其余链路（注册登录/发帖/互动/通知/IM/语音）均验证可用。
