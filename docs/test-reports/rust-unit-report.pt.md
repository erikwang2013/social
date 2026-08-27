# Relatório de testes unitários do Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Data: 2026-08-27
- Local: `/home/wwwroot/social/infrastructure`
- Comando: `cargo test --workspace` (features padrão), além da verificação de backends com feature-gate (tsdb/graph/search/kv)
- Resultado: **183 aprovados / 0 reprovados** (178 unitários+de integração + 5 inline de feature + 1 doctest, etc.; o workspace padrão inclui os 6 casos de bee_search porque social_grpc depende da feature `elasticsearch` dele)

## Resumo

| crate | Casos de teste | Aprovados | Reprovados | Módulos cobertos |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | mapeamento rust_type, find_bin_name, validate_name, análise de argumentos bin |
| bee_config | 8 + 6 integração | 14 | 0 | IniParser (comentários/espaços/troca de seção), Config, ConfigSource, erros de recarga |
| bee_config_macro | 0 | — | — | coberto indiretamente via testes de integração |
| bee_graph | 15 | 15 | 0 | StubGraphDB: direção/profundidade/rótulos de travessia, add/update/delete, caminhos de erro, serde (feature neo4j +5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, chaves expiradas, caminhos de erro (feature redis +3 casos Redis reais) |
| bee_logs | 4 | 4 | 0 | level_str todos os níveis, saída de arquivo, saída stdout/stderr |
| bee_orm | 19 integração | 19 | 0 | SelectBuilder: order/limit/offset/vínculo de parâmetros/reutilização/table_name/Display de erros (0 na lib) |
| bee_orm_macro | 0 | — | — | coberto indiretamente via testes de integração |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), pipeline de dispatch, restauração/persistência/cookie expirado de sessão |
| bee_rust | 2 (bin +9) | 11 | 0 | exportações de prelude, alias de Result, análise de argumentos CLI |
| bee_search | 20 (incl. 6 inline de feature) | 20 | 0 | MemoryEngine: index/delete/sobrescrita/paginação/get/consulta vazia/serde; driver Elasticsearch: get/search/bulk/aggregate, escape NDJSON |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, caminhos de erro, unicidade de UUID |
| bee_template | 6 + 1 doctest | 7 | 0 | macro context!, render, erros de template/variável ausente, engine vazio, flutuantes não finitos (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | escritas/escritas em lote, limites de consulta de intervalo, filtragem Eq/Neq/Regex/AND, Point serde, CQ (feature influxdb +5, incl. determinismo do line protocol) |
| social_grpc | 6 | 6 | 0 | SearchService: ida e volta index/search/delete, fallback para JSON inválido, índice vazio, erro de id não numérico |
| hello_bee | 0 | — | — | programa de exemplo, sem testes |

## Defeitos reais corrigidos nesta rodada (fix mínimo + testes de regressão)

1. **bee_search MemoryEngine `search` ignora a paginação** (`crates/bee_search/src/lib.rs`) — o `from`/`size` enviado pela camada gRPC era descartado, devolvendo sempre todos os resultados. Fix: lê `from`/`size` do JSON de consulta e aplica skip/truncate sobre os hits, `total` continua contando todas as correspondências. Novo: `test_search_honors_from_size_pagination` (robusto contra a iteração desordenada de HashMap: compara com uma fatia do resultado completo da própria engine).
2. **social_grpc `search` converte silenciosamente ids não numéricos em 0** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` devolvia silenciosamente os ids de documento não numéricos como 0. Fix: a falha do parse devolve `Status::invalid_argument`. Novo: `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb campos line protocol do InfluxDB fora de ordem** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags estão ordenadas mas fields não; saída não determinística com múltiplos campos. Fix: ordenar fields por chave. Novo: `line_protocol_is_deterministic_across_field_insertion_order` (ordens de inserção diferentes produzem linhas idênticas, ordenadas por a,b).
4. **bee_search Elasticsearch bulk NDJSON sem escapar ids** (`crates/bee_search/src/elasticsearch.rs`) — index/id interpolados em claro; ids com `"` produziam NDJSON inválido. Fix: extraído `bulk_ndjson()`, linhas de ação serializadas via serde_json. Novo: `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge` sempre reporta o extremo `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — quando o extremo ausente é `to`, a mensagem de erro induz em erro. Fix: quando `nodes-matched < 2`, usar `get_vertex` para determinar o extremo realmente ausente antes de reportar. Novo: `add_edge_reports_the_missing_endpoint` (serviço HTTP simulado verifica que reporta `to1` em vez de `from1`).
6. **bee_template doc `context!` inconsistente com o comportamento** (`crates/bee_template/src/lib.rs`) — a doc afirma que os flutuantes não finitos geram panic, mas serde_json os serializa como `null` (comprovado por um teste existente). Fix: doc atualizada.

## Nova cobertura

- **Testes de integração bee_kv RedisStore com Redis real** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — preenche a lacuna de cobertura nomeada explicitamente no relatório anterior. Redis local disponível (127.0.0.1:6379); 3 casos: ida e volta set/get/del, incr/expire, mset/mget; chaves com prefixo pid+nanossegundos, os casos se limpam sozinhos. Se o Redis não estiver disponível, os casos são pulados elegantemente (imprimem SKIP e passam).

## Lacunas de cobertura (sem mudanças)

- **bee_tsdb IoTDB `write_batch` não atômico** (`crates/bee_tsdb/src/iotdb.rs`) — escritas ponto a ponto com curto-circuito `?`, inconsistente com o «atomically» da doc do trait. O fix requer suporte transacional do backend; sem instância IoTDB local, esta rodada não faz mudanças às cegas; listado como limitação conhecida.
- **Backends externos** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — os principais drivers (elasticsearch, neo4j, influxdb: caminhos de escrita/consulta/CQ) estão cobertos com serviços HTTP simulados locais; o restante sem serviço local só tem verificação em nível de compilação.
- **MySQL**: local 127.0.0.1:3306 disponível (root, sem senha), mas nenhum crate do workspace introduz um driver MySQL — bee_orm é um construtor SQL independente de driver, os casos QuerySet não dependem de uma base real; nenhuma dependência de driver é necessária nem deve ser adicionada.
- **bee_config_macro / bee_orm_macro**: proc-macros, cobertos indiretamente via seus testes de integração, sem testes unitários independentes.

## Controle de qualidade

- `cargo fmt --check`: aprovado (esta rodada executou `cargo fmt` em todo o workspace, corrigindo 20+ desvios de formatação deixados por sessões anteriores).
- `cargo clippy --workspace --all-targets`: zero avisos no código novo; os 3 restantes são avisos pré-existentes (bee_config `get("default").is_none()`, bee_rust `unwrap()` sobre Ok, bee_search MemoryEngine sem impl Default), fora do escopo desta rodada.

## Notas de ambiente

- cargo está em `~/.cargo/bin` (não no PATH por padrão), requer `export PATH="$HOME/.cargo/bin:$PATH"`.
- `protoc` já está disponível (`/home/erik/.local/bin/protoc`).
- social_grpc roda em segundo plano (porta 50051); este relatório apenas executou `cargo test`, não `cargo run` nele.
- Redis (6379) e MySQL (3306) disponíveis localmente; lista de casos de feature:
  - `cargo test -p bee_tsdb --features influxdb` → 16 aprovados
  - `cargo test -p bee_search --features elasticsearch` → 20 aprovados
  - `cargo test -p bee_graph --features neo4j` → 20 aprovados
  - `cargo test -p bee_kv --features redis` → 13 aprovados (incl. 3 casos Redis reais)
