# Rapport de tests unitaires Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Date : 2026-08-27
- Emplacement : `/home/wwwroot/social/infrastructure`
- Commande : `cargo test --workspace` (feature par défaut), plus vérification séparée des backends sous feature (tsdb/graph/search/kv)
- Résultat : **183 réussis / 0 échoué** (178 unitaires+intégration + 5 inline de feature + 1 doctest, etc. ; le workspace par défaut inclut les 6 cas de bee_search parce que social_grpc dépend de sa feature `elasticsearch`)

## Récapitulatif

| crate | Cas de test | Réussis | Échoués | Modules couverts |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | mapping rust_type, find_bin_name, validate_name, analyse des arguments bin |
| bee_config | 8 + 6 intégration | 14 | 0 | IniParser (commentaires/espaces/changement de section), Config, ConfigSource, erreurs de rechargement |
| bee_config_macro | 0 | — | — | couvert indirectement via les tests d'intégration |
| bee_graph | 15 | 15 | 0 | StubGraphDB : direction/profondeur/étiquettes de traversée, add/update/delete, chemins d'erreur, serde (feature neo4j +5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, clés expirées, chemins d'erreur (feature redis +3 cas Redis réels) |
| bee_logs | 4 | 4 | 0 | level_str tous niveaux, sortie fichier, sortie stdout/stderr |
| bee_orm | 19 intégration | 19 | 0 | SelectBuilder : order/limit/offset/liaison de paramètres/réutilisation/table_name/Display des erreurs (0 dans lib) |
| bee_orm_macro | 0 | — | — | couvert indirectement via les tests d'intégration |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), pipeline de dispatch, restauration/persistance/cookie expiré de session |
| bee_rust | 2 (bin +9) | 11 | 0 | exports prelude, alias Result, analyse des arguments CLI |
| bee_search | 20 (dont 6 inline de feature) | 20 | 0 | MemoryEngine : index/delete/écrasement/pagination/get/requête vide/serde ; driver Elasticsearch : get/search/bulk/aggregate, échappement NDJSON |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, chemins d'erreur, unicité des UUID |
| bee_template | 6 + 1 doctest | 7 | 0 | macro context!, render, erreurs de template/variable manquante, engine vide, flottants non finis (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | écritures/écritures par lot, bornes des requêtes de plage, filtrage Eq/Neq/Regex/AND, Point serde, CQ (feature influxdb +5, dont déterminisme du line protocol) |
| social_grpc | 6 | 6 | 0 | SearchService : allers-retours index/search/delete, repli sur JSON invalide, index vide, erreur sur id non numérique |
| hello_bee | 0 | — | — | programme d'exemple, aucun test |

## Défauts réels corrigés dans ce tour (fix minimal + tests de régression)

1. **bee_search MemoryEngine `search` ignore la pagination** (`crates/bee_search/src/lib.rs`) — le `from`/`size` transmis par la couche gRPC était ignoré, renvoyant toujours tous les résultats. Fix : lit `from`/`size` dans le JSON de requête et applique skip/truncate sur les hits, `total` compte toujours toutes les correspondances. Nouveau : `test_search_honors_from_size_pagination` (robuste face à l'itération non ordonnée d'une HashMap : comparaison avec une tranche du résultat complet de l'engine).
2. **social_grpc `search` transforme silencieusement les id non numériques en 0** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` renvoyait silencieusement les id non numériques comme 0. Fix : l'échec du parse renvoie `Status::invalid_argument`. Nouveau : `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb InfluxDB champs line protocol désordonnés** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — les tags sont triés mais pas les fields ; sortie non déterministe avec plusieurs champs. Fix : tri des fields par clé. Nouveau : `line_protocol_is_deterministic_across_field_insertion_order` (des ordres d'insertion différents produisent des lignes identiques, triées par a,b).
4. **bee_search Elasticsearch bulk NDJSON sans échappement des id** (`crates/bee_search/src/elasticsearch.rs`) — index/id interpolés en clair ; les id contenant `"` produisaient un NDJSON invalide. Fix : `bulk_ndjson()` extrait, lignes d'action sérialisées via serde_json. Nouveau : `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge` signale toujours l'extrémité `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — quand l'extrémité manquante est `to`, le message d'erreur induit en erreur. Fix : quand `nodes-matched < 2`, utiliser `get_vertex` pour déterminer l'extrémité réellement manquante avant de signaler. Nouveau : `add_edge_reports_the_missing_endpoint` (service HTTP simulé vérifie qu'il signale `to1` plutôt que `from1`).
6. **bee_template doc `context!` incohérente avec le comportement** (`crates/bee_template/src/lib.rs`) — la doc affirme que les flottants non finis paniquent, mais serde_json les sérialise en `null` (prouvé par un test existant). Fix : doc mise à jour.

## Nouvelle couverture

- **Tests d'intégration bee_kv RedisStore avec vrai Redis** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — comble la lacune de couverture nommée explicitement dans le rapport précédent. Redis local disponible (127.0.0.1:6379) ; 3 cas : aller-retour set/get/del, incr/expire, mset/mget ; clés avec préfixe pid+nanosecondes, les cas se nettoient eux-mêmes. Si Redis est indisponible, les cas sont sautés élégamment (affichage SKIP et passage).

## Lacunes de couverture (inchangées)

- **bee_tsdb IoTDB `write_batch` non atomique** (`crates/bee_tsdb/src/iotdb.rs`) — écritures point par point avec court-circuit `?`, incompatible avec le « atomically » de la doc du trait. Le correctif exige le support transactionnel du backend ; pas d'instance IoTDB locale, donc pas de changement à l'aveugle ce tour ; listé comme limitation connue.
- **Backends externes** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — les principaux drivers (elasticsearch, neo4j, influxdb : chemins écriture/requête/CQ) sont couverts par des services HTTP simulés locaux ; le reste sans service local n'a qu'une vérification au niveau compilation.
- **MySQL** : 127.0.0.1:3306 local disponible (root, mot de passe vide), mais aucun crate du workspace n'introduit de driver MySQL — bee_orm est un constructeur SQL indépendant du driver, les cas QuerySet ne dépendent pas d'une vraie base ; aucune dépendance driver n'est nécessaire ni ne doit être ajoutée.
- **bee_config_macro / bee_orm_macro** : proc-macros, couverts indirectement via leurs tests d'intégration, pas de tests unitaires dédiés.

## Contrôle qualité

- `cargo fmt --check` : réussi (ce tour, `cargo fmt` a été exécuté sur tout le workspace, corrigeant 20+ écarts de formatage laissés par les sessions précédentes).
- `cargo clippy --workspace --all-targets` : zéro avertissement dans le nouveau code ; les 3 restants sont des avertissements préexistants (bee_config `get("default").is_none()`, bee_rust `unwrap()` sur Ok, bee_search MemoryEngine sans impl Default), hors périmètre ce tour.

## Remarques d'environnement

- cargo se trouve dans `~/.cargo/bin` (pas dans le PATH par défaut), nécessite `export PATH="$HOME/.cargo/bin:$PATH"`.
- `protoc` est maintenant disponible (`/home/erik/.local/bin/protoc`).
- social_grpc tourne en arrière-plan (port 50051) ; ce rapport n'a exécuté que `cargo test`, pas de `cargo run` dessus.
- Redis (6379) et MySQL (3306) disponibles localement ; liste des cas de feature :
  - `cargo test -p bee_tsdb --features influxdb` → 16 réussis
  - `cargo test -p bee_search --features elasticsearch` → 20 réussis
  - `cargo test -p bee_graph --features neo4j` → 20 réussis
  - `cargo test -p bee_kv --features redis` → 13 réussis (dont 3 cas Redis réels)
