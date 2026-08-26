# 보안 아키텍처 설계 문서

**语言 / Languages:** [中文](SECURITY.md) · [English](SECURITY.en.md) · [한국어](SECURITY.ko.md) · [Русский](SECURITY.ru.md) · [Deutsch](SECURITY.de.md) · [Français](SECURITY.fr.md) · [Español](SECURITY.es.md) · [Português](SECURITY.pt.md) · [हिन्दी](SECURITY.hi.md) · [العربية](SECURITY.ar.md) · [বাংলা](SECURITY.bn.md) · [Bahasa Indonesia](SECURITY.id.md) · [日本語](SECURITY.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. 심층 방어(Defense in Depth) 개요

시스템은 7계층 심층 방어 모델을 채택하여, 외부에서 내부로 악성 요청을 계층별로 걸러냅니다. 단일 계층이 무너져도 이후 방어선이 계속 작동하도록 보장합니다.

전체 미들웨어 체인은 다음 순서로 실행됩니다 (`config/middleware.php` 참조):

```
请求 → Cors → Locale(Accept-Language) → SecurityMiddleware (erikwang2013/security-php, 31种检测器) → RateLimit → [路由组中间件: AdminAuth → AdminPermission → OperationLog] → Controller
```

| 계층 | 미들웨어/메커니즘 | 방어 목표 |
|----|--------|---------|
| 1 | SecurityMiddleware (erikwang2013/security-php) | 31가지 공격 탐지 + HTTP 메서드 검증 + 요청 본문 크기 제한 + Content-Type 검증 + CSRF + IP 공격 승격 블랙리스트 |
| 2 | Cors | 교차 출처 보안 + 보안 응답 헤더 주입 |
| 3 | RateLimit | Redis 슬라이딩 윈도우 속도 제한, 무차별 대입 방지 |
| 4 | AdminAuth | JWT 인증 + 블랙리스트 로그아웃 |
| 5 | AdminPermission | RBAC method.path 단위 권한 검증 |
| 6 | OperationLog | 작업 감사 + 출처 클라이언트 추적 |
| 7 | 데이터 암호화 | Hashids ID 난독화 + Encryptable DB 암호화 + EncryptionService 전송 암호화 |

프론트엔드 계층(Flutter)에도 독립적인 입력 검증이 있으며, 백엔드는 어떤 것도 신뢰하지 않고 각 계층이 독립적으로 방어합니다.

---

## 2. 공격 탐지 엔진

## 2. 攻击检测引擎 (erikwang2013/security-php)

공격 탐지는 자체 개발 SecurityMiddleware에서 전용 보안 패키지 `erikwang2013/security-php` v1.1+로 이전되었으며, 5대 공격 범주를 포괄하는 **31가지 탐지기**를 제공합니다.

### 2.1 탐지기 분류

**주입 공격 (11가지):** XSS, SQL 인젝션, 명령어 인젝션, NoSQL 인젝션, LDAP 인젝션, XPath 인젝션, JNDI/Log4Shell, SSI 서버 사이드 인클루드, GraphQL 인젝션, SSTI 템플릿 인젝션

**프로토콜 및 요청 공격 (9가지):** SSRF, XXE, HTTP 응답 헤더 인젝션, Host 헤더 공격, Request Smuggling, Open Redirect, CORS 우회, WebSocket 하이재킹, DNS Rebinding

**HTTP 프로토콜 계층 검증 (6가지):** HTTP 메서드 검증(405), 요청 본문 크기 제한(413), Content-Type 검증(415), CSRF Origin 검사, IP 공격 승격 블랙리스트, 민감 데이터 유출 탐지

**데이터 및 직렬화 공격 (5가지):** PHP 역직렬화, CSV 수식 인젝션, 이메일 헤더 인젝션, JWT 공격(구조적 분석), JS Prototype Pollution

**파일 및 경로 공격 (2가지):** 경로 탐색, 악성 파일 업로드

### 2.2 처리 모드

각 탐지기는 독립적으로 두 가지 모드를 지원합니다:
- `block` — 공격 탐지 시 차단하고 설정된 상태 코드 반환
- `log` — 차단하지 않고 로그만 기록 (`header_injection`, `ssti`, `nosql_injection`은 오탐 방지를 위해 기본 log 모드)

### 2.3 IP 공격 승격 블랙리스트

동일 IP가 60초 내에 5회 공격 탐지를 트리거하면 자동으로 15분간 차단됩니다. 저장 백엔드는 Redis(분산), File(단일 노드 JSON), Cache(고동시성 독립 파일) 중 선택 가능하며, 현재 구성은 Redis 저장입니다.

### 2.4 보안 로그

파일 위치: `runtime/logs/security.log` (자동 로테이션, 파일당 10MB)

---

## 4. 보안 응답 헤더

모든 헤더는 `Cors` 미들웨어에서 주입되며 `$response->withHeaders()`를 통해 모든 응답에 추가됩니다.

| 헤더 | 값 | 역할 |
|----|-----|------|
| Access-Control-Allow-Origin | `*` | 모든 출처의 교차 출처 요청 허용 (내부망 관리 콘솔 시나리오) |
| Access-Control-Allow-Methods | `GET,POST,PUT,DELETE,OPTIONS` | 허용된 메서드 집합 |
| Access-Control-Allow-Headers | `Authorization,Content-Type,API-Version` | 허용된 커스텀 헤더 |
| Access-Control-Max-Age | `86400` | 프리플라이트 응답 24시간 캐시 |
| X-Content-Type-Options | `nosniff` | 브라우저 MIME 스니핑 방지 |
| X-Frame-Options | `DENY` | 모든 iframe 임베딩 금지, 클릭재킹 방지 |
| X-XSS-Protection | `1; mode=block` | 브라우저 내장 XSS 필터 활성화 및 페이지 렌더링 차단 |
| Referrer-Policy | `strict-origin-when-cross-origin` | 동일 출처는 전체 URL, 교차 출처는 도메인만 전송 |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | 전 사이트에서 카메라/마이크/위치 API 비활성화 |

OPTIONS 프리플라이트 요청은 빈 204 응답을 직접 반환하며 이후 미들웨어 체인에 진입하지 않습니다.

### 4.2 Content-Security-Policy (CSP)

다른 보안 헤더와 함께 Cors 미들웨어에서 주입되며, 브라우저가 로드하고 실행할 수 있는 리소스 출처를 제한하여 심층 방어를 제공합니다.

| 헤더 | 값 | 역할 |
|----|-----|------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | 스크립트/스타일/이미지/연결/프레임/폼 등 리소스 출처 제한 |
| X-Permitted-Cross-Domain-Policies | `none` | Adobe Flash/PDF 등 교차 도메인 정책 파일 로드 금지 |

CSP 정책 요점:
- `default-src 'self'`: 기본적으로 동일 출처 리소스만 허용
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'`: 동일 출처 스크립트 + 인라인 스크립트(Flutter Web 필수) + eval(Flutter Web 디버깅 필수) 허용
- `frame-ancestors 'none'`: 어떤 페이지의 iframe에도 임베딩 금지, X-Frame-Options: DENY와 이중 보호
- `base-uri 'self'`: `<base>` 태그를 동일 출처로만 제한
- `form-action 'self'`: 폼이 동일 출처로만 제출되도록 제한

---

## 5. 속도 제한 정책

### 알고리즘

Redis Sorted Set 슬라이딩 윈도우 + Lua 원자 스크립트, 주요 작업:

```lua
-- 1. 清理窗口外的旧记录
redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, windowStart)
-- 2. 检查当前窗口计数
local count = redis.call('ZCARD', KEYS[1])
-- 3. 超限则返回 {0, count}，未超限则 ZADD 并返回 {1, count+1}
if count >= limit then return {0, count} end
redis.call('ZADD', KEYS[1], now, now . '.' . random)  -- 随机后缀避免同毫秒覆盖
redis.call('EXPIRE', KEYS[1], window + 10)
```

Lua 스크립트는 Redis 서버에서 단일 스레드로 실행되어 **자연스럽게 원자적**이며, TOCTOU(Time-of-check to Time-of-use) 경쟁 조건을 제거합니다.

### 속도 제한 구성

| 라우트 | 제한 | 윈도우 | 시나리오 |
|------|------|------|------|
| 기본 (전체 라우트) | 60회/분 | 60s | 일반 API |
| `/api/auth/login` | 10회/분 | 60s | 로그인 (무차별 대입 방지) |
| `/api/auth/register` | 5회/분 | 60s | 회원가입 (대량 가입 방지) |

### 응답 헤더

속도 제한이 트리거되면 HTTP 429 및 JSON 본문이 반환됩니다:
```json
{"code": 429, "message": "请求过于频繁，请稍后再试", "data": []}
```

모든 응답(정상 응답 포함)은 다음 헤더를 전달합니다:

| 헤더 | 설명 |
|----|------|
| X-RateLimit-Limit | 현재 윈도우에서 허용된 최대 요청 수 |
| X-RateLimit-Remaining | 현재 윈도우에서 사용 가능한 남은 요청 수 |
| X-RateLimit-Reset | 윈도우가 초기화되는 Unix 타임스탬프 |
| Retry-After | 속도 제한 시에만 포함, 대기 권장 초 수 |

### 다운그레이드 전략

Redis 이상 시(연결 타임아웃, 사용 불가 등) **fail-open**:

```php
try {
    $result = Redis::eval($lua, ...);
} catch (\Throwable $e) {
    return $handler($request); // Redis down, 放行所有请求
}
```

짧은 시간 속도 제한 보호를 잃더라도 정상 비즈니스 요청을 차단하지 않습니다.

### 5.4 계정 잠금 메커니즘

로그인 인터페이스는 속도 제한에 더해 특정 사용자를 겨냥한 무차별 대입을 방지하기 위한 **계정 잠금** 메커니즘을 추가로 제공합니다.

**잠금 흐름**:

```
登录失败 → Redis INCR account_lockout:{userId} TTL=900s
连续 5 次失败 → Redis SETEX account_locked:{userId} 900 1
            → 返回 429 "账号已被锁定，请15分钟后再试"
            → 清除计数器 DEL account_lockout:{userId}
```

**잠금 기간 동작**:

잠금 기간 동안 모든 로그인 요청은 비밀번호 검증 없이 429를 직접 반환하여 무차별 대입 시도를 완전히 차단합니다.

**구성 상수**:

| 상수 | 값 | 의미 |
|------|-----|------|
| MAX_LOGIN_ATTEMPTS | 5 | 최대 연속 실패 횟수 |
| LOCKOUT_DURATION | 900 | 잠금 지속 시간(초), 즉 15분 |

참고: 계정 잠금은 IP가 아닌 `userId` 기준이므로 공격자가 IP를 바꿔도 우회할 수 없습니다. IP 속도 제한(10회/분)과 결합되어 이중 보호를 형성합니다:
- IP 계층: 10회/분 속도 제한으로 분산 무차별 대입 차단
- 계정 계층: 5회 실패 시 잠금으로 표적 무차별 대입 차단

---

## 6. 인증과 권한 부여

### 6.1 JWT 인증

AdminAuth 미들웨어로 구현되며 인증이 필요한 라우트 그룹에 마운트됩니다.

**파라미터 구성** (`config/plugin/erikwang2013/jwt/jwt`, `.env`에서 주입):

| 파라미터 | 값 | 설명 |
|------|-----|------|
| 알고리즘 | HS256 | HMAC-SHA256 대칭 서명 |
| 비밀키 | `JWT_SECRET` | 환경 변수로 주입, 프로덕션에서 교체 필요 |
| access_token TTL | 7200s (2h) | `JWT_TTL` |
| refresh_token TTL | 1209600s (14d) | `JWT_REFRESH_TTL` |
| 발급자 | `open-admin` | `JWT_ISSUER` |
| 대상 | `open-admin` | `JWT_AUDIENCE` |

**Token 추출**: `Authorization: Bearer <token>` 헤더에서 추출하며, `Bearer ` 접두사를 제거하면 원본 JWT를 얻습니다.

**인증 흐름**:
1. 빈 token → 즉시 401 `{"code": 401, "message": "未登录"}`
2. Redis 블랙리스트 `jwt_blacklist:{md5(token)}` 확인 → 적중 → 401 `Token已失效，请重新登录`
3. JWT decode → 실패(만료/서명 불일치) → 401 `Token已过期或无效`
4. 성공 → `$request->adminId`와 `$request->adminUsername` 주입

**블랙리스트 메커니즘**: 사용자가 로그아웃하면 `md5(token)`을 Redis에 기록하고 TTL을 JWT 잔여 유효 시간으로 설정합니다. Redis 장애 시 블랙리스트 확인이 건너뛰어지고(fail-open), 이때 로그아웃된 token도 짧은 기간 사용될 수 있지만 JWT 자체의 짧은 유효 시간(2h)이 최후의 보호선 역할을 합니다.

### 6.2 동시 세션 제한

Token 유출 후 다중 기기에서 악용되는 것을 방지하기 위해, 시스템은 동일 사용자가 동시에 보유할 수 있는 유효 Token 수를 제한합니다.

**제한 로직**:

```
登录成功 → 签发新 Token
         → 查询当前用户有效 Token 数量: Redis SCARD user_tokens:{userId}
         → 若数量 >= 3（MAX_CONCURRENT_SESSIONS）:
            → 按创建时间升序排列，移除最旧的 Token:
              Redis SREM user_tokens:{userId} <oldest_token_id>
              Redis SETEX jwt_blacklist:{md5(oldest_token)} TTL remaining
         → 将新 Token 加入集合: Redis SADD user_tokens:{userId} <new_token_id>
            Redis SET user_token:{token_id} {userId} EX {TTL}
```

**구성 상수**:

| 상수 | 값 | 의미 |
|------|-----|------|
| MAX_CONCURRENT_SESSIONS | 3 | 사용자당 최대 동시 Token 수 |

**강제 로그아웃 시나리오**: 사용자가 4번째 기기에서 로그인하면 1번째 기기의 Token이 강제로 블랙리스트에 추가되고, 이후 요청은 401 "Token已失效，请重新登录"을 반환합니다.

로그아웃 시 현재 Token이 집합에서 제거됩니다. Token이 자연 만료되면 Redis key가 자동으로 소멸하고 집합 구성원도 함께 줄어듭니다.

### 6.3 RBAC 권한 모델

AdminPermission 미들웨어로 구현됩니다.

**데이터 모델**: User -> Role -> Permission 3단계 연관

- `erik_admin_user` (사용자 테이블)
- `erik_admin_user_role` (사용자-역할 연관 테이블)
- `erik_admin_role` (역할 테이블)
- `erik_admin_role_permission` (역할-권한 연관 테이블)
- `erik_admin_permission` (권한 테이블)

**권한 유형**:
| type | 의미 | 예시 |
|------|------|------|
| 1 | 메뉴 권한 | 좌측 내비게이션 표시 여부 제어 |
| 2 | 버튼 권한 | 페이지 내 작업 버튼 제어 (추가/수정/삭제) |
| 3 | API 권한 | 백엔드 인터페이스 호출 제어 |

API 권한 식별자 형식: `{method}.{path}`

예시:
- `post.admin/user` — 사용자 생성
- `put.admin/user` — 사용자 수정
- `delete.admin/user` — 사용자 삭제
- `get.admin/user` — 사용자 목록 조회

**권한 검증 흐름**:
1. `$request->adminId`가 비어 있으면 → 통과 (라우트에 인증 선행 조건이 구성되지 않음)
2. 사용자 → 역할(`status=0`인 비활성 역할 건너뜀) → 권한 목록 조회
3. 슈퍼 관리자(`slug = '*'`) → 바로 통과
4. `strtolower(method) . '.' . trim(path, '/')` 구성 → 권한 목록과 비교
5. 불일치 → 403 `{"code": 403, "message": "无权限访问"}`

**2차 확인**: BaseController는 `confirmPassword()` 메서드를 제공하며, 민감 작업(사용자 삭제, 데이터 내보내기 등)은 Controller 계층에서 현재 비밀번호 입력을 추가로 요구하여 세션 하이재킹 후의 무단 작업을 방지합니다.

---

## 7. 감사 로그

### 7.1 작업 로그

OperationLog 미들웨어는 POST / PUT / DELETE 요청에 대해 작업 로그를 자동으로 기록합니다. GET 요청은 기록하지 않습니다.

**기록 필드**:

| 필드 | 출처 | 설명 |
|------|------|------|
| id | SnowflakeService::generate() | 전역 고유 ID |
| user_id | `$request->adminId` | 작업자 ID, 미로그인 시 0 |
| action | `$request->method()` | method와 동일 |
| method | `$request->method()` | POST / PUT / DELETE |
| path | `$request->path()` | 요청 경로 |
| ip | `$request->getRealIp()` | 클라이언트 실제 IP |
| source | detectSource() | 클라이언트 출처 플랫폼 |
| input | 요청 본문 (마스킹된 JSON) | 제출된 작업 데이터 |
| created_at | `date('Y-m-d H:i:s')` | 작업 시간 |

**민감 필드 필터링**: 요청 본문을 재귀적으로 순회하며 다음 필드의 값을 `***`로 대체합니다:

`password`, `old_password`, `new_password`, `new_password_confirmation`, `token`, `secret`, `access_token`, `refresh_token`

**출처 감지** (`detectSource()`): 우선순위에 따라:

1. `X-Client-Platform` 커스텀 헤더 우선 읽기 (네이티브 클라이언트가 명시적으로 선언)
2. User-Agent 문자열 추론으로 폴백 (`detectSource()` 메서드 감지 순서):

| 플랫폼 | UA 키워드 |
|------|----------|
| iPadOS | `iPad` |
| macOS | `Macintosh`, `Mac OS` |
| Windows | `Windows` |
| Linux | `Linux` |
| iOS | `iPhone`, `iOS`, `CFNetwork` |
| Android | `Android` |
| HarmonyOS | `HarmonyOS`, `OpenHarmony` |
| Web | 폴백 기본값 |

**내결함성**: 로그 쓰기 예외는 비즈니스 요청을 차단하지 않습니다 (`catch (\Throwable)`로 조용히 무시).

### 7.2 보안 로그

**파일 위치**: `runtime/logs/security.log`

**기록 내용**:
- 공격 차단 로그: 공격 범주, IP, 경로, 필드, 출처, payload 일부(앞 200자)
- IP 차단 알림: 차단된 IP, 트리거 횟수

로그 권한은 `FILE_APPEND | LOCK_EX`로 동시성 안전 쓰기를 보장합니다.

---

## 8. 데이터 보호

시스템은 데이터 흐름의 세 단계에 대응하는 3계층 데이터 보호 전략을 채택합니다.

### 8.1 전송 계층 — EncryptionService

`EncryptionService`는 `erikwang2013/encryption` 패키지를 사용하여 API 요청/응답의 민감 필드를 암호화/복호화합니다.

**기술 세부 사항**:
- 알고리즘: `aes-256-cbc-hmac` (HMAC 서명 내장으로 변조 방지)
- 키: `ENCRYPTION_KEY` 환경 변수, 자동으로 32바이트 정렬
- 용도: 클라이언트와 API 간 휴대폰 번호, 주민등록번호 등 필드 전송

**마스킹 유틸리티 메서드**:
- `maskPhone('13812341234')` → `138****1234`
- `maskEmail('abc@example.com')` → `a***@example.com` (사용자명 2자 초과) 또는 `a**@example.com`

### 8.2 저장 계층 — Encryptable Cast

`AdminUser` 모델은 `Erikwang2013\Encryptable\Encryptable` Eloquent cast를 사용하며, 해당 필드는 다음과 같습니다:

- `email` → Encryptable로 cast, 자동 암호화/복호화
- `phone` → Encryptable로 cast, 자동 암호화/복호화
- `id_card` → Encryptable로 cast, 자동 암호화/복호화

데이터베이스에 쓸 때 자동으로 암호문으로 암호화되고, 읽을 때 자동으로 평문으로 복호화됩니다. 데이터베이스 저장 컬럼 타입은 `VARCHAR(500)`이며 암호문은 base64 형태로 저장됩니다.

**키 체계**: 전송 계층 암호화(`ENCRYPTION_KEY`)와 독립적으로 `ENCRYPTABLE_KEY`를 사용하며, 한 키가 유출되어도 다른 계층은 무너지지 않습니다.

키 로테이션: `ENCRYPTION_PREVIOUS_KEYS` 환경 변수가 과거 키 목록(쉼표 구분)을 지원합니다. 과거 데이터를 읽을 때 과거 키로 복호화를 시도하고, 다시 쓸 때는 현재 키로 재암호화합니다.

### 8.3 표시 계층 — ID 난독화와 마스킹

**Hashids ID 난독화**: `HashidsService`는 `erikwang2013/hashids` 패키지를 사용합니다.

- 외부 API가 반환하는 DB BIGINT ID를 hash 문자열로 인코딩 (예: `xK3mN9qR2pL7wV8b`)
- 클라이언트가 요청 시 hash 문자열을 전달하면 백엔드가 자동으로 원본 ID로 디코딩
- 소금값은 `HASHIDS_SALT` 환경 변수로 주입되며, 소금값이 다르면 인코딩/디코딩 결과가 완전히 달라짐
- hash 최소 길이 16자, 62자 영숫자 문자셋 사용
- BaseController가 `encodeId()`, `decodeId()`, `encodeIds()` 편의 메서드 제공

**내보내기 마스킹**: Excel/PDF 내보내기 시(ExportController) 민감 필드를 일괄 마스킹합니다:
- 휴대폰 번호: `138****1234`
- 이메일: `a***@example.com`
- 주민등록번호: `********`으로 완전 가림

---

## 9. 키 관리

모든 키는 `.env` 환경 변수로 주입되며, 구성 파일은 `getenv()`로 읽고 내장 폴백 기본값을 갖습니다 (개발 환경에서만 안전).

| 환경 변수 | 용도 | 패키지 | 프로덕션 요구 사항 |
|----------|------|-----|---------|
| JWT_SECRET | JWT 서명 키 | erikwang2013/jwt-webman | 64자 이상 랜덤 문자열 |
| JWT_ALGORITHM | JWT 서명 알고리즘 | 동일 | HS256 유지 |
| HASHIDS_SALT | ID 인코딩 소금값 | erikwang2013/hashids | 랜덤 문자열 |
| SNOWFLAKE_DATACENTER_ID | 데이터센터 ID (0-31) | erikwang2013/snowflake-php | 단일 IDC면 기본값 유지 |
| ENCRYPTION_KEY | API 전송 계층 암호화 키 | erikwang2013/encryption | 32바이트 랜덤 문자열 |
| ENCRYPTABLE_KEY | DB 저장 계층 암호화 키 | erikwang2013/encryptable | 32바이트 랜덤 문자열, 전송 키와 상이 |

**보안 요구 사항**:
- `.env` 파일은 `.gitignore`에 포함되어 있으며 저장소에 커밋 금지
- `.env.example`은 공개 템플릿 파일로 실제 키를 포함하지 않음
- 프로덕션 환경에서 **반드시** 모든 기본 키를 랜덤 문자열로 교체
- `openssl rand -base64 32`로 키 생성 권장

### 키 저장 격리

| 계층 | 구성 키 | 키 환경 변수 |
|----|--------|-------------|
| 전송 암호화 | `config/encryption.php` → `key` | `ENCRYPTION_KEY` |
| 저장 암호화 | `config/encryptable.php` → `key` | `ENCRYPTABLE_KEY` |
| ID 난독화 | `config/hashids.php` → `connections.main.salt` | `HASHIDS_SALT` |
| JWT 서명 | `config/plugin/erikwang2013/jwt/jwt` | `JWT_SECRET` |

---

## 10. security.txt (RFC 9116)

시스템은 `/.well-known/security.txt`에 RFC 9116 표준을 준수하는 보안 연락처 엔드포인트를 제공하여, 보안 연구자가 취약점 발견 시 신고 경로를 빠르게 찾을 수 있도록 합니다.

**접근 방식**:

```
GET /.well-known/security.txt
```

**응답 내용**:

```text
Contact: mailto:security@erik.xyz
Expires: 2027-05-20T00:00:00.000Z
Preferred-Languages: zh, en
Canonical: https://erik.xyz/.well-known/security.txt
Policy: https://erik.xyz/security-policy
```

**필드 설명**:

| 필드 | 설명 |
|------|------|
| Contact | 보안 취약점 신고 연락처 |
| Expires | 파일 만료 시간, 주기적 갱신 필요 |
| Preferred-Languages | 선호 소통 언어 |
| Canonical | 이 파일의 표준 URL |
| Policy | 보안 정책/취약점 공개 정책 링크 |

이 엔드포인트는 속도 제한, 인증 등 미들웨어의 제한을 받지 않으며 누구나 직접 접근할 수 있습니다.

---

## 11. Nginx 보안 구성

프로젝트는 프로덕션 환경 Nginx 리버스 프록시의 보안 강화 참조 구성으로 `docs/nginx-security.conf`를 제공합니다.

**포함된 보안 조치**:

| 구성 항목 | 역할 |
|--------|------|
| `server_tokens off` | Nginx 버전 번호 숨기기 |
| `client_max_body_size 10m` | 요청 본문 크기 제한, SecurityMiddleware (erikwang2013/security-php)와 협력 |
| `limit_req_zone` | Nginx 계층의 요청 빈도 제한 |
| `limit_conn_zone` | 동시 연결 수 제한 |
| `add_header` 보안 헤더 | Nginx 계층에서 X-XSS-Protection 등 보안 헤더 추가 |
| `if ($request_method)` | Nginx 계층에서 비표준 HTTP 메서드 거부 |
| SSL/TLS 구성 | 최신 TLS 1.2/1.3 구성, 취약 암호화 스위트 비활성화 |
| 백엔드 헤더 숨기기 | `proxy_hide_header`로 webman 버전 등 민감 헤더 제거 |

**사용 방법**: `docs/nginx-security.conf`의 구성을 Nginx server 블록에 병합하고 실제 도메인과 인증서 경로에 맞게 조정합니다.

---

## 12. 위협 모델

### 12.1 방어된 위협

| 위협 유형 | 공격 벡터 | 방어 계층 |
|----------|---------|---------|
| HTTP 메서드 남용 | TRACE/TRACK XST 공격, CONNECT 터널 프록시, WebDAV 메서드 탐지 | SecurityMiddleware http_method 탐지기 405 메서드 화이트리스트 |
| 표적 무차별 대입 | 특정 사용자 대상 반복 비밀번호 시도 | 계정 잠금 (5회 실패 시 15분 잠금) + RateLimit (로그인 10/min) + Captcha |
| 무차별 대입 | 분산 IP의 사용자명/비밀번호 반복 시도 | RateLimit (로그인 10/min) + Captcha |
| XSS 크로스 사이트 스크립팅 | `<script>`, onerror, javascript: | SecurityMiddleware (erikwang2013/security-php) (5가지 패턴) + X-XSS-Protection 응답 헤더 + CSP |
| SQL 인젝션 | UNION SELECT, OR 1=1, 주석 우회 | SecurityMiddleware (erikwang2013/security-php) (6가지 패턴) + Eloquent ORM 파라미터화 쿼리 |
| CSRF 크로스 사이트 요청 위조 | 악성 사이트의 대리 요청 | SecurityMiddleware (erikwang2013/security-php) Origin/Referer 검증 |
| 경로 탐색 | `../../etc/passwd` | SecurityMiddleware (erikwang2013/security-php) 경로 탐색 패턴 + UploadController 확장자 화이트리스트 |
| 명령어 인젝션 | `;ls`, `` `whoami` ``, `$(cat ...)` | SecurityMiddleware (erikwang2013/security-php) (4가지 패턴) |
| 세션 하이재킹 | JWT Token 탈취 | JWT 단기 유효 (2h) + 블랙리스트 로그아웃 + 민감 작업 2차 비밀번호 확인 |
| ID 열거 | 숫자 ID 순회로 데이터 규모 추측 | Hashids로 랜덤 문자열 난독화 |
| 데이터 유출 | DB 유출 / 중간자 / 로그 유출 | 3계층 암호화/마스킹 + OperationLog 민감 필드 필터링 |
| DoS 공격 | 초대형 요청 본문 / 고빈도 요청 | 요청 본문 10MB 제한 + RateLimit 60/min + IP 블랙리스트 |
| 권한 상승 | 저권한 사용자의 관리 인터페이스 접근 | RBAC method.path 단위 권한 검증 |
| 파일 업로드 공격 | shell.php.png 이중 확장자 | SecurityMiddleware (erikwang2013/security-php) 악성 파일 탐지 |

### 12.2 알려진 한계

| 한계 | 영향 범위 | 완화 조치 |
|------|---------|---------|
| CSRF 보호는 브라우저에서만 유효 | 비브라우저 클라이언트(curl, Postman, 모바일 앱)는 Origin/Referer 검사 건너뛸 수 있음 | 비브라우저 클라이언트는 본질적으로 CSRF에 면역; Cookie 대신 JWT 인증에 의존 |
| Redis 사용 불가 시 속도 제한·블랙리스트가 fail-open으로 다운그레이드 | 공격자가 속도 제한·고빈도 차단 우회 가능 | Redis 가용성 모니터링 알람; IP 블랙리스트는 file/redis/cache 3가지 백엔드로 다운그레이드 가능 |
| 독립 WAF 엔진 없음 | 정규식 기반 탐지, 전용 WAF 규칙 엔진 아님 | 프로덕션에서 전단에 Nginx ModSecurity 또는 Cloudflare WAF 권장 |
| JWT 무상태로 능동 무효화 불가 | 만료 전에는 서버에서 능동적으로 폐기할 수 없음 (블랙리스트 제외) | 블랙리스트 + 단기 2h TTL로 위험 윈도우 축소 |
| 관리자 엔드포인트에 특별 속도 제한 없음 | 관리자 API가 일반 API와 기본 60/min 제한 공유 | 관리자 작업 빈도는 자연적으로 낮아 현재 구분 불필요 |
| PCRE 백트래킹 제한 | 패키지 내장 1,000,000 백트래킹 상한 + finally 복구, 극단적으로 복잡한 입력은 여전히 성능 위험 | 요청 본문 크기 제한(10MB)이 최후 보루 |
