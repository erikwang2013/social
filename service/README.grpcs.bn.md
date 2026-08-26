# PHP gRPC রানটাইম পরিবেশ

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

এই ডিরেক্টরিটি একটি webman gRPC **ক্লায়েন্ট** প্রজেক্ট (কন্ট্রাক্ট স্টাবগুলো `generated/`-এ আছে, যা `scripts/gen-contracts.sh` দিয়ে তৈরি হয়)।

## নির্ভরতা

- Composer প্যাকেজ: `grpc/grpc` (PHP ক্লায়েন্ট লাইব্রেরি), `google/protobuf` (মেসেজ রানটাইম) — ইতিমধ্যে `composer.json`-এ যোগ করা হয়েছে।
- PHP এক্সটেনশন: `grpc` (আবশ্যক; ক্লায়েন্ট সংযোগ C এক্সটেনশনের উপর নির্ভর করে)। `google/protobuf` এক্সটেনশন ঐচ্ছিক (থাকলে এক্সটেনশন ব্যবহার করুন, না থাকলে Composer প্যাকেজ)।

## grpc এক্সটেনশন ইনস্টল করা

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

ইনস্টলের পরে নিশ্চিত করুন যে `php -i | grep grpc`-এ `grpc support => enabled` দেখা যাচ্ছে।

বর্তমান ডেভ মেশিন (2026-08-17): Composer প্যাকেজ ইনস্টল করা আছে (grpc/grpc 1.82, google/protobuf 5.35), **grpc এক্সটেনশন ইনস্টল নেই** (pecl-এর লেখার অনুমতি নেই, sudo-তে পাসওয়ার্ড লাগে)। লোকালি gRPC চালানোর আগে এটি ইনস্টল করতে হবে; CI (T10) shivammathur/setup-php-এর `extensions: grpc` দিয়ে ইনস্টল করে।

## নোট

এই রিপোজিটরিতে `composer require` চালালে `erikwang2013/security-php` প্লাগইনের ডুপ্লিকেট ক্লাস লোডিং মারাত্মক ত্রুটি ঘটে
(vendor-এর ভেতরে Installer.php প্লাগইন মেকানিজম দিয়ে একবার এবং autoload দিয়ে আরেকবার লোড হয়); `--no-plugins` যোগ করে এড়িয়ে যেতে হবে:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## প্রোব স্ক্রিপ্ট

`php scripts/probe_ping.php` (T5 প্রদত্ত) infrastructure-এর `127.0.0.1:50051`-এ `InfraService.Ping` পাঠায়।
