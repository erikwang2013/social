# Ambiente de execução PHP gRPC

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

Este diretório é um projeto **cliente** de webman gRPC (os stubs de contrato estão em `generated/`, gerados por `scripts/gen-contracts.sh`).

## Dependências

- Pacotes Composer: `grpc/grpc` (biblioteca cliente PHP), `google/protobuf` (runtime de mensagens) — já adicionados ao `composer.json`.
- Extensões PHP: `grpc` (obrigatória; as conexões do cliente dependem da extensão C). A extensão `google/protobuf` é opcional (prefira a extensão se disponível, caso contrário use o pacote Composer).

## Instalando a extensão grpc

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

Após instalar, confirme que `php -i | grep grpc` mostra `grpc support => enabled`.

Máquina de desenvolvimento atual (2026-08-17): os pacotes Composer estão instalados (grpc/grpc 1.82, google/protobuf 5.35), **a extensão grpc não está instalada** (pecl sem permissão de escrita, sudo exige senha). Ela precisa ser instalada antes de rodar gRPC localmente; o CI (T10) instala via `extensions: grpc` do shivammathur/setup-php.

## Observações

Neste repositório, `composer require` dispara um erro fatal de carregamento duplicado de classes do plugin `erikwang2013/security-php`
(o Installer.php em vendor é carregado uma vez pelo mecanismo de plugins e uma vez pelo autoload); adicione `--no-plugins` para contornar:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## Script de sondagem

`php scripts/probe_ping.php` (fornecido pelo T5) envia `InfraService.Ping` para `127.0.0.1:50051` da infrastructure.
