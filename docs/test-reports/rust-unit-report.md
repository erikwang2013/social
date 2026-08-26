# Rust Workspace 单元测试报告
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- 日期：2026-08-27
- 位置：`/home/wwwroot/social/infrastructure`
- 命令：`cargo test --workspace`（默认 features），外加 feature-gated 后端验证（tsdb/graph/search/kv）
- 结果：**180 通过 / 0 失败**（179 单元+集成测试 + 1 doctest）

## 汇总

| crate | 用例数 | 通过 | 失败 | 覆盖模块 |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire、incr、ttl、MemoryCache |
| bee_cli | 23 | 23 | 0 | rust_type 映射、find_bin_name、validate_name、bin 参数解析 |
| bee_config | 14 | 14 | 0 | IniParser（注释/空白/节切换）、Config、ConfigSource、6 integration |
| bee_config_macro | 0 | — | — | 通过 integration tests 间接覆盖 |
| bee_graph | 15 | 15 | 0 | StubGraphDB：遍历方向/深度/标签、add/update/delete、错误路径、serde（feature 后端另 29） |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset、过期键、错误路径 |
| bee_logs | 4 | 4 | 0 | level_str 全级别、file output、stdout/stderr output |
| bee_orm | 19 | 19 | 0 | SelectBuilder（集成）：order/limit/offset/参数绑定/复用/table_name/错误 display（lib 内 0 个） |
| bee_orm_macro | 0 | — | — | 通过 integration tests 间接覆盖 |
| bee_router | 36 | 36 | 0 | context（params/text/html/abort）、router（method/404/namespace）、dispatch 流水线、会话恢复/持久化/过期 cookie |
| bee_rust | 2 | 2 | 0 | prelude 导出、Result 别名 |
| bee_search | 18 | 18 | 0 | MemoryEngine：index/delete/覆盖写/分页/get/空查询/serde（feature 后端另 20） |
| bee_session | 8 | 8 | 0 | set/get/delete、save/load/refresh、TTL floor、错误路径、UUID 唯一性 |
| bee_template | 6+1 | 7 | 0 | context! 宏、render、缺失模板/变量错误、空 engine、非有限浮点（含 1 doctest） |
| bee_tsdb | 10 | 10 | 0 | Query 过滤（Neq/Regex/范围/AND）、Point serde、枚举 debug（feature 后端另 22） |
| social_grpc | 5 | 5 | 0 | SearchService：index/search/delete 往返、无效 JSON 回退、空索引 |
| hello_bee | 0 | — | — | 示例程序，无测试 |

## 未覆盖清单

- **bee_kv `redis` feature（RedisStore）**：需要 live Redis server，未覆盖
- **hello_bee**：示例程序，0 测试
- **feature-gated 后端**（默认 features 不编译）：已通过各自 feature 组合验证可编译且测试通过（tsdb 22、graph 29、search 20、kv 10），但 es/opensearch/clickhouse、neo4j/nebulagraph/arangodb、influxdb/iotdb/questdb、redis 等真实后端需外部服务，仅编译级验证
- **bee_config_macro / bee_orm_macro**：proc-macro，通过各自 integration tests 间接覆盖，无独立单元测试

## 记录的真实 Bug（未修改库源码）

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` 迭代 `&point.fields`（HashMap）时未排序，而 tags 已排序 → 多 field 时 line protocol 输出不确定
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` 非原子（逐点执行并 `?` 短路），与 trait 文档声称的 "atomically" 不符
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` 总是返回 `VertexNotFound(edge.from)`，即使缺失的是 `to` 端点
4. `bee_search` MemoryEngine `search` — 忽略 gRPC 层传入的 from/size（无分页）
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`：非数字 id 静默变成 0
6. `bee_template/src/lib.rs` `context!` 宏文档 — 声称 NaN 会 panic，实际 serde_json ≥1.0.128 序列化为 null（文档过时）
7. `bee_search/src/elasticsearch.rs:64` — bulk NDJSON 将 index/id 原始插值进 JSON；id 含 `"` 时产生错误 NDJSON

## 环境备注

- cargo 位于 `~/.cargo/bin`（不在 PATH），需 `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc 需 `protoc`：通过 `apt-get download protobuf-compiler` + `dpkg-deb -x` 解压到 `/tmp/protoc-local`，`PROTOC=/tmp/protoc-local/usr/bin/protoc`（无需 sudo）
