# Design geral da plataforma social (Social Platform Design)

**语言 / Languages:** [中文](2026-08-16-social-platform-design.md) · [English](2026-08-16-social-platform-design.en.md) · [한국어](2026-08-16-social-platform-design.ko.md) · [Русский](2026-08-16-social-platform-design.ru.md) · [Deutsch](2026-08-16-social-platform-design.de.md) · [Français](2026-08-16-social-platform-design.fr.md) · [Español](2026-08-16-social-platform-design.es.md) · [Português](2026-08-16-social-platform-design.pt.md) · [हिन्दी](2026-08-16-social-platform-design.hi.md) · [العربية](2026-08-16-social-platform-design.ar.md) · [বাংলা](2026-08-16-social-platform-design.bn.md) · [Bahasa Indonesia](2026-08-16-social-platform-design.id.md) · [日本語](2026-08-16-social-platform-design.ja.md)

- Data: 2026-08-16
- Status: confirmado, aguardando implementação
- Escopo: comunidade de conteúdo curto (imagem+texto) + mensagens instantâneas + transmissão ao vivo/voz + economia virtual, multilíngue, multirregional global

## 1. Objetivos e escopo

Construir uma plataforma social que combine conteúdo curto de imagem+texto e IM, com transmissão ao vivo (vídeo + danmaku + microfone compartilhado), voz (mensagens / chamadas 1v1 / salas de voz) e uma economia virtual de gorjetas-presentes. Suporte a UI multilíngue, tradução de conteúdo e conformidade multirregional, com implantação em várias regiões do mundo. Desenvolvimento nativo paralelo em três plataformas: iOS / Android / HarmonyOS.

## 2. Visão geral do sistema

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

## 3. Responsabilidades dos subsistemas

### 3.1 contracts (contratos gRPC, novo diretório de nível superior)

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- Pipeline de geração: a CI gera com buf três tipos de stubs e os envia aos respectivos sub-repositórios (os builds não dependem da rede)
  - service/, admin/ → stubs PHP (grpc/grpc + google/protobuf)
  - infrastructure/ → stubs Rust (tonic)
- Regra de versionamento: apenas adicionar campos, nunca modificar ou excluir; o nome do pacote carrega a versão major (`social.user.v1`)

### 3.2 service (webman v2) — monólito de negócios do lado do usuário

- **Domínios de API**: auth (JWT token duplo + lista negra), profile, posts, likes, comments, follows, IM (conversas/mensagens/gateway WS), notifications, agendamento de tradução, sinalização de salas/danmaku/microfone compartilhado, sinalização de chamadas de voz/salas de voz, economia virtual (carteira/presentes/verificação IAP/divisão de receita), exportação/exclusão GDPR
- **Sistema de erros multilíngue**: os erros retornam `{code, lang_key, params}`; os textos são renderizados pelo cliente conforme a locale
- **Filas** (redis-queue): gatilhos de moderação, agendamento de tradução, entrega de push, estatísticas assíncronas, transmissão de efeitos de presentes
- **Tarefas agendadas** (webman-crontab): pré-aquecimento de traduções, limpeza de tokens/mensagens expirados, arquivamento de auditoria, liquidação da divisão de receita
- **IDs**: `erikwang2013/snowflake-php` (consistente com o admin)
- **Contratos**: exportação automática OpenAPI 3.0 → geração de clientes tipados para as três plataformas

### 3.3 infrastructure (bee-rust) — camada de computação de alto rendimento

Não armazena dados primários de negócios (MySQL é a única fonte da verdade); assume capacidades pesadas em computação/consultas:

- `bee_search`: busca de texto completo em publicações/usuários (segmentação de palavras chinesas, indexação multilíngue)
- `bee_graph`: grafo social → feed de recomendações
- `bee_tsdb`: estatísticas de séries temporais: DAU, postagens, interações, visualizações ao vivo, duração de chamadas de voz, etc.
- `bee_cache/bee_kv`: cache de timeline, contadores (curtidas, visualizações, usuários online)
- Implantado por região, muitas leituras/poucas escritas, dados replicados a partir do site central

### 3.4 admin (reforma do open-admin)

**Reutilizado**: infraestrutura JWT/RBAC/auditoria/gerenciamento de arquivos/health checks/i18n zh-en

**Novo**:
- Workbench de moderação de conteúdo: revisão bilíngue lado a lado de publicações/comentários/imagens, modelos multilíngues de motivos de rejeição, penalidades a usuários
- Fila de tratamento de denúncias
- Balcão de solicitações GDPR (tickets de exportação/exclusão)
- Painéis de dados apoiados por bee_tsdb
- Gerenciamento de termos i18n (CRUD de termos compartilhados pelos quatro clientes)
- Gerenciamento do catálogo de presentes (SKU, preços, efeitos, nomes multilíngues)
- Configuração dos providers de transmissão (estratégia de roteamento, ordem de comutação)
- Revisão de solicitações de saque

### 3.5 media (camada de mídia auto-hospedada, Node.js + serviços de sistema)

- `sfu/`: mediasoup; carrega o plano de mídia de chamadas 1v1 e salas de voz; apenas retransmissão de mídia, sem lógica de negócios
- `srs/`: SRS auto-hospedado para transmissão; ingestão RTMP → transcodificação FFmpeg → distribuição HTTP-FLV/HLS
- `coturn/`: relay TURN, fallback para travessia de NAT
- Toda a sinalização é retransmitida pelo gateway WS do service

### 3.6 apps — desenvolvimento nativo paralelo em três plataformas

- Contrato OpenAPI compartilhado; cada plataforma gera seu próprio cliente tipado
- Módulos de infraestrutura unificados: camada de rede (reintentos/atualização de autenticação), cliente WS (sinalização IM/danmaku/chamadas), i18n (recursos locais + termos remotos incrementais), registro de push, temas
- Notas do HarmonyOS: Huawei Push Kit, adaptação ao modelo de concorrência do ArkTS

## 4. Comunicação de backend (gRPC)

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| Chamador → Chamado | Conteúdo |
|------|------|
| service → infra | busca de texto completo, feed de recomendações, cache quente de timeline, leitura/escrita de contadores, escrita de estatísticas temporais |
| admin → infra | consultas de estatísticas para painéis, busca de backend |
| admin → service | penalidades a usuários, exclusão de conteúdo, entrega de resultados de moderação |
| service → admin | eventos de denúncia, enfileiramento de tarefas de moderação (async) |

Fronteira: os apps das três plataformas e o frontend administrativo (Flutter) usam HTTPS REST + WS e nunca tocam gRPC diretamente.

**Pré-requisito operacional**: gRPC no lado PHP exige a extensão oficial `grpc` (extensão C) + o pacote composer `grpc/grpc`; o modo servidor segue o esquema oficial walkor/grpc do workerman; a documentação de implantação deve especificar isso.

## 5. Arquitetura multilíngue (três camadas)

| Camada | Abordagem |
|----|------|
| **Camada de UI** | recursos de locale por plataforma (início zh/en; o sistema suporta qualquer idioma); o servidor só envia códigos de erro + chaves de modelo |
| **Camada de conteúdo** | na publicação, armazenar o original + detecção automática de idioma gravada no campo `lang`; na leitura, reader.lang ≠ author.lang → serviço de tradução (abstração LLM/MT provider), resultados em cache no Redis (bee_cache, TTL), flag `is_translated` para voltar ao original; pré-aquecimento agendado de conteúdo popular |
| **Camada de conformidade** | regras de moderação aplicadas por região (regras GDPR da UE vs outras regiões); UI bilíngue de denúncias/moderação |

Danmaku é texto curto em tempo real: sem tradução de conteúdo, apenas i18n de UI + filtragem multilíngue de palavras sensíveis.

## 6. Arquitetura de IM

- **Gateway**: gateway WS do webman, múltiplas instâncias com retransmissão entre nós via Redis pub/sub, deduplicação idempotente com `client_msg_id`
- **Dados**: conversations / conversation_members / messages / message_reads; conversas privadas + grupos (limite de grupo 500)
- **Entrega**: online → push direto por WS; offline → push APNs/FCM/Huawei
- **Capacidades**: confirmações de leitura, indicador de digitação, recall com limite de tempo, mensagens de imagem/voz (upload S3 + transcodificação)
- Compartilha o sistema de usuários e notificações com o feed

## 7. Arquitetura de transmissão ao vivo (vídeo + danmaku + microfone compartilhado, via dupla)

### 7.1 Abstração de provider (dentro do service)

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| Mecanismo | Design |
|------|------|
| Estratégia de roteamento | provider padrão escolhido por região na criação da sala (sobrescrevível pelo admin); regiões sem cobertura de terceiros ou sensíveis a custo → auto-hospedado |
| Tolerância a falhas | dupla ingestão com o SDK do streamer (principal = terceiros, backup = SRS próprio); os players resolvem a URL por provider e mudam automaticamente para o fluxo próprio em caso de falha do terceiro |
| Danmaku/microfone compartilhado | desacoplados do pipeline de vídeo: o danmaku passa pelo WS do service, o microfone compartilhado pela RTC de terceiros |
| Conformidade | a moderação de áudio/vídeo em tempo real do pipeline próprio reutiliza as APIs de moderação de terceiros (compra-se apenas a moderação, não o transporte) |

### 7.2 Salas de transmissão

CRUD de salas, máquina de estados de início/fim de transmissão, capa, anúncios (multilíngues), contadores de visualização (bee_tsdb), canais de danmaku da sala (Redis pub/sub), gerenciamento de papéis de microfone compartilhado (anfitrião/assentos, o service emite tokens RTC de terceiros), estatísticas online/pico/duração → painéis do admin.

## 8. Arquitetura de voz (trio)

| Forma | Implementação |
|------|------|
| Mensagens de voz | extensão do tipo de mensagem IM: armazenamento S3 + transcodificação (m4a) + duração |
| Chamadas 1v1 | sinalização pelo gateway WS (offer/answer/ICE), máquina de estados de toque/atendimento/desligamento (Redis), plano de mídia via mediasoup, registros de chamadas no banco |
| Salas de voz | o gerenciamento de salas reutiliza o padrão de salas de transmissão; estados de microfone/ouvintes gerenciados pelo service; plano de mídia via mediasoup |

## 9. Economia virtual (recargas + gorjetas-presentes + saques)

```
移动端 IAP（App Store/Google Play/华为）──┐
国内：微信支付 / 支付宝（APP/H5）          ├─▶ PaymentProvider ─▶ 钱包
国外：微信国际 / 支付宝国际 / Stripe / PayPal│    （按 region 选路）
                                          └─▶ payments 支付单（幂等+验签+对账）
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
主播钱包 ──▶ payouts 提现单 ──▶ 国内：商家转账 │ 国外：Stripe Connect/PayPal
```

### 9.1 Canais de pagamento (nacional vs internacional)

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

| Mecanismo | Design |
|------|------|
| Roteamento de canais | escolha do canal por região do usuário + moeda + regras de merchant do admin, com ordem de fallback configurável (separação natural nacional/internacional) |
| Ordem de pagamento | modelo payments unificado: usuário/canal/valor/moeda/máquina de estados, idempotente em todos os canais |
| Callbacks | wrapper unificado de verificação de assinatura (RSA/HMAC), callbacks idempotentes, tarefa diária de conciliação (conferência com extratos dos canais) |
| Saques | ordens payouts: transferência de merchant nacional, pagamento internacional Stripe Connect/PayPal; modo de divisão/desembolso conforme a capacidade do canal |
| Preços | tabelas de preços regionais (admin): moeda virtual × preços em moedas, câmbio gerenciado centralmente |
| Controle de risco | limites/frequência/alertas de ordens anômalas, auditoria completa do fluxo (reutiliza o sistema de auditoria) |
| SKU de presentes | catálogo de presentes (preços, identificadores de efeitos, nomes multilíngues) gerenciado pelo admin |

Conformidade: recargas de moeda virtual no mobile devem passar pelo IAP das lojas (comissão Apple/Google/Huawei); WeChat/Alipay são usados para H5/Web e cenários regionais específicos; saques envolvem liquidação de fundos, então a plataforma os resolve por interfaces de divisão/desembolso de canais licenciados; a qualificação contratual dos canais é confirmada antes de M6b; limites para menores entram na fase de conformidade.

## 10. Modelos de dados principais

- Usuários: users, user_profiles (campos multilíngues)
- Social: follows, posts, post_translations, comments, comment_translations, likes, reports
- IM: conversations, conversation_members, messages, message_reads
- Transmissão: live_rooms, live_streams (com provider), danmaku_archive
- Voz: call_records, voice_rooms, voice_room_members
- Economia virtual: wallets, currency_transactions, gift_catalog, gifts_given, streamer_earnings, withdrawals, payments, payouts, price_plans (preços regionais/câmbio), merchant_configs (configurações de merchant por canal), products (SKU IAP)
- Plataforma: i18n_terms (termos compartilhados pelos quatro clientes), moderation_queue, provider_configs, audit_logs

## 11. Escolha de bancos de dados e armazenamento

| Uso | Armazenamento | Componente |
|------|------|----------|
| Dados primários de negócios (usuários/publicações/IM/carteira/moderação/denúncias) | MySQL 8 (master central + réplicas somente leitura regionais) | compartilhado por service e admin; única fonte da verdade |
| Dados quentes/sessões/estados online/contadores/canais de danmaku/máquinas de estado de chamadas | Redis 7 | bee_kv / bee_cache (feature redis) |
| Busca de texto completo (publicações/usuários, busca de backend do admin) | OpenSearch (início com um nó) | bee_search (feature opensearch) |
| Estatísticas temporais (DAU/tendências/visualizações ao vivo/duração de chamadas/painéis) | QuestDB (início com um binário) | bee_tsdb (feature questdb, substituível por influxdb) |
| Grafo social → feed de recomendações | Neo4j Community (início com um nó) | bee_graph (feature neo4j, substituível por nebulagraph) |
| Arquivos de objeto (imagens/vídeos/voz/pacotes de exportação) | S3 (MinIO ou provedor de nuvem) | acesso direto do service + distribuição via CDN |
| Registros de auditoria | MySQL audit_logs, arquivados em armazenamento de objetos no vencimento | reutiliza o sistema de auditoria do admin |

Princípios de seleção: os componentes do bee-rust são abstrações com feature flags — início com um nó, substituição por backends distribuídos conforme o crescimento, sem amarração; MySQL é sempre a única fonte da verdade; a camada de computação (índices/estatísticas/grafo/cache) armazena apenas dados derivados reconstruíveis. O frontend administrativo (Flutter) nunca toca o banco de dados diretamente; tudo passa pelo backend do admin.

## 12. Implantação e operação (multirregional global)

- **Arquitetura inicial**: duas grandes regiões — China + exterior; cada região com cluster webman + cluster bee-rust + Redis local + media (SFU/SRS/TURN); master central MySQL + réplicas somente leitura por região; CDN por região
- **Acesso WS mais próximo**, mensagens entre regiões coordenadas centralmente; push pelo provedor correspondente por região
- **Caminho de evolução**: após o crescimento do tráfego, sharding dos bancos por hash de usuário
- **Monitoramento**: métricas Prometheus (seguindo o padrão do open-admin), logs centralizados, alertas (taxa de erro/latência/acúmulo de filas/saúde dos serviços de mídia)

## 13. Segurança e conformidade

- service replica o modelo de defesa de 18 camadas do open-admin (XSS/SQLi/CSRF/limite de requisições/CSP)
- Pipeline de moderação: filtro multilíngue de palavras sensíveis na publicação → moderação de imagem/áudio-vídeo (APIs de terceiros) → moderação humana
- GDPR: exportação de dados, direito de cancelamento/exclusão, política de retenção de logs, limite de idade para menores, regras diferenciadas por região

## 14. Marcos (full-stack solo, ~9–10 meses)

| Fase | Conteúdo | Duração |
|------|------|------|
| M0 Fundação | esqueleto monorepo, contracts(gRPC)+geração de stubs das três plataformas+sondas de vida ponta a ponta, inicialização dos projetos das três plataformas, CI (build+test), esqueleto dos serviços bee-rust | 1–2 semanas |
| M1 Ciclo fechado | registro/login/perfil, publicação/detalhe, timeline simplificada, curtidas e comentários | 3–4 semanas |
| M2 Social completo | sistema de seguidores, feed completo, busca de texto completo (bee_search), notificações | 3–4 semanas |
| M3 IM | gateway WS, conversas, mensagens, push offline, leitura/recall | 4–6 semanas |
| M4 Voz | componentes media (mediasoup+coturn), mensagens de voz, chamadas 1v1, salas de voz | 4–5 semanas |
| M5a Transmissão principal | pipeline de terceiros, salas de transmissão, danmaku, microfone compartilhado | 3–4 semanas |
| M5b Transmissão complementar | integração SRS próprio, failover de dupla ingestão, configuração de roteamento | 2 semanas |
| M6a Moeda virtual+presentes | IAP, carteira, presentes, divisão de receita | 2–3 semanas |
| M6b Canais de pagamento | WeChat/Alipay/WeChat Global/Alipay Global/Stripe/PayPal, saques, conciliação | 3–4 semanas |
| M7 Multilíngue+conformidade | i18n em todas as plataformas, tradução de conteúdo, workbench de moderação, GDPR, integração de moderação de áudio/vídeo | 3–4 semanas |
| M8 Lançamento | implantação em duas regiões (incl. TURN regional), monitoramento/alertas, testes de carga, revisão de segurança | 2–3 semanas |

Cada marco é uma fatia entregável de forma independente; o projeto pode parar a qualquer momento e o produto permanece sempre totalmente utilizável.

## 15. Resumo do stack tecnológico

| Subsistema | Tecnologia |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / extensão grpc / snowflake-php |
| infrastructure | Rust / workspace bee-rust (search/graph/tsdb/kv/cache) / tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| Externo | nuvem de transmissão de terceiros, RTC de terceiros, APIs de moderação de terceiros, WeChat Pay/Alipay/WeChat Pay Global/Alipay Global/Stripe/PayPal, IAP de App Store/Google Play/Huawei, push APNs/FCM/Huawei |

## 16. Planejamento da equipe (quadro real, ritmo estável)

### 16.1 Estrutura organizacional

```
技术负责人 / PM（1人，兼任 contracts 契约 owner）
├── 后端组（2人）       webman service 主力 + admin 改造/支付专项
├── 平台组（2人）       Rust ×1（infrastructure）、音视频 ×1（media）
├── 客户端组（3人）     iOS、Android、HarmonyOS 各 1
├── 质量与运维（2人）   QA ×1、DevOps ×1
└── 支持（弹性）        UI/UX ×1（常驻）、支付/合规顾问（按需）、本地化（外包）
```

### 16.2 Detalhamento de papéis

| Papel | Pessoas | Responsabilidades | Habilidades-chave | Início |
|------|---|------|----------|------|
| Tech lead/PM | 1 | owner dos contracts(gRPC), coordenação entre subsistemas, avanço de marcos | PHP/arquitetura/gestão de projetos | M0 |
| Backend PHP · service | 1 | auth/publicações/gateway WS IM/sinalização de transmissão e voz/agendamento de tradução/gatilhos de moderação/GDPR | webman/Redis/MySQL/WS | M0 |
| Backend PHP · admin+pagamentos | 1 | reforma dos 8 módulos do open-admin, PaymentProvider todos os canais, conciliação, saques | PHP/experiência em canais de pagamento | M0 (pagamentos M6) |
| Engenheiro iOS | 1 | cliente SwiftUI, APNs, WS, integração WebRTC, i18n | Swift/SwiftUI | M0 |
| Engenheiro Android | 1 | Kotlin+Compose, FCM, WS, WebRTC, i18n | Kotlin/Compose | M0 |
| Engenheiro HarmonyOS | 1 | cliente ArkTS, Push Kit, i18n | ArkTS/ecossistema HarmonyOS | M0 |
| Engenheiro Rust | 1 | servicificação do bee-rust (search/graph/tsdb) + gRPC tonic | Rust/axum/tonic | fim de M1 |
| Engenheiro de áudio/vídeo | 1 | componentes media (mediasoup/SRS/FFmpeg/coturn), failover de dupla ingestão, implantação regional de TURN | Node.js/WebRTC/SRS/transcodificação | fim de M3 |
| Designer UI/UX | 1 | sistema de design das três plataformas, visuais de transmissão/presentes/voz, diretrizes de textos i18n | Figma/design multilíngue | M0 |
| QA | 1 | regressão de três plataformas+backend+mídia, testes de carga, validação de moderação/pagamentos | testes mobile/API | M1 |
| DevOps | 1 | CI/CD, implantação em duas regiões, monitoramento Prometheus, operação dos serviços de mídia, logs | Docker/K8s/Prometheus | M2 |
| Consultor de pagamentos/finanças | flexível | qualificação contratual de canais, regras de conciliação, limites de risco, liquidação da divisão de receita | setor de pagamentos/finanças | a partir de M6 |
| Consultor de conformidade/legal | flexível | GDPR, regulamentações regionais, regras de moderação de conteúdo, políticas das lojas | conformidade de dados | a partir de M7 |
| Localização | terceirizada | tradução e revisão de termos, textos multilíngues | tradução/revisão | a partir de M7 |

### 16.3 Ritmo de marcos

| Fase | Equipe | Foco paralelo |
|------|------|----------|
| M0–M2 | líder+2 backend+3 mobile+design+QA | contratos primeiro; três plataformas em paralelo no OpenAPI; Rust entra para a busca |
| M3–M4 | +áudio/vídeo, DevOps | áudio/vídeo constrói media em paralelo com IM/voz |
| M5 | equipe completa | transmissão em via dupla; o backend apoia a mídia |
| M6 | +consultor de pagamentos | trilha de pagamentos+conciliação |
| M7 | +consultor de conformidade, localização | i18n em todas as plataformas+fechamento de conformidade |
| M8 | equipe completa, garantia | lançamento em duas regiões, testes de carga, revisão de segurança |

### 16.4 Prioridades de contratação

1. Backend PHP ×2 + tech lead (núcleo do período de fundação; o backend é a área de maior volume de trabalho)
2. Mobile ×3 (o paralelismo das três plataformas é a restrição dura do prazo total — quanto antes, melhor)
3. UI/UX, QA
4. Rust, DevOps (entrada antes de M1–M2)
5. Áudio/vídeo (fim de M3)
6. Consultores de pagamentos/conformidade, localização (sob demanda em M6/M7)

### 16.5 Riscos e planos de contingência

- Áudio/vídeo e canais de pagamento são os dois papéis mais difíceis de contratar (especialistas escassos); reservar planos de contingência com terceirização/consultores
- Se for difícil contratar um engenheiro de HarmonyOS, um engenheiro Android pode cobrir primeiro (ArkTS tem raízes comuns com TS e é rápido de aprender); o ritmo paralelo das três plataformas não é afetado
