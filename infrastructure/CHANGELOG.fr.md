# Journal des modifications

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### Ajouté
- Implémentations réelles de `bee_cli` : `new` (génération de projet), `generate controller/model`, `run` avec rechargement à chaud `--watch`, `pack` (build de release + copie vers `dist/`)
- Tests unitaires CLI pour la génération de projets et de code (7 nouveaux tests)

### Corrigé
- `bee_rust::init()` est désormais masqué derrière la fonctionnalité `logs` — les builds réduits (par ex. `--no-default-features --features kv`) compilent à nouveau
- Lint Clippy `unnecessary_map_or` dans `bee_kv::InMemoryKvStore::exists`
- `rustfmt.toml` : suppression des options réservées à nightly, silencieusement ignorées sur stable ; le workspace passe désormais `cargo fmt --all --check`
- Binaire `bee_cli` avec `doc = false` pour éliminer la collision de nom de sortie rustdoc avec `bee_rust`
- Le port de l'exemple `hello` est désormais configurable via la variable d'environnement `PORT`

### Modifié
- `bee-rust migrate` signale « not implemented » et se termine avec un code non nul (prévu)
- README / README.en mis à jour pour décrire le comportement réel du CLI

## [1.0.4] — 2026-07-29

### Ajouté
- Filtre de détection d'attaques via `security-rust` (27 détecteurs)
- `SecurityFilter` couvrant XSS, injection SQL, injection de commandes et traversée de chemin
- Drapeau de fonctionnalité `security` dans `bee_rust` et `bee_router`

### Modifié
- README mis à jour avec la documentation des fonctionnalités de sécurité
- README mis à jour avec une section sur le support des paiements (WeChat Pay / Alipay)

### Corrigé
- Syntaxe des identifiants bruts Tera de `bee_template` pour l'édition Rust 2024

## [1.0.3] — 2026-07-29

### Ajouté
- Structure initiale du workspace avec 13 crates
- Routage MVC avec le trait `Controller` et `Router`
- ORM avec le builder `QuerySet` et la macro derive `Model`
- Abstraction trait KV/Cache avec des backends Redis et Memory
- Gestion des sessions avec les backends Memory/Redis
- Gestion de la configuration avec support INI/YAML/ENV et rechargement à chaud
- Rendu de templates via Tera
- Journalisation avec intégration tracing
- Génération de projets et de code via le CLI
- Stubs de traits pour les moteurs de recherche, graphes et séries temporelles (pilotes prévus)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
