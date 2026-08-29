# M6c CDN 接入设计 — 全球主流 CDN 云服务商，管理端可配置

日期：2026-08-29 · 里程碑：M6c · 状态：已确认

## 目标

- 图片/文件上传从本地 `public/upload/` 迁移到对象存储 + CDN，全球加速
- 支持 S3 兼容全家桶：AWS S3、Cloudflare R2、阿里 OSS、腾讯 COS、Backblaze B2（全部兼容 S3 API，一套 SDK 通吃）
- CDN 服务商在管理端可配置、可管理（增删改查 + 切换活动服务商），不依赖 .env
- 语音文件（Rust VoiceStorage）同步迁桶

## 需求决策（已确认）

| 项 | 决策 |
|----|------|
| 服务商 | S3 兼容全家桶，驱动抽象层，一套 SDK |
| 上传链路 | 服务端中转 + 水印（水印是现有功能，保留） |
| 范围 | 全量静态文件：图片、语音、后台文件 |
| 访问控制 | 公开读（文件名随机 md5，不可枚举）；admin 导出为即时同步下载，从不落盘，无需处理 |
| 管理 | 管理端 CRUD + 激活切换，配置存 DB |

## 架构

```
管理端 Flutter 页 ──▶ StorageProviderController ──▶ erik_storage_provider 表
                                                        │ 活动服务商（Redis 60s 缓存）
上传链路：
客户端 ─multipart─▶ PHP(校验+水印) ─put─▶ 活动服务商 S3 桶 ◀── Rust VoiceStorage（读活动配置）
                    │                                        ▲
                    └────── 返回 CDN URL ◀─────── CDN ───────┘
旧文件：nginx 继续服务本地 public/upload（历史 URL 零迁移零中断）
```

- PHP 侧：`Storage` 薄封装（`put(string $key, string $bytes): string`），内部 `aws/aws-sdk-php` S3Client，endpoint 覆盖兼容所有厂商；驱动配置从 DB 活动服务商读取（Redis 60s 缓存，服务端已有同模式）
- Rust 侧：`bee_live::upload::VoiceStorage` 内部从本地磁盘换成 `object_store` crate 的 AmazonS3；活动服务商配置直接查 MySQL（bee_live 已有 `mysql_async` 依赖）；对外 API 形状不变，PHP gRPC 代理链路一行不改
- 新依赖：`aws/aws-sdk-php`（PHP）、`object_store`（Rust）
- 新表：`erik_storage_provider`（snowflake id；secret 字段用现有 encryptable 加密）

### 表结构

```sql
CREATE TABLE erik_storage_provider (
  id         BIGINT UNSIGNED NOT NULL COMMENT 'snowflake 主键',
  name       VARCHAR(50)  NOT NULL COMMENT '服务商名称（展示用）',
  driver     VARCHAR(10)  NOT NULL DEFAULT 's3' COMMENT 'local|s3',
  endpoint   VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'S3 endpoint（R2/OSS/COS/B2 各自地址）',
  region     VARCHAR(50)  NOT NULL DEFAULT 'auto',
  key        VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'AccessKey（encryptable 加密）',
  secret     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SecretKey（encryptable 加密）',
  bucket     VARCHAR(100) NOT NULL DEFAULT '',
  cdn_url    VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'CDN 公开读域名',
  enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  is_active  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '活动服务商（唯一 1）',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) COMMENT='CDN 存储服务商';
```

种子数据：一条「本地存储」服务商（driver=local, is_active=1）——开发/测试零配置，与现状行为一致。

### 管理端 API（StorageProviderController，遵循现有 BaseController/权限模式）

- `GET    /admin/storage/providers` — 列表
- `POST   /admin/storage/providers` — 新建
- `PUT    /admin/storage/providers/{id}` — 更新
- `DELETE /admin/storage/providers/{id}` — 删除（活动服务商禁止删除）
- `POST   /admin/storage/providers/{id}/activate` — 设为活动（先验签连接，失败返回错误）
- 权限种子：`get.admin/storage/providers` 等 5 项，追加到 install.sql 权限种子 + migration
- Flutter 管理页：`storage/` 列表页 + 新建/编辑对话框 + 激活按钮，遵循现有页面模式（GetX + ApiService + DataTable）

### 数据流

- **上传**：客户端 multipart → PHP 校验 + 水印（不变）→ `Storage::put("upload/{Y-m-d}/{md5}.{ext}", bytes)` → 返回 `local ? "/upload/..." : "{cdn_url}/upload/..."`
- **读取**：图片/文件公开直读；语音仍走 PHP→Rust gRPC 代理（白名单校验保留），Rust 从桶读字节回传
- **旧文件**：历史相对 URL 由 nginx 继续服务本地目录；可选批量迁移脚本（M6c5，不做不影响上线）
- **缓存**：文件名随机 md5、无覆盖写，CDN 永不需要 purge

### 错误处理

- put 失败返回 500 + lang_key，不回退本地（双源不一致比失败更糟）
- activate 时验签连接，配错立即反馈
- 活动服务商不可删；不可用状态由 enabled 控制

## 测试

- Storage 单测：local 驱动读写 + key 拼接；s3 驱动用 aws-sdk-php MockHandler
- 控制器测试：断言返回 URL 与活动服务商一致（local→相对 / s3→绝对 CDN URL）
- admin 测试：StorageProviderController CRUD + activate 验签失败分支
- Rust：VoiceStorage ingest/roundtrip 测试改用内存/本地 object_store 路径
- 可选：配置好 .env 后 `tests/storage_e2e.php` 真传真读冒烟

## 阶段（团队流水线执行）

- **M6c1**：DB 表 + 种子 + admin API + 权限 + Flutter 管理页 + admin 测试
- **M6c2**：PHP Storage 驱动 + 两处上传接入（service ImageController、admin UploadController）+ 单测
- **M6c3**：Rust VoiceStorage 换 object_store + 语音 e2e
- **M6c4**：README/13 语言文档同步 + 冒烟 + 收尾

## 明确不做

- 不做 flysystem 抽象层（只用 put/get，多一层无收益）
- 不做客户端直传/预签名直传（水印必须服务端）
- 不做 CDN purge（无覆盖写）
- 不做旧文件迁移（nginx 兼容，M6c5 可选）
