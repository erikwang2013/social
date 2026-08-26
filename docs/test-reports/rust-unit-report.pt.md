# Relatório de testes unitários do Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Data: 2026-08-27
- Local: `/home/wwwroot/social/infrastructure`
- Comando: `cargo test --workspace` (features padrão), além da verificação de backends com feature-gate (tsdb/graph/search/kv)
- Resultado: **180 aprovados / 0 reprovados** (179 testes unitários+de integração + 1 doctest)

## Resumo

| crate | Casos de teste | Aprovados | Reprovados | Módulos cobertos |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | mapeamento rust_type, find_bin_name, validate_name, análise de argumentos bin |
| bee_config | 14 | 14 | 0 | IniParser (comentários/espaços/troca de seção), Config, ConfigSource, 6 integração |
| bee_config_macro | 0 | — | — | coberto indiretamente via testes de integração |
| bee_graph | 15 | 15 | 0 | StubGraphDB: direção/profundidade/rótulos de travessia, add/update/delete, caminhos de erro, serde (backend feature +29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, chaves expiradas, caminhos de erro |
| bee_logs | 4 | 4 | 0 | level_str todos os níveis, saída de arquivo, saída stdout/stderr |
| bee_orm | 19 | 19 | 0 | SelectBuilder (integração): order/limit/offset/vínculo de parâmetros/reutilização/table_name/exibição de erros (0 na lib) |
| bee_orm_macro | 0 | — | — | coberto indiretamente via testes de integração |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), pipeline de dispatch, restauração/persistência/cookie expirado de sessão |
| bee_rust | 2 | 2 | 0 | exportações de prelude, alias de Result |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/sobrescrita/paginação/get/consulta vazia/serde (backend feature +20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, caminhos de erro, unicidade de UUID |
| bee_template | 6+1 | 7 | 0 | macro context!, render, erros de template/variável ausente, engine vazio, floats não finitos (incl. 1 doctest) |
| bee_tsdb | 10 | 10 | 0 | filtragem de Query (Neq/Regex/intervalo/AND), Point serde, enum debug (backend feature +22) |
| social_grpc | 5 | 5 | 0 | SearchService: ida e volta index/search/delete, fallback para JSON inválido, índice vazio |
| hello_bee | 0 | — | — | programa de exemplo, sem testes |

## Lista de não coberto

- **Feature `redis` do bee_kv (RedisStore)**: requer um servidor Redis ativo, não coberto
- **hello_bee**: programa de exemplo, 0 testes
- **Backends com feature-gate** (não compilados com features padrão): verificados como compiláveis e com testes aprovados em suas respectivas combinações de features (tsdb 22, graph 29, search 20, kv 10), mas os backends reais es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis exigem serviços externos — apenas verificação em nível de compilação
- **bee_config_macro / bee_orm_macro**: proc-macros, cobertos indiretamente via seus testes de integração, sem testes unitários independentes

## Bugs reais documentados (código-fonte das bibliotecas não modificado)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` itera `&point.fields` (HashMap) sem ordenação, enquanto tags são ordenadas → a saída de line protocol é não determinística com múltiplos campos
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` não é atômico (execução ponto a ponto com curto-circuito `?`), inconsistente com o "atomically" declarado na doc do trait
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` sempre retorna `VertexNotFound(edge.from)`, mesmo quando o ponto de extremidade ausente é `to`
4. `bee_search` MemoryEngine `search` — ignora o from/size recebido da camada gRPC (sem paginação)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: um id não numérico vira silenciosamente 0
6. Documentação da macro `context!` em `bee_template/src/lib.rs` — afirma que NaN gera panic, mas na verdade serde_json ≥1.0.128 o serializa como null (doc desatualizada)
7. `bee_search/src/elasticsearch.rs:64` — o NDJSON bulk interpola index/id brutos no JSON; ids contendo `"` produzem NDJSON inválido

## Notas de ambiente

- cargo está em `~/.cargo/bin` (fora do PATH), requer `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc requer `protoc`: obtido via `apt-get download protobuf-compiler` + `dpkg-deb -x` extraído para `/tmp/protoc-local`, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (sem necessidade de sudo)
