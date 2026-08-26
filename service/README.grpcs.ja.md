# PHP gRPC 実行環境

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

このディレクトリは webman gRPC **クライアント**プロジェクトです（契約スタブは `generated/` にあり、`scripts/gen-contracts.sh` によって生成されます）。

## 依存関係

- Composer パッケージ: `grpc/grpc`（PHP クライアントライブラリ）、`google/protobuf`（メッセージランタイム）— `composer.json` に追加済み。
- PHP 拡張: `grpc`（必須。クライアント接続は C 拡張に依存）。`google/protobuf` 拡張は任意（あれば拡張を優先し、なければ Composer パッケージを使用）。

## grpc 拡張のインストール

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

インストール後、`php -i | grep grpc` で `grpc support => enabled` が表示されることを確認してください。

現在の開発マシン（2026-08-17）: Composer パッケージはインストール済み（grpc/grpc 1.82、google/protobuf 5.35）、**grpc 拡張は未インストール**（pecl に書き込み権限がなく、sudo にはパスワードが必要）。ローカルで gRPC を動かす前に追加インストールが必要です。CI（T10）は shivammathur/setup-php の `extensions: grpc` でインストールします。

## 注意

このリポジトリでは、`composer require` を実行すると `erikwang2013/security-php` プラグインの重複クラスロードによる致命的エラーが発生します
（vendor 内の Installer.php がプラグインメカニズムと autoload でそれぞれ 1 回ずつロードされる）。`--no-plugins` を付けて回避してください:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## 死活監視スクリプト

`php scripts/probe_ping.php`（T5 提供）は infrastructure の `127.0.0.1:50051` に対して `InfraService.Ping` を送信します。
