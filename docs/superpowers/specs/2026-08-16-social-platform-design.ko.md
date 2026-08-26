# 소셜 플랫폼 종합 설계 (Social Platform Design)

**语言 / Languages:** [中文](2026-08-16-social-platform-design.md) · [English](2026-08-16-social-platform-design.en.md) · [한국어](2026-08-16-social-platform-design.ko.md) · [Русский](2026-08-16-social-platform-design.ru.md) · [Deutsch](2026-08-16-social-platform-design.de.md) · [Français](2026-08-16-social-platform-design.fr.md) · [Español](2026-08-16-social-platform-design.es.md) · [Português](2026-08-16-social-platform-design.pt.md) · [हिन्दी](2026-08-16-social-platform-design.hi.md) · [العربية](2026-08-16-social-platform-design.ar.md) · [বাংলা](2026-08-16-social-platform-design.bn.md) · [Bahasa Indonesia](2026-08-16-social-platform-design.id.md) · [日本語](2026-08-16-social-platform-design.ja.md)

- 날짜: 2026-08-16
- 상태: 확정, 구현 대기
- 범위: 이미지+텍스트 숏콘텐츠 커뮤니티 + 인스턴트 메시징 + 라이브/음성 + 가상 경제, 다국어, 글로벌 다지역

## 1. 목표와 범위

이미지+텍스트 숏콘텐츠와 IM을 결합한 소셜 플랫폼을 구축한다. 라이브 스트리밍(비디오+탄막+연결 방송), 음성(메시지/1v1 통화/보이스 채팅방), 선물 후원형 가상 경제를 포함한다. UI 다국어, 콘텐츠 번역, 다지역 컴플라이언스를 지원하고 전 세계 다지역에 배포한다. iOS / Android / HarmonyOS 3개 플랫폼에서 병렬 네이티브 개발을 진행한다.

## 2. 시스템 개요

```
                    ┌─────────────────────────────────────────────┐
                    │   iOS (SwiftUI) │ Android (Kotlin+Compose)  │
                    │            HarmonyOS (ArkTS)                │
                    └───────┬─────────────────────────┬───────────┘
                            │  HTTPS / WSS（多区域就近接入）
                  ┌─────────▼──────────┐   ┌──────────────────────┐
                  │  CDN + 区域接入层   │   │ 厂商推送 APNs/FCM/华为 │
                  └─────────┬──────────┘   └──────────────────────┘
              ┌─────────────▼─────────────┐
              │   service (webman v2)     │──gRPC──▶ infrastructure
              │  业务单体：认证/资料/动态/ │          (bee-rust)
              │  点赞/评论/关注/IM 网关/   │  gRPC      搜索/推荐/图/
              │  翻译调度/审核/直播/语音/  │  ▲        时序/热数据
              │  虚拟经济                 │  │ gRPC
              └─────────────┬─────────────┘  │
                  ┌─────────▼─────────┐      │
                  │ MySQL + Redis     │      │
                  │ S3 对象存储       │      │
                  └───────────────────┘      │
              ┌──────────────────────────────┴──┐
              │   admin (open-admin 改造, webman) │
              │  审核台/举报/GDPR/看板/词条/礼物库/ │
              │  直播配置/提现审核                 │
              └──────────────────────────────────┘

  media/（自建媒体层，信令走 service WS 网关）
  ├── sfu/    mediasoup：1v1 通话、语聊房
  ├── srs/    SRS：自建直播（RTMP → FFmpeg 转码 → FLV/HLS）
  └── coturn/ TURN 中继

  外部：第三方直播云（推流/转码/CDN/实时审核）、第三方 RTC（连麦）、
        第三方审核 API、商店 IAP（App Store / Google Play / 华为）
```

## 3. 서브시스템 책임

### 3.1 contracts (gRPC 계약, 신규 최상위 디렉터리)

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- 생성 파이프라인: CI에서 buf로 3종 스텁을 생성해 각각의 하위 리포지토리에 커밋한다(빌드가 네트워크에 의존하지 않음)
  - service/, admin/ → PHP 스텁 (grpc/grpc + google/protobuf)
  - infrastructure/ → Rust 스텁 (tonic)
- 버전 규칙: 필드 추가만 가능하고 수정·삭제 불가, 패키지명에 major 버전 포함 (`social.user.v1`)

### 3.2 service (webman v2) — 사용자 측 비즈니스 모놀리스

- **API 도메인**: auth (JWT 이중 토큰 + 블랙리스트), profile, posts, likes, comments, follows, IM (세션/메시지/WS 게이트웨이), notifications, 번역 스케줄링, 라이브방송방/탄막/연결방송 시그널링, 음성통화/보이스 채팅방 시그널링, 가상 경제 (지갑/선물/IAP 검증/수익 배분), GDPR 내보내기/삭제
- **다국어 오류 체계**: 오류는 `{code, lang_key, params}`를 반환하고, 문구는 클라이언트가 locale별로 렌더링
- **큐** (redis-queue): 심사 트리거, 번역 스케줄링, 푸시 전달, 비동기 통계, 선물 효과 브로드캐스트
- **정기 작업** (webman-crontab): 번역 사전 워밍, 만료 토큰/메시지 정리, 감사 아카이빙, 수익 배분 정산
- **ID**: `erikwang2013/snowflake-php` (admin과 동일)
- **계약**: OpenAPI 3.0 자동 내보내기 → 3개 플랫폼용 타입드 클라이언트 생성

### 3.3 infrastructure (bee-rust) — 고처리량 계산 계층

비즈니스 주 데이터를 저장하지 않으며(MySQL이 유일한 진실 공급원), 계산/조회 부하가 큰 기능을 담당한다:

- `bee_search`: 게시물/사용자 전문 검색 (중국어 형태소 분석, 다국어 인덱싱)
- `bee_graph`: 소셜 그래프 → 추천 피드
- `bee_tsdb`: DAU, 발행, 상호작용, 라이브 시청, 음성통화 시간 등 시계열 통계
- `bee_cache/bee_kv`: 타임라인 캐시, 카운터 (좋아요 수, 조회 수, 온라인 인원)
- 지역별로 배포, 읽기 위주·쓰기 적음, 데이터는 중앙에서 복제

### 3.4 admin (open-admin 개조)

**재사용**: JWT/RBAC/감사/파일 관리/헬스 체크/중영 i18n 인프라

**신규**:
- 콘텐츠 심사 워크벤치: 게시물/댓글/이미지 양국어 대조 심사, 거절 사유 다국어 템플릿, 사용자 제재
- 신고 처리 큐
- GDPR 요청 데스크 (내보내기/삭제 티켓)
- bee_tsdb 연동 데이터 대시보드
- i18n 용어 관리 (4개 클라이언트 공용 용어 CRUD)
- 선물 카탈로그 관리 (SKU, 가격, 효과, 다국어 이름)
- 라이브 provider 설정 (라우팅 전략, 전환 순서)
- 출금 신청 심사

### 3.5 media (자체 구축 미디어 계층, Node.js + 시스템 서비스)

- `sfu/`: mediasoup, 1v1 통화와 보이스 채팅방의 미디어 플레인 담당; 미디어 중계만 하고 비즈니스는 하지 않음
- `srs/`: SRS 자체 구축 라이브, RTMP 푸시 → FFmpeg 트랜스코딩 → HTTP-FLV/HLS 배포
- `coturn/`: TURN 릴레이, NAT 통과 폴백
- 시그널링은 모두 service의 WS 게이트웨이를 통해 전달

### 3.6 apps — 3개 플랫폼 네이티브 병렬 개발

- OpenAPI 계약 공유, 각 플랫폼이 독립적으로 타입드 클라이언트 생성
- 통합 인프라 모듈: 네트워크 계층 (재시도/인증 갱신), WS 클라이언트 (IM/탄막/통화 시그널링), i18n (로컬 리소스 + 원격 용어 증분), 푸시 등록, 테마
- HarmonyOS 유의점: Huawei Push Kit, ArkTS 동시성 모델 적응

## 4. 백엔드 통신 (gRPC)

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| 호출자 → 피호출자 | 전달 내용 |
|------|------|
| service → infra | 전문 검색, 추천 피드, 타임라인 핫 캐시, 카운터 읽기/쓰기, 시계열 통계 기록 |
| admin → infra | 대시보드 통계 조회, 백엔드 검색 |
| admin → service | 사용자 제재, 콘텐츠 삭제, 심사 결과 전달 |
| service → admin | 신고 이벤트, 심사 작업 큐 등록 (비동기) |

경계: 3개 플랫폼 앱과 관리자 프런트엔드(Flutter)는 HTTPS REST + WS를 사용하며 gRPC를 직접 다루지 않는다.

**운영 전제**: PHP 측 gRPC는 공식 `grpc` 확장(C 확장) + `grpc/grpc` composer 패키지가 필요하며, 서버 모드는 workerman 공식 walkor/grpc 방식을 따른다. 배포 문서에 명시해야 한다.

## 5. 다국어 아키텍처 (3계층)

| 계층 | 방안 |
|----|------|
| **UI 계층** | 플랫폼별 locale 리소스 (zh/en으로 시작, 체계는 모든 언어 지원); 서버는 오류 코드 + 템플릿 key만 전송 |
| **콘텐츠 계층** | 발행 시 원문 저장 + 자동 언어 감지로 `lang` 필드 기록; 조회 시 reader.lang ≠ author.lang → 번역 서비스 (LLM/MT provider 추상화), 결과는 Redis 캐시 (bee_cache, TTL), `is_translated` 표시로 원문 복귀 가능; 인기 콘텐츠는 정기적으로 번역 사전 워밍 |
| **컴플라이언스 계층** | 심사 규칙을 지역별로 적용 (EU GDPR 규칙 vs 기타 지역); 신고/심사 UI 이중 언어 |

탄막은 실시간 짧은 텍스트로 콘텐츠 번역을 하지 않고, UI i18n + 다국어 민감어 필터만 적용한다.

## 6. IM 아키텍처

- **게이트웨이**: webman WS 게이트웨이, 다중 인스턴스 + Redis pub/sub 크로스 노드 전달, `client_msg_id` 멱등 중복 제거
- **데이터**: conversations / conversation_members / messages / message_reads; 1v1 + 그룹 채팅 (그룹 상한 500)
- **전달**: 온라인 → WS 직접 푸시; 오프라인 → APNs/FCM/华为(화웨이) 푸시
- **기능**: 읽음 확인, 입력 중 표시, 시간 제한 철회, 이미지/음성 메시지 (S3 업로드 + 트랜스코딩)
- 피드와 사용자 체계 및 알림 체계를 공유

## 7. 라이브 아키텍처 (비디오 + 탄막 + 연결방송, 이중 트랙)

### 7.1 Provider 추상화 (service 내부)

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| 메커니즘 | 설계 |
|------|------|
| 라우팅 전략 | 방송방 생성 시 지역별 기본 provider 선택 (admin이 재정의 가능); 제3자 커버리지가 없거나 비용 민감 지역 → 자체 구축 |
| 장애 복구 | 스트리머 SDK 이중 푸시 (주=제3자, 예비=자체 SRS); 시청자는 provider별로 URL을 해석하고 제3자 장애 시 자체 스트림으로 자동 전환 |
| 탄막/연결방송 | 비디오 파이프라인과 분리: 탄막은 service WS, 연결방송은 제3자 RTC |
| 컴플라이언스 | 자체 구축 파이프라인의 실시간 음성/영상 심사는 제3자 심사 API 재사용 (심사만 구매, 전송은 아님) |

### 7.2 라이브방송방

방 CRUD, 시작/종료 상태 머신, 커버, 공지 (다국어), 시청 카운트 (bee_tsdb), 탄막 방 채널 (Redis pub/sub), 연결방송 역할 관리 (호스트/연결방송 자리, service가 제3자 RTC token 발급), 온라인/최대/시간 통계 → admin 대시보드.

## 8. 음성 아키텍처 (3종 세트)

| 형태 | 구현 |
|------|------|
| 음성 메시지 | IM 메시지 타입 확장: S3 저장 + 트랜스코딩 (m4a) + 길이 |
| 1v1 통화 | 시그널링은 WS 게이트웨이 (offer/answer/ICE), 벨소리/수신/종료 상태 머신 (Redis), 미디어 플레인은 mediasoup, 통화 기록 저장 |
| 보이스 채팅방 | 방 관리는 라이브방송방 패턴 재사용, 마이크 켜기/끄기/청취자는 service가 상태 관리, 미디어 플레인은 mediasoup |

## 9. 가상 경제 (충전 + 선물 후원 + 출금)

```
移动端 IAP（App Store/Google Play/华为）──┐
国内：微信支付 / 支付宝（APP/H5）          ├─▶ PaymentProvider ─▶ 钱包
国外：微信国际 / 支付宝国际 / Stripe / PayPal│    （按 region 选路）
                                          └─▶ payments 支付单（幂等+验签+对账）
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
主播钱包 ──▶ payouts 提现单 ──▶ 国内：商家转账 │ 国外：Stripe Connect/PayPal
```

### 9.1 결제 채널 (국내외 구분)

```
PaymentProvider 接口（admin 配置）
├── 国内（CNY）
│   ├── wechat_cn    微信支付（APP/H5）
│   ├── alipay_cn    支付宝（APP/WAP）
│   └── 提现：商家转账（零钱/银行卡）
├── 国外（USD/EUR/...）
│   ├── wechat_global  微信国际支付（境外商户）
│   ├── alipay_global  支付宝国际（Alipay+）
│   ├── stripe         卡 / Apple Pay / Google Pay / SEPA
│   ├── paypal
│   └── 提现：Stripe Connect / PayPal 批量打款
└── 移动端虚拟币充值：App Store / Google Play / 华为 IAP（商店政策强制，服务端凭证校验）
```

| 메커니즘 | 설계 |
|------|------|
| 채널 라우팅 | 사용자 region + 통화 + admin 가맹점 규칙으로 채널 선택, 폴백 순서 설정 가능 (국내외 자연 분리) |
| 결제 주문 | payments 통합 모델: 사용자/채널/금액/통화/상태 머신, 전 채널 멱등 |
| 콜백 | 통합 서명 검증 래퍼 (RSA/HMAC), 콜백 멱등, 일별 대사 작업 (채널 정산서 대조) |
| 출금 | payouts 출금 주문: 국내는 가맹점 이체, 해외는 Stripe Connect/PayPal 지급; 채널 역량에 따라 분배/대행 모드 선택 |
| 가격 | 지역 가격표 (admin): 가상 화폐 × 통화 가격, 환율 중앙 관리 |
| 리스크 관리 | 한도/빈도 제한/이상 주문 경보, 전체 흐름 감사 (감사 체계 재사용) |
| 선물 SKU | 선물 카탈로그 (가격, 효과 식별자, 다국어 이름)를 admin이 관리 |

컴플라이언스: 모바일 가상 화폐 충전은 반드시 스토어 IAP를 거쳐야 하며 (Apple/Google/华为 수수료), WeChat/Alipay는 H5/Web 및 특정 지역 시나리오에 사용한다. 출금은 자금 정산과 관련되므로 플랫폼은 라이선스 보유 채널의 분배/대행 인터페이스로 처리하고, 채널 계약 자격은 M6b 이전에 확인한다. 미성년자 한도는 컴플라이언스 단계에서 확정한다.

## 10. 핵심 데이터 모델

- 사용자: users, user_profiles (다국어 필드)
- 소셜: follows, posts, post_translations, comments, comment_translations, likes, reports
- IM: conversations, conversation_members, messages, message_reads
- 라이브: live_rooms, live_streams (provider 포함), danmaku_archive
- 음성: call_records, voice_rooms, voice_room_members
- 가상 경제: wallets, currency_transactions, gift_catalog, gifts_given, streamer_earnings, withdrawals, payments, payouts, price_plans (지역 가격/환율), merchant_configs (채널 가맹점 설정), products (IAP SKU)
- 플랫폼: i18n_terms (4개 클라이언트 공용 용어), moderation_queue, provider_configs, audit_logs

## 11. 데이터베이스 및 스토리지 선정

| 용도 | 스토리지 | 적용 컴포넌트 |
|------|------|----------|
| 비즈니스 주 데이터 (사용자/게시물/IM/지갑/심사/신고) | MySQL 8 (중앙 마스터 + 지역 읽기 전용 레플리카) | service와 admin 공용, 유일한 진실 공급원 |
| 핫 데이터/세션/온라인 상태/카운터/탄막 채널/통화 상태 머신 | Redis 7 | bee_kv / bee_cache (redis 기능) |
| 전문 검색 (게시물/사용자 검색, admin 백엔드 검색) | OpenSearch (단일 노드 시작) | bee_search (opensearch 기능) |
| 시계열 통계 (DAU/추이/라이브 시청/통화 시간/대시보드) | QuestDB (단일 바이너리 시작) | bee_tsdb (questdb 기능, influxdb로 교체 가능) |
| 소셜 그래프 → 추천 피드 | Neo4j 커뮤니티 에디션 (단일 노드 시작) | bee_graph (neo4j 기능, nebulagraph로 교체 가능) |
| 객체 파일 (이미지/비디오/음성/내보내기 패키지) | S3 (MinIO 또는 클라우드 업체) | service 직접 연결 + CDN 배포 |
| 감사 로그 | MySQL audit_logs, 만료 시 객체 스토리지로 아카이빙 | admin 감사 체계 재사용 |

선택 원칙: bee-rust 각 컴포넌트는 기능 플래그 추상화로 단일 노드에서 시작하고 규모가 커지면 분산 백엔드로 교체하며, 특정 제품에 묶이지 않는다. MySQL은 항상 유일한 진실 공급원이고 계산 계층(인덱스/통계/그래프/캐시)은 재구성 가능한 파생 데이터만 저장한다. 관리자 프런트엔드(Flutter)는 데이터베이스에 직접 접근하지 않고 전부 admin 백엔드를 경유한다.

## 12. 배포 및 운영 (글로벌 다지역)

- **초기 아키텍처**: 중국 지역 + 해외 지역 두 개 대역; 각 지역은 webman 클러스터 + bee-rust 클러스터 + 로컬 Redis + media (SFU/SRS/TURN); MySQL 중앙 마스터 + 각 지역 읽기 전용 레플리카; CDN 지역 분리
- **WS 최인접 접속**: 지역별 가장 가까운 노드로 접속, 지역 간 메시지는 중앙에서 조율, 푸시는 지역별 해당 업체
- **진화 경로**: 트래픽 증가 후 사용자 hash 기준 분산 샤딩
- **모니터링**: Prometheus metrics (open-admin 패턴 계승), 중앙 집중 로그, 알림 (오류율/지연/큐 적체/미디어 서비스 상태)

## 13. 보안 및 컴플라이언스

- service는 open-admin의 18계층 방어 패턴 복제 (XSS/SQLi/CSRF/속도 제한/CSP)
- 심사 파이프라인: 발행 시 다국어 민감어 → 이미지/음성·영상 심사 (제3자 API) → 수동 심사 워크벤치
- GDPR: 데이터 내보내기, 탈퇴/삭제 권리, 로그 보존 정책, 미성년자 연령 제한, 지역 규칙 차별화

## 14. 마일스톤 (1인 풀스택, 약 9–10개월)

| 단계 | 내용 | 기간 |
|------|------|------|
| M0 기반 | monorepo 골격, contracts(gRPC)+3개 플랫폼 스텁 생성+엔드투엔드 헬스 프로브, 3개 플랫폼 프로젝트 초기화, CI (build+test), bee-rust 서비스 골격 | 1–2주 |
| M1 폐루프 | 회원가입/로그인/프로필, 게시물/상세, 단순 타임라인, 좋아요/댓글 | 3–4주 |
| M2 소셜 완성 | 팔로우 체계, 전체 피드, 전문 검색 (bee_search), 알림 | 3–4주 |
| M3 IM | WS 게이트웨이, 세션, 메시지, 오프라인 푸시, 읽음/철회 | 4–6주 |
| M4 음성 | media 컴포넌트 (mediasoup+coturn), 음성 메시지, 1v1 통화, 보이스 채팅방 | 4–5주 |
| M5a 라이브 주력 | 제3자 파이프라인, 라이브방송방, 탄막, 연결방송 | 3–4주 |
| M5b 라이브 보강 | 자체 SRS 연동, 이중 푸시 복구, 라우팅 설정 | 2주 |
| M6a 가상 화폐+선물 | IAP, 지갑, 선물, 수익 배분 | 2–3주 |
| M6b 결제 채널 | WeChat/Alipay/WeChat 국제/Alipay 국제/Stripe/PayPal, 출금, 대사 | 3–4주 |
| M7 다국어+컴플라이언스 | 전 플랫폼 i18n, 콘텐츠 번역, 심사 워크벤치, GDPR, 음성·영상 심사 연동 | 3–4주 |
| M8 출시 | 2지역 배포 (지역 TURN 포함), 모니터링/알림, 부하 테스트, 보안 재점검 | 2–3주 |

각 마일스톤은 독립적으로 출시 가능한 조각이며, 중간에 멈춰도 제품이 항상 온전하게 사용 가능하다.

## 15. 기술 스택 요약

| 서브시스템 | 기술 |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / grpc 확장 / snowflake-php |
| infrastructure | Rust / bee-rust workspace (search/graph/tsdb/kv/cache) / tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| 외부 | 제3자 라이브 클라우드, 제3자 RTC, 제3자 심사 API, WeChat Pay/Alipay/WeChat 국제/Alipay 국제/Stripe/PayPal, App Store/Google Play/华为 IAP, APNs/FCM/华为 푸시 |

## 16. 팀 계획 (실제 인력, 안정적인 페이스)

### 16.1 조직 구조

```
技术负责人 / PM（1人，兼任 contracts 契约 owner）
├── 后端组（2人）       webman service 主力 + admin 改造/支付专项
├── 平台组（2人）       Rust ×1（infrastructure）、音视频 ×1（media）
├── 客户端组（3人）     iOS、Android、HarmonyOS 各 1
├── 质量与运维（2人）   QA ×1、DevOps ×1
└── 支持（弹性）        UI/UX ×1（常驻）、支付/合规顾问（按需）、本地化（外包）
```

### 16.2 역할 상세

| 역할 | 인원 | 책임 | 핵심 역량 | 투입 |
|------|---|------|----------|------|
| 기술 리드/PM | 1 | contracts(gRPC) 오너, 서브시스템 간 조율, 마일스톤 추진 | PHP/아키텍처/프로젝트 관리 | M0 |
| 백엔드 PHP · service | 1 | 인증/게시물/IM WS 게이트웨이/라이브·음성 시그널링/번역 스케줄링/심사 트리거/GDPR | webman/Redis/MySQL/WS | M0 |
| 백엔드 PHP · admin+결제 | 1 | open-admin 8개 모듈 개조, PaymentProvider 전 채널, 대사, 출금 | PHP/결제 채널 경험 | M0 (결제 전담 M6) |
| iOS 엔지니어 | 1 | SwiftUI 클라이언트, APNs, WS, WebRTC 연동, i18n | Swift/SwiftUI | M0 |
| Android 엔지니어 | 1 | Kotlin+Compose, FCM, WS, WebRTC, i18n | Kotlin/Compose | M0 |
| HarmonyOS 엔지니어 | 1 | ArkTS 클라이언트, Push Kit, i18n | ArkTS/鸿蒙(하모니) 생태계 | M0 |
| Rust 엔지니어 | 1 | bee-rust 서비스화 (search/graph/tsdb) + tonic gRPC | Rust/axum/tonic | M1 말 |
| 음성·영상 엔지니어 | 1 | media 컴포넌트 (mediasoup/SRS/FFmpeg/coturn), 이중 푸시 복구, 지역 TURN 배포 | Node.js/WebRTC/SRS/트랜스코딩 | M3 말 |
| UI/UX 디자이너 | 1 | 3개 플랫폼 디자인 시스템, 라이브/선물/음성 비주얼, i18n 문구 가이드라인 | Figma/다국어 디자인 | M0 |
| QA | 1 | 3개 플랫폼+백엔드+미디어 회귀, 부하 테스트, 심사/결제 흐름 검증 | 모바일/API 테스트 | M1 |
| DevOps | 1 | CI/CD, 2지역 배포, Prometheus 모니터링, 미디어 서비스 운영, 로깅 | Docker/K8s/Prometheus | M2 |
| 결제/재무 자문 | 유동 | 채널 계약 자격, 대사 규칙, 리스크 한도, 수익 배분 정산 | 결제 업계/재무 | M6부터 |
| 컴플라이언스/법무 자문 | 유동 | GDPR, 지역 규정, 콘텐츠 심사 규칙, 스토어 정책 | 데이터 컴플라이언스 | M7부터 |
| 현지화 | 외주 | 용어 번역 및 검수, 다국어 문구 | 번역/검수 | M7부터 |

### 16.3 마일스톤 페이스

| 단계 | 팀 | 병행 초점 |
|------|------|----------|
| M0–M2 | 리드+백엔드 2+모바일 3+디자인+QA | 계약 우선, 3개 플랫폼 OpenAPI 기준 병렬; Rust는 검색 투입 |
| M3–M4 | +음성·영상, DevOps | 음성·영상이 media를 구축, IM/음성과 병행 |
| M5 | 전원 | 라이브 이중 트랙, 백엔드가 미디어 지원 |
| M6 | +결제 자문 | 결제 전담+대사 |
| M7 | +컴플라이언스 자문, 현지화 | 전 플랫폼 i18n+컴플라이언스 마무리 |
| M8 | 전원 보장 | 2지역 출시, 부하 테스트, 보안 재점검 |

### 16.4 채용 우선순위

1. 백엔드 PHP ×2 + 기술 리드 (기반 기간 핵심, 백엔드가 작업량 최대 영역)
2. 모바일 ×3 (3개 플랫폼 병렬이 총 기간의 하드 제약, 빠를수록 좋음)
3. UI/UX, QA
4. Rust, DevOps (M1–M2 이전 투입)
5. 음성·영상 (M3 말)
6. 결제/컴플라이언스 자문, 현지화 (M6/M7 수요 시)

### 16.5 리스크와 폴백

- 음성·영상과 결제 채널은 채용이 가장 어려운 두 역할 (전문가 부족), 외주/자문 폴백 방안 마련
- HarmonyOS 엔지니어 채용이 어려우면 Android 엔지니어가 우선 겸임 가능 (ArkTS는 TS와 동일 계열로 습득이 빠름), 3개 플랫폼 병렬 페이스는 영향 없음
