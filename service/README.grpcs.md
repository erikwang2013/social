# PHP gRPC 运行环境

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

本目录为 webman gRPC **客户端**工程（契约桩在 `generated/`，由 `scripts/gen-contracts.sh` 生成）。

## 依赖

- Composer 包：`grpc/grpc`（PHP 客户端库）、`google/protobuf`（消息运行时）——已加入 `composer.json`。
- PHP 扩展：`grpc`（必需，客户端连接依赖 C 扩展）。`google/protobuf` 扩展可选（有则优先用扩展，否则用 Composer 包）。

## 安装 grpc 扩展

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

安装后确认 `php -i | grep grpc` 能看到 `grpc support => enabled`。

当前开发机（2026-08-17）：Composer 包已装（grpc/grpc 1.82、google/protobuf 5.35），
**grpc 扩展未装**（pecl 无写权限、sudo 需密码）。本地跑通 gRPC 前需先补装；
CI（T10）由 shivammathur/setup-php 的 `extensions: grpc` 安装。

## 注意

`composer require` 在本仓库会触发 `erikwang2013/security-php` 插件的重复类加载致命错误
（vendor 内 Installer.php 被插件机制与 autoload 各加载一次），需加 `--no-plugins` 绕过：

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## 探活脚本

`php scripts/probe_ping.php`（T5 提供），对 infrastructure 的 `127.0.0.1:50051` 发起 `InfraService.Ping`。
