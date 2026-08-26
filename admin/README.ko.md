# 오픈 관리자 콘솔 (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

webman v2 + Flutter 기반의 풀스택 관리자 콘솔 시스템입니다.

> [English version](README_EN.md) | [아키텍처 다이어그램](docs/ARCHITECTURE.md) | [설계 문서](docs/DESIGN.md) | [보안 아키텍처](docs/SECURITY.md) | [API 레퍼런스](docs/API.md)

## 기능 목록

| 영역 | 기능 | 설명 |
|--------|------|------|
| 🔐 인증 | 로그인 / 토큰 갱신 / 로그아웃 | 클릭 캡차 + JWT + 블랙리스트 |
| | 계정 잠금 | 5회 실패 시 15분 잠금 |
| | 동시 세션 제한 | 사용자당 유효 Token 최대 3개 |
| 📊 대시보드 | 실시간 통계 / 추세 그래프 / 분포도 / 최근 작업 | Redis 5분 캐시 |
| 👥 사용자 관리 | CRUD + 일괄 삭제 / 활성·비활성 | 소프트 삭제 + 비밀번호 재확인 |
| | Excel 일괄 가져오기 | 행 단위 검증 + 오류 보고서 |
| 🔒 역할·권한 | 역할 CRUD + 권한 트리 | RBAC method.path 단위 인가 |
| ⚙ 시스템 설정 | 키-값 CRUD | 그룹 관리 |
| 📋 작업 감사 | 로그 조회 + 접속원 감지 | 8개 플랫폼 자동 인식 |
| 📁 파일 관리 | 업로드 / Excel 내보내기 / PDF 내보내기 | 민감 데이터 자동 마스킹 |
| 🛡 보안 | 18단계 심층 방어 | XSS/SQL 인젝션/경로 탐색/명령 삽입/CSRF/속도 제한/CSP... |
| 🏥 운영 | 헬스 체크 / metrics / API 문서 / security.txt | Prometheus + OpenAPI 3.0 + hg/apidoc 대화형 문서 |
| 🌐 국제화 | 중·영문 전환 | Accept-Language 헤더 / ?lang= 파라미터 |

## 기술 스택

| 계층 | 기술 | 설명 |
|---|------|------|
| 백엔드 프레임워크 | webman v2 (workerman) | 초고성능 PHP 상주 프로세스 프레임워크 |
| PHP 버전 | 8.3+ | |
| 데이터베이스 | MySQL 8.0+ | 테이블 접두사 `erik_`, BIGINT 비자동증가 기본키 |
| 검색 엔진 | Elasticsearch | `webman-scout`으로 동기화 및 조회 |
| 관리자 프론트엔드 | Flutter 3.x | Web은 PC 관리 콘솔 스타일 (`apps/flutter/`) |
| 모바일 | HarmonyOS ArkTS | 하모니OS 네이티브 클라이언트 (`apps/harmonyos/`), 폰/태블릿/2in1 지원 |

## 핵심 의존성

| 패키지 | 용도 |
|---|------|
| `erikwang2013/snowflake-php` | Snowflake 알고리즘으로 전역 고유 BIGINT 기본키 생성 |
| `erikwang2013/hashids` | API 계층 ID 암복호화, 실제 DB ID 숨김 |
| `erikwang2013/jwt-webman` | JWT 인증 토큰 발급 및 검증 |
| `erikwang2013/encryption` | 인터페이스 전송 계층 민감 데이터 암복호화 |
| `erikwang2013/encryptable` | DB 저장 계층 민감 필드 자동 암복호화 |
| `erikwang2013/webman-scout` | Elasticsearch 데이터 동기화 및 전문 검색 |
| `erikwang2013/season` | 국가 국기 데이터 |
| `erikwang2013/poster-php` | 클릭 캡차 생성·검증 + 포스터 생성 |
| `phpoffice/phpspreadsheet` | Excel 내보내기 |
| `barryvdh/laravel-dompdf` | PDF 내보내기 (Dompdf 기반) |

## 프로젝트 구조

```
open-admin/
├── app/
│   ├── admin/controller/       # 管理端控制器
│   │   ├── DashboardController.php # 仪表盘（Redis缓存）
│   │   ├── UserController.php      # 用户 CRUD + 批量操作
│   │   ├── RoleController.php      # 角色 CRUD
│   │   ├── PermissionController.php# 权限 CRUD
│   │   ├── ConfigController.php    # 系统配置 CRUD
│   │   ├── LogController.php       # 操作日志查询
│   │   ├── ProfileController.php   # 个人中心 + 登出
│   │   ├── ExportController.php    # Excel/PDF 导出
│   │   ├── ImportController.php    # Excel 导入用户
│   │   ├── UploadController.php    # 文件上传
│   │   ├── HealthController.php    # 健康检查
│   │   ├── DocsController.php      # OpenAPI 文档
│   │   └── BaseController.php      # 基础控制器
│   ├── api/
│   │   └── v1/controller/          # API v1 控制器（版本由请求头 API-Version 控制）
│   │       ├── CaptchaController.php # 点击验证码
│   │       └── AuthController.php    # 登录/刷新令牌
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php  # ID 编解码
│   │   ├── SnowflakeService.php# Snowflake ID 生成
│   │   └── EncryptionService.php # 数据加解密 + 脱敏
│   ├── middleware/             # 中间件
│   │   ├── Cors.php            # 跨域
│   │   ├── SecurityFilter.php  # 攻击检测拦截（HTTP方法限制/XSS/SQL注入/路径遍历/命令注入/CSRF）
│   │   ├── RateLimit.php       # Redis 限流（滑动窗口 + 响应头）
│   │   ├── ApiVersion.php      # API 版本校验
│   │   ├── AdminAuth.php       # JWT 认证 + 黑名单
│   │   ├── AdminPermission.php # RBAC 权限校验
│   │   └── OperationLog.php    # 操作日志自动记录（含来源端检测）
│   └── model/                  # 数据模型
├── apps/
│   ├── flutter/                # Flutter Web 管理后台（PC 风格）
│   │   └── lib/app/
│   │       ├── pages/          # 5 个完整页面（仪表盘/用户/角色/配置/日志/个人中心）
│   │       ├── services/       # ApiService（JWT 拦截器）+ AuthService（Token 持久化）
│   │       └── layouts/        # 响应式管理后台布局（侧边栏+顶栏+内容区）
│   └── harmonyos/              # HarmonyOS 原生客户端（Token 无感刷新）
├── config/                     # 配置文件（含中文注释）
│   ├── route.php               # 路由 + API 版本策略
│   ├── middleware.php           # 全局中间件注册
│   └── ...                     # 各组件配置
├── database/migrations/        # SQL 迁移文件（含权限种子数据）
├── public/                     # 公共入口
├── runtime/                    # 运行时文件
└── vendor/                     # Composer 依赖
```

## 환경 요구사항

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (프론트엔드 개발 시에만 필요)
- Elasticsearch >= 7.x (선택 사항, 검색 기능에 필요)

## 빠른 시작

### 1. 의존성 설치

```bash
composer install
```

### 2. 환경 변수 설정

환경 변수를 복사하여 수정합니다 (선택 사항, 설정하지 않으면 `config/*.php`의 기본값 사용):

```bash
cp .env.example .env
```

주요 설정 항목:

| 환경 변수 | 설명 | 기본값 |
|---------|------|--------|
| `JWT_SECRET` | JWT 서명 키 | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Hashids 솔트 | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | API 암호화 키 | 32바이트 기본값 |
| `SNOWFLAKE_DATACENTER_ID` | 데이터센터 ID (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | 워커 노드 ID (0-31) | `1` |
| `SCOUT_HOSTS` | ES 주소 | `http://localhost:9200` |

**프로덕션 환경에서는 모든 키를 반드시 랜덤 문자열로 변경하세요.**

### 3. 원클릭 설치

서비스 시작 후 브라우저에서 설치 마법사에 접속해 데이터베이스 초기화와 관리자 생성을 완료합니다:

```bash
php start.php start
```

기본적으로 `http://0.0.0.0:8787`을 리슨합니다 (포트는 `config/server.php`에서 변경 가능).

브라우저에서 **`http://localhost:8787/install`**을 열고 마법사에 따라 입력합니다:

| 단계 | 내용 |
|------|------|
| ① 데이터베이스 설정 | 호스트 주소, 포트, 데이터베이스명, 사용자명, 비밀번호 |
| ② 관리자 설정 | 관리자 사용자명, 비밀번호 (기본 admin / admin888) |

「설치 시작」을 클릭하면 테이블 생성, 권한 데이터 시딩, 관리자 계정 생성, `.env`에 데이터베이스 설정 기록이 자동으로 완료됩니다.

> 설치 완료 후 `runtime/install.lock` 잠금 파일이 생성됩니다. 재설치가 필요하면 이 파일을 삭제하면 됩니다.

### 4. 로그인

`http://localhost:8787`에 접속해 설치 시 설정한 관리자 계정과 비밀번호로 로그인합니다.

### 5. 프론트엔드 실행 (선택 사항)

**Flutter 관리 콘솔 (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**HarmonyOS 클라이언트 (모바일):**

DevEco Studio로 `apps/harmonyos/` 디렉터리를 열고 실기기 또는 에뮬레이터에 연결해 실행합니다.

### 6. Docker Compose 원클릭 배포 (프로덕션 권장)

프로젝트는 Nginx, PHP (webman app), MySQL, Redis, Elasticsearch 5개 서비스로 구성된 완전한 Docker 오케스트레이션을 제공합니다.

```bash
# 1. 配置 Docker 环境变量
cp .env.docker .env

# 2. 启动所有服务
docker-compose up -d

# 3. 浏览器访问安装向导完成初始化
# http://localhost:8787/install  (填入数据库和管理员信息)
# 或手动执行 SQL 迁移（进入 app 容器）:
# docker-compose exec app mysql -h mysql -u root -p < database/migrations/open_admin.sql

# 4. 访问
# http://localhost:8787  (webman)
# http://localhost:8080  (Nginx 反向代理)
```

- `Dockerfile`: PHP 8.3 + OPcache + Composer, `php:8.3-cli` 기반
- `docker-compose.yml`: 5개 서비스 오케스트레이션, 네트워크 격리, 데이터 볼륨 영속화
- `.env.docker`: Docker 환경 전용 환경 변수


## 데이터베이스 규칙

- **테이블 접두사**: `erik_`
- **기본키**: 모든 테이블의 기본키는 `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT 사용 금지**
- **ID 생성**: 기본키 ID는 애플리케이션 계층의 `SnowflakeService::generate()`로 생성, 분산 환경에서 고유
- **필수 필드**: 모든 테이블은 `id`, `created_at`, `updated_at`을 포함해야 함
- **소프트 삭제**: 소프트 삭제가 필요한 테이블은 `deleted_at DATETIME DEFAULT NULL` 추가
- **민감 필드**: 휴대폰 번호, 이메일, 주민번호 등은 `encryptable` 플러그인으로 자동 암복호화, DB 필드는 `VARCHAR(500)`으로 암호문 저장

## API 레퍼런스

완전한 API 규격(통일 응답 형식, 비즈니스 오류 코드, ID 처리, API 버전, 속도 제한, 미들웨어 아키텍처, 인증 및 캡차 흐름)과 전체 엔드포인트 목록은 **[API 레퍼런스 문서](docs/API.md)**를 참조하세요.

## 프론트엔드 설명

### Flutter 관리 콘솔 (PC 스타일)

- **레이아웃**: 접이식 사이드바 (64px/240px) + 상단 바 + 콘텐츠 영역, 반응형 3단계 (폰/태블릿/데스크톱)
- **페이지**: 로그인, 대시보드, 사용자 관리, 역할·권한, 시스템 설정, 작업 로그, 개인 센터
- **상태 관리**: GetX (`ApiService` 싱글턴 + `AuthService` Token 영속화)
- **대시보드**: 통계 카드, 추세 꺾은선 그래프 (fl_chart), 파이 차트, 최근 작업 로그
- **내보내기**: Excel/PDF 내보내기, PDF에는 제거 불가능한 저작권 정보 포함
- **일괄 작업**: 다중 선택 일괄 삭제, 일괄 활성화/비활성화
- **테마**: Material 3 라이트/다크 듀얼 테마

### HarmonyOS 모바일 클라이언트

- **페이지**: 로그인, 대시보드, 사용자 목록/상세, 개인 센터
- **인증**: JWT Bearer + 401 시 자동 무감각 Token 갱신, 갱신 실패 시 자동으로 로그인 페이지 리다이렉트
- **저장**: Token은 AppStorage로 관리

## 개발 규칙

- 전역 함수/클래스 참조 시 앞에 `\`를 붙이지 않고 `use`로 통일하여 임포트
- 모든 PHP 파일 상단에는 저작권 고지가 포함되어야 함
- 모든 설정 파일에는 중국어 주석 설명이 포함되어야 함
- 데이터베이스 기본키는 애플리케이션 계층 snowflake로 생성해야 하며 자동증가 금지
- API 계층의 모든 파라미터와 응답의 ID는 hashids로 암복호화해야 함
- AdminPermission 미들웨어는 사용자 권한을 Redis에 캐시 (TTL=60s), N+1 쿼리 병목 제거

## 배포

### Docker Compose (권장)

프로젝트 루트에 `docker-compose.yml`이 제공되며 5개 서비스를 오케스트레이션합니다:

| 서비스 | 이미지 | 포트 |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | 로컬 `Dockerfile` 빌드 | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

PHP 이미지는 `Dockerfile`로 빌드되며 기본 이미지는 `php:8.3-cli`, OPcache가 활성화됩니다.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

GitHub Actions CI 파이프라인: `.github/workflows/ci.yml`

- PHP 문법 검사 (`php -l`)
- PHPUnit 단위 테스트
- Flutter 정적 분석 (`flutter analyze`)

### 데이터베이스 백업

`database/backup/` 디렉터리:

- `backup.sh` - mysqldump + gzip 백업, 30일 이전 백업 자동 정리
- `restore.sh` - 대화형 복원, 사용 가능한 백업을 나열해 선택

### Nginx 보안 설정

프로덕션 배포 시 `docs/nginx-security.conf`를 참조해 리버스 프록시 보안 강화를 구성하세요.

## 오픈소스는 어렵습니다. 응원해 주세요

| 위챗 | 알리페이 |
|:---:|:---:|
| ![위챗](./docs/weixinpay.png "위챗") | ![알리페이](./docs/alipay.png "알리페이") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
