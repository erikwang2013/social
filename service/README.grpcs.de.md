# PHP-gRPC-Laufzeitumgebung

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

Dieses Verzeichnis ist ein webman-gRPC-**Client**-Projekt (die Vertrags-Stubs liegen in `generated/` und werden von `scripts/gen-contracts.sh` erzeugt).

## Abhängigkeiten

- Composer-Pakete: `grpc/grpc` (PHP-Client-Bibliothek), `google/protobuf` (Nachrichten-Laufzeit) — bereits in `composer.json` aufgenommen.
- PHP-Erweiterungen: `grpc` (erforderlich; Client-Verbindungen hängen von der C-Erweiterung ab). Die `google/protobuf`-Erweiterung ist optional (falls vorhanden, die Erweiterung bevorzugen, sonst das Composer-Paket).

## Installieren der grpc-Erweiterung

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

Prüfen Sie nach der Installation, dass `php -i | grep grpc` `grpc support => enabled` anzeigt.

Aktuelle Entwicklungsmaschine (2026-08-17): Composer-Pakete sind installiert (grpc/grpc 1.82, google/protobuf 5.35), **die grpc-Erweiterung ist nicht installiert** (pecl hat keine Schreibrechte, sudo benötigt ein Passwort). Sie muss installiert werden, bevor gRPC lokal läuft; CI (T10) installiert sie über `extensions: grpc` von shivammathur/setup-php.

## Hinweise

In diesem Repository löst `composer require` einen fatalen Fehler durch doppeltes Laden von Klassen des Plugins `erikwang2013/security-php` aus
(Installer.php in vendor wird einmal vom Plugin-Mechanismus und einmal vom Autoload geladen); `--no-plugins` umgeht dies:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## Liveness-Probe-Skript

`php scripts/probe_ping.php` (von T5 bereitgestellt) sendet `InfraService.Ping` an `127.0.0.1:50051` der Infrastructure.
