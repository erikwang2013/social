# Environnement d'exécution PHP gRPC

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

Ce répertoire est un projet **client** webman gRPC (les stubs de contrats se trouvent dans `generated/`, générés par `scripts/gen-contracts.sh`).

## Dépendances

- Paquets Composer : `grpc/grpc` (bibliothèque cliente PHP), `google/protobuf` (runtime des messages) — déjà ajoutés à `composer.json`.
- Extensions PHP : `grpc` (obligatoire ; les connexions client dépendent de l'extension C). L'extension `google/protobuf` est facultative (préférez l'extension si disponible, sinon le paquet Composer).

## Installation de l'extension grpc

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

Après l'installation, vérifiez que `php -i | grep grpc` affiche `grpc support => enabled`.

Machine de développement actuelle (2026-08-17) : les paquets Composer sont installés (grpc/grpc 1.82, google/protobuf 5.35), **l'extension grpc n'est pas installée** (pecl n'a pas les droits d'écriture, sudo demande un mot de passe). Elle doit être installée avant de pouvoir exécuter gRPC en local ; le CI (T10) l'installe via `extensions: grpc` de shivammathur/setup-php.

## Remarques

Dans ce dépôt, `composer require` déclenche une erreur fatale de chargement dupliqué des classes du plugin `erikwang2013/security-php`
(Installer.php dans vendor est chargé une fois par le mécanisme de plugin et une fois par autoload) ; ajoutez `--no-plugins` pour contourner :

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## Script de sonde de disponibilité

`php scripts/probe_ping.php` (fourni par T5) envoie `InfraService.Ping` vers `127.0.0.1:50051` de l'infrastructure.
