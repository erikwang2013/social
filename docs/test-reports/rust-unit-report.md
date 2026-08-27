# Rust Workspace 单元测试报告

- 日期：2026-08-27
- 位置：`/home/wwwroot/social/infrastructure`
- 命令：`cargo test --workspace`（默认 feature），另单独验证 feature 后端（tsdb/graph/search/kv）
- 结果：**183 通过 / 0 失败**（178 单测+集成 + 5 feature 内联 + 1 doctest 等；默认 workspace 因 social_grpc 依赖 bee_search 的 `elasticsearch` feature 而包含其 6 个用例）

## 汇总

| crate | 用例数 | 通过 | 失败 | 覆盖模块 |
|-------|------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire、incr、ttl、MemoryCache |
| bee_cli | 14 | 14 | 0 | rust_type 映射、find_bin_name、validate_name、bin 参数解析 |
| bee_config | 8 + 6 集成 | 14 | 0 | IniParser（注释/空白/分节切换）、Config、ConfigSource、重载错误 |
| bee_config_macro | 0 | — | — | 经集成测试间接覆盖 |
| bee_graph | 15 | 15 | 0 | StubGraphDB：遍历方向/深度/labels、增删改、错误路径、serde（feature neo4j 另 5） |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset、过期键、错误路径（feature redis 另 3 个真实 Redis 用例） |
| bee_logs | 4 | 4 | 0 | level_str 全级别、文件输出、stdout/stderr 输出 |
| bee_orm | 19 集成 | 19 | 0 | SelectBuilder：order/limit/offset/参数绑定/复用/表名/错误 Display（lib 内 0） |
| bee_orm_macro | 0 | — | — | 经集成测试间接覆盖 |
| bee_router | 36 | 36 | 0 | context（params/text/html/abort）、router（方法/404/namespace）、分发管线、会话恢复/持久化/过期 cookie |
| bee_rust | 2（bin 另 9） | 11 | 0 | prelude 导出、Result 别名、CLI 参数解析 |
| bee_search | 20（含 feature 内 6） | 20 | 0 | MemoryEngine：index/delete/覆盖/分页/get/空查询/serde；Elasticsearch 驱动：get/search/bulk/aggregate、NDJSON 转义 |
| bee_session | 8 | 8 | 0 | set/get/delete、save/load/refresh、TTL 下限、错误路径、UUID 唯一性 |
| bee_template | 6 + 1 doctest | 7 | 0 | context! 宏、render、缺失模板/变量错误、空引擎、非有限浮点（doctest 1） |
| bee_tsdb | 11 | 11 | 0 | 写入/批量写入、范围查询边界、Eq/Neq/Regex/AND 过滤、Point serde、CQ（feature influxdb 另 5，含 line protocol 确定性） |
| social_grpc | 6 | 6 | 0 | SearchService：index/search/delete 往返、非法 JSON 回退、空索引、非数字 id 报错 |
| hello_bee | 0 | — | — | 示例程序，无测试 |

## 本次修复的真实缺陷（最小修复 + 回归测试）

1. **bee_search MemoryEngine `search` 忽略分页**（`crates/bee_search/src/lib.rs`）— gRPC 层传入的 `from`/`size` 被丢弃，永远返回全部命中。修复：读取 query JSON 中 `from`/`size` 并对 hits 做 skip/truncate，`total` 仍为全部匹配数。新增 `test_search_honors_from_size_pagination`（对 HashMap 无序迭代稳健：与引擎自身全量结果做切片对比）。
2. **social_grpc `search` 非数字 id 静默变 0**（`crates/social_grpc/src/search.rs:53-60`）— `h.id.parse().unwrap_or(0)` 会把非数字文档 id 悄悄当 0 返回。修复：parse 失败返回 `Status::invalid_argument`。新增 `non_numeric_hit_id_becomes_invalid_argument`。
3. **bee_tsdb InfluxDB line protocol 字段乱序**（`crates/bee_tsdb/src/influxdb.rs:160-170`）— tags 已排序而 fields 未排序，多字段时输出不确定。修复：fields 按键排序。新增 `line_protocol_is_deterministic_across_field_insertion_order`（不同插入顺序产出相同行，且按 a,b 排序）。
4. **bee_search Elasticsearch bulk NDJSON 未转义 id**（`crates/bee_search/src/elasticsearch.rs`）— index/id 直接字符串插值，含 `"` 的 id 产生畸形 NDJSON。修复：抽取 `bulk_ndjson()`，action 行经 serde_json 序列化。新增 `bulk_ndjson_escapes_ids_and_stays_parseable`。
5. **bee_graph Neo4j `add_edge` 报错端点恒为 `from`**（`crates/bee_graph/src/neo4j.rs:107-116`）— 当缺失端是 `to` 时错误信息误导。修复：`nodes-matched < 2` 时用 `get_vertex` 判定实际缺失端点再报错。新增 `add_edge_reports_the_missing_endpoint`（mock HTTP 服务验证报 `to1` 而非 `from1`）。
6. **bee_template `context!` 文档与行为不符**（`crates/bee_template/src/lib.rs`）— 文档称非有限浮点会 panic，实际 serde_json 序列化为 `null`（已有测试证明）。修复：更新文档说明。

## 新增覆盖

- **bee_kv RedisStore 真实 Redis 集成测试**（`crates/bee_kv/src/redis_store.rs`，feature `redis`）— 补齐旧报告明确的覆盖缺口。本地 Redis 可用（127.0.0.1:6379），3 个用例：set/get/del 往返、incr/expire、mset/mget；键带 pid+纳秒前缀，用例自行清理。Redis 不可用时优雅跳过（打印 SKIP 并放行）。

## 覆盖缺口（维持现状）

- **bee_tsdb IoTDB `write_batch` 非原子**（`crates/bee_tsdb/src/iotdb.rs`）— 逐点 `?` 短路写入，与 trait 文档"atomically"不符。修复需后端事务支持，无本地 IoTDB 实例，本次不做盲改，列为已知限制。
- **外部后端**（es/opensearch/clickhouse、neo4j/nebulagraph/arangodb、influxdb/iotdb/questdb、memcached）— 已用本地 mock HTTP 服务覆盖主要驱动（elasticsearch、neo4j、influxdb 的写/查/CQ 路径），其余无本地服务的仅编译级验证。
- **MySQL**：本地 127.0.0.1:3306 可用（root 空密码），但 workspace 内无任何 crate 引入 MySQL 驱动——bee_orm 是驱动无关的 SQL 构造器，QuerySet 用例不依赖真实数据库，无需也不应新增驱动依赖。
- **bee_config_macro / bee_orm_macro**：proc macro，经各自集成测试间接覆盖，无独立单测。

## 质量检查

- `cargo fmt --check`：通过（本次对全 workspace 执行 `cargo fmt`，修复了包括先前会话遗留的 20+ 处格式偏差）。
- `cargo clippy --workspace --all-targets`：新代码零警告；残留 3 处为既有代码警告（bee_config `get("default").is_none()`、bee_rust `unwrap()` on Ok、bee_search MemoryEngine 缺 Default impl），未在本次范围。

## 环境备注

- cargo 位于 `~/.cargo/bin`（默认不在 PATH），需 `export PATH="$HOME/.cargo/bin:$PATH"`。
- `protoc` 已可用（`/home/erik/.local/bin/protoc`）。
- social_grpc 在后台运行（端口 50051），本报告仅执行 `cargo test`，未对其 `cargo run`。
- Redis（6379）与 MySQL（3306）本机可用；feature 用例清单：
  - `cargo test -p bee_tsdb --features influxdb` → 16 通过
  - `cargo test -p bee_search --features elasticsearch` → 20 通过
  - `cargo test -p bee_graph --features neo4j` → 20 通过
  - `cargo test -p bee_kv --features redis` → 13 通过（含 3 个真实 Redis 用例）
