# Rapport de tests unitaires Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Date : 2026-08-27
- Emplacement : `/home/wwwroot/social/infrastructure`
- Commande : `cargo test --workspace` (features par défaut), plus vérification des backends sous feature (tsdb/graph/search/kv)
- Résultat : **180 réussis / 0 échoué** (179 tests unitaires+d'intégration + 1 doctest)

## Récapitulatif

| crate | Cas de test | Réussis | Échoués | Modules couverts |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | mapping rust_type, find_bin_name, validate_name, analyse des arguments bin |
| bee_config | 14 | 14 | 0 | IniParser (commentaires/espaces/changement de section), Config, ConfigSource, 6 integration |
| bee_config_macro | 0 | — | — | couvert indirectement via les tests d'intégration |
| bee_graph | 15 | 15 | 0 | StubGraphDB : direction/profondeur/étiquettes de traversée, add/update/delete, chemins d'erreur, serde (backend feature +29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, clés expirées, chemins d'erreur |
| bee_logs | 4 | 4 | 0 | level_str tous niveaux, sortie fichier, sortie stdout/stderr |
| bee_orm | 19 | 19 | 0 | SelectBuilder (intégration) : order/limit/offset/liaison de paramètres/réutilisation/table_name/affichage des erreurs (0 dans lib) |
| bee_orm_macro | 0 | — | — | couvert indirectement via les tests d'intégration |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), pipeline de dispatch, restauration/persistance/cookie expiré de session |
| bee_rust | 2 | 2 | 0 | exports prelude, alias Result |
| bee_search | 18 | 18 | 0 | MemoryEngine : index/delete/écrasement/pagination/get/requête vide/serde (backend feature +20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, chemins d'erreur, unicité des UUID |
| bee_template | 6+1 | 7 | 0 | macro context!, render, erreurs de template/variable manquante, engine vide, flottants non finis (dont 1 doctest) |
| bee_tsdb | 10 | 10 | 0 | filtrage Query (Neq/Regex/plage/AND), Point serde, enum debug (backend feature +22) |
| social_grpc | 5 | 5 | 0 | SearchService : allers-retours index/search/delete, repli sur JSON invalide, index vide |
| hello_bee | 0 | — | — | programme d'exemple, aucun test |

## Lacunes de couverture

- **Feature `redis` de bee_kv (RedisStore)** : nécessite un serveur Redis actif, non couvert
- **hello_bee** : programme d'exemple, 0 test
- **Backends sous feature** (non compilés avec les features par défaut) : vérifiés compilables et passant leurs tests avec leurs combinaisons de features respectives (tsdb 22, graph 29, search 20, kv 10), mais les vrais backends es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis exigent des services externes — vérification au niveau compilation uniquement
- **bee_config_macro / bee_orm_macro** : proc-macros, couverts indirectement via leurs tests d'intégration, pas de tests unitaires dédiés

## Bugs réels documentés (code source des bibliothèques non modifié)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` itère `&point.fields` (HashMap) sans tri, alors que les tags sont triés → sortie line protocol non déterministe avec plusieurs champs
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` n'est pas atomique (exécution point par point avec court-circuit `?`), incompatible avec le « atomically » revendiqué dans la doc du trait
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` retourne toujours `VertexNotFound(edge.from)`, même quand le point d'extrémité manquant est `to`
4. `bee_search` MemoryEngine `search` — ignore le from/size transmis par la couche gRPC (pas de pagination)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)` : un id non numérique devient silencieusement 0
6. Doc de la macro `context!` dans `bee_template/src/lib.rs` — affirme que NaN panique, alors que serde_json ≥1.0.128 le sérialise en null (doc obsolète)
7. `bee_search/src/elasticsearch.rs:64` — le NDJSON bulk insère index/id bruts dans le JSON ; des id contenant `"` produisent un NDJSON invalide

## Remarques d'environnement

- cargo se trouve dans `~/.cargo/bin` (pas dans le PATH), nécessite `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc nécessite `protoc` : obtenu via `apt-get download protobuf-compiler` + `dpkg-deb -x` extrait dans `/tmp/protoc-local`, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (pas besoin de sudo)
