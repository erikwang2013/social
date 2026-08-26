# Lingkungan Runtime PHP gRPC

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

Direktori ini adalah proyek **klien** webman gRPC (stub kontrak berada di `generated/`, dihasilkan oleh `scripts/gen-contracts.sh`).

## Dependensi

- Paket Composer: `grpc/grpc` (pustaka klien PHP), `google/protobuf` (runtime pesan) — sudah ditambahkan ke `composer.json`.
- Ekstensi PHP: `grpc` (wajib; koneksi klien bergantung pada ekstensi C). Ekstensi `google/protobuf` opsional (utamakan ekstensi jika tersedia, jika tidak gunakan paket Composer).

## Menginstal ekstensi grpc

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

Setelah menginstal, pastikan `php -i | grep grpc` menampilkan `grpc support => enabled`.

Mesin dev saat ini (2026-08-17): paket Composer terinstal (grpc/grpc 1.82, google/protobuf 5.35), **ekstensi grpc belum terinstal** (pecl tidak punya izin tulis, sudo butuh kata sandi). Ekstensi harus diinstal sebelum gRPC bisa berjalan secara lokal; CI (T10) menginstalnya melalui `extensions: grpc` dari shivammathur/setup-php.

## Catatan

Di repositori ini, `composer require` memicu error fatal pemuatan kelas ganda pada plugin `erikwang2013/security-php`
(Installer.php di vendor dimuat sekali oleh mekanisme plugin dan sekali oleh autoload); tambahkan `--no-plugins` untuk mengatasinya:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## Skrip probe

`php scripts/probe_ping.php` (disediakan T5) mengirim `InfraService.Ping` ke `127.0.0.1:50051` milik infrastructure.
