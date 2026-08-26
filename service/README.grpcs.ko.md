# PHP gRPC 실행 환경

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

이 디렉터리는 webman gRPC **클라이언트** 프로젝트입니다(계약 스텁은 `generated/`에 있으며, `scripts/gen-contracts.sh`로 생성됨).

## 의존성

- Composer 패키지: `grpc/grpc`(PHP 클라이언트 라이브러리), `google/protobuf`(메시지 런타임) — `composer.json`에 이미 추가됨.
- PHP 확장: `grpc`(필수, 클라이언트 연결이 C 확장에 의존). `google/protobuf` 확장은 선택 사항(있으면 확장 우선, 없으면 Composer 패키지 사용).

## grpc 확장 설치

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

설치 후 `php -i | grep grpc`에서 `grpc support => enabled`가 보이는지 확인합니다.

현재 개발 머신(2026-08-17): Composer 패키지는 설치됨(grpc/grpc 1.82, google/protobuf 5.35), **grpc 확장은 미설치**(pecl 쓰기 권한 없음, sudo는 비밀번호 필요). 로컬에서 gRPC를 실행하려면 먼저 설치해야 하며, CI(T10)는 shivammathur/setup-php의 `extensions: grpc`로 설치합니다.

## 주의

이 저장소에서 `composer require`는 `erikwang2013/security-php` 플러그인의 중복 클래스 로딩 치명적 오류를 유발합니다
(vendor 내 Installer.php가 플러그인 메커니즘과 autoload로 각각 한 번씩 로드됨). `--no-plugins`를 추가해 우회해야 합니다:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## 프로브 스크립트

`php scripts/probe_ping.php`(T5 제공)는 infrastructure의 `127.0.0.1:50051`에 `InfraService.Ping`을 보냅니다.
