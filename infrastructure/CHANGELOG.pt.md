# Registro de alterações

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### Adicionado
- Implementações reais de `bee_cli`: `new` (scaffolding de projetos), `generate controller/model`, `run` com hot reload `--watch`, `pack` (build de release + cópia para `dist/`)
- Testes unitários de CLI para scaffolding e geração de código (7 novos testes)

### Corrigido
- `bee_rust::init()` agora está atrás da feature `logs` — builds reduzidos (ex.: `--no-default-features --features kv`) compilam novamente
- Lint `unnecessary_map_or` do Clippy em `bee_kv::InMemoryKvStore::exists`
- `rustfmt.toml` removeu opções exclusivas do nightly que eram ignoradas silenciosamente no stable; o workspace agora passa em `cargo fmt --all --check`
- Binário `bee_cli` com `doc = false` para eliminar a colisão de nome de saída do rustdoc com `bee_rust`
- A porta do exemplo `hello` agora é configurável via variável de ambiente `PORT`

### Alterado
- `bee-rust migrate` informa "not implemented" e sai com código não zero (planejado)
- README / README.en atualizados para descrever o comportamento real do CLI

## [1.0.4] — 2026-07-29

### Adicionado
- Filtro de detecção de ataques de segurança via `security-rust` (27 detectores)
- `SecurityFilter` com cobertura de XSS, injeção de SQL, injeção de comandos e path traversal
- Feature flag `security` em `bee_rust` e `bee_router`

### Alterado
- README atualizado com documentação das funcionalidades de segurança
- README atualizado com seção de suporte a pagamentos (WeChat Pay / Alipay)

### Corrigido
- Sintaxe de identificadores raw do Tera em `bee_template` para a edição Rust 2024

## [1.0.3] — 2026-07-29

### Adicionado
- Estrutura inicial do workspace com 13 crates
- Roteamento MVC com o trait `Controller` e `Router`
- ORM com o builder `QuerySet` e a macro derive `Model`
- Abstração de trait KV/Cache com backends Redis e Memory
- Gerenciamento de sessões com backends Memory/Redis
- Gerenciamento de configuração com suporte a INI/YAML/ENV e hot reload
- Renderização de templates via Tera
- Logging com integração com tracing
- Scaffolding CLI e geração de código
- Stubs de traits para os motores de busca, grafos e séries temporais (drivers planejados)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
