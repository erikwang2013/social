# Среда выполнения PHP gRPC

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

Это каталог **клиентского** проекта webman gRPC (заглушки контрактов находятся в `generated/`, генерируются `scripts/gen-contracts.sh`).

## Зависимости

- Пакеты Composer: `grpc/grpc` (PHP-клиентская библиотека), `google/protobuf` (рантайм сообщений) — уже добавлены в `composer.json`.
- Расширения PHP: `grpc` (обязательно, клиентские подключения зависят от C-расширения). Расширение `google/protobuf` опционально (при наличии предпочтительно расширение, иначе пакет Composer).

## Установка расширения grpc

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

После установки убедитесь, что `php -i | grep grpc` показывает `grpc support => enabled`.

Текущая машина разработки (2026-08-17): пакеты Composer установлены (grpc/grpc 1.82, google/protobuf 5.35), **расширение grpc не установлено** (у pecl нет прав на запись, для sudo нужен пароль). Перед локальным запуском gRPC его нужно доустановить; CI (T10) устанавливает его через `extensions: grpc` в shivammathur/setup-php.

## Примечание

В этом репозитории `composer require` вызывает фатальную ошибку повторной загрузки классов плагина `erikwang2013/security-php`
(Installer.php в vendor загружается один раз механизмом плагинов и один раз autoload), поэтому нужно добавить `--no-plugins`:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## Скрипт проверки доступности

`php scripts/probe_ping.php` (предоставлен T5) отправляет `InfraService.Ping` на `127.0.0.1:50051` инфраструктуры.
