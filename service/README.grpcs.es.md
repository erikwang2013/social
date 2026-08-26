# Entorno de ejecución de PHP gRPC

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

Este directorio es un proyecto **cliente** de webman gRPC (los stubs de contratos están en `generated/`, generados por `scripts/gen-contracts.sh`).

## Dependencias

- Paquetes de Composer: `grpc/grpc` (biblioteca cliente de PHP), `google/protobuf` (runtime de mensajes) — ya añadidos a `composer.json`.
- Extensiones de PHP: `grpc` (obligatoria; las conexiones del cliente dependen de la extensión C). La extensión `google/protobuf` es opcional (prioriza la extensión si está disponible; si no, usa el paquete de Composer).

## Instalación de la extensión grpc

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

Después de instalar, confirma que `php -i | grep grpc` muestra `grpc support => enabled`.

Máquina de desarrollo actual (2026-08-17): los paquetes de Composer están instalados (grpc/grpc 1.82, google/protobuf 5.35), **la extensión grpc no está instalada** (pecl no tiene permisos de escritura y sudo requiere contraseña). Hay que instalarla antes de poder ejecutar gRPC en local; el CI (T10) la instala mediante `extensions: grpc` de shivammathur/setup-php.

## Notas

En este repositorio, `composer require` provoca un error fatal de carga duplicada de clases del plugin `erikwang2013/security-php`
(Installer.php en vendor se carga una vez por el mecanismo de plugins y otra por autoload); añade `--no-plugins` para evitarlo:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## Script de sondeo

`php scripts/probe_ping.php` (proporcionado por T5) envía `InfraService.Ping` a `127.0.0.1:50051` de infrastructure.
