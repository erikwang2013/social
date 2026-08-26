# 변경 로그

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### 추가됨
- `bee_cli` 실제 구현: `new`(프로젝트 스캐폴딩), `generate controller/model`, `--watch` 핫 리로드가 포함된 `run`, `pack`(릴리스 빌드 + `dist/`로 복사)
- 스캐폴딩 및 코드 생성 CLI 단위 테스트(새 테스트 7개)

### 수정됨
- `bee_rust::init()`이 이제 `logs` 기능 뒤에 게이팅됨 — 축소 기능 빌드(예: `--no-default-features --features kv`)가 다시 컴파일됨
- `bee_kv::InMemoryKvStore::exists`의 Clippy `unnecessary_map_or` 린트
- `rustfmt.toml`에서 stable에서 조용히 무시되던 nightly 전용 옵션 제거; 워크스페이스가 이제 `cargo fmt --all --check` 통과
- `bee_cli` 바이너리에 `doc = false` 적용 — `bee_rust`와의 rustdoc 출력 파일명 충돌 제거
- `hello` 예제 포트를 이제 `PORT` 환경 변수로 구성 가능

### 변경됨
- `bee-rust migrate`가 "not implemented"를 보고하고 0이 아닌 코드로 종료(예정)
- README / README.en을 실제 CLI 동작을 설명하도록 업데이트

## [1.0.4] — 2026-07-29

### 추가됨
- `security-rust`를 통한 보안 공격 탐지 필터(탐지기 27개)
- XSS, SQL 인젝션, 커맨드 인젝션, 경로 탐색(path traversal) 커버리지를 갖춘 `SecurityFilter`
- `bee_rust`와 `bee_router`의 `security` 기능 플래그

### 변경됨
- 보안 기능 문서로 README 업데이트
- 결제 지원 섹션(WeChat Pay / Alipay)으로 README 업데이트

### 수정됨
- Rust 2024 에디션용 `bee_template` Tera raw 식별자 구문

## [1.0.3] — 2026-07-29

### 추가됨
- 13개 크레이트로 구성된 초기 워크스페이스 구조
- `Controller` 트레이트와 `Router`를 사용한 MVC 라우팅
- `QuerySet` 빌더와 `Model` derive 매크로를 사용한 ORM
- Redis 및 Memory 백엔드를 갖춘 KV/Cache 트레이트 추상화
- Memory/Redis 백엔드를 갖춘 세션 관리
- INI/YAML/ENV 지원과 핫 리로드를 갖춘 설정 관리
- Tera를 통한 템플릿 렌더링
- tracing 통합 로깅
- CLI 스캐폴딩 및 코드 생성
- Search, Graph, Time-series 엔진 트레이트 스텁(드라이버 예정)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
