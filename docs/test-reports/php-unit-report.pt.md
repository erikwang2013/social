# Relatório de testes unitários PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Data: 2026-08-27
- Execução: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Escopo: admin/ (painel administrativo webman) + service/ (serviço principal webman)

## Visão geral

| Projeto | Casos de teste | Asserções | Resultado |
|------|------|------|------|
| service | 136 | 348 | ✅ Tudo aprovado (OK) |
| admin | 60 | 136 | ⚠️ 49 aprovados / 4 erros / 7 reprovados |

## service (tudo verde)

- Novos arquivos de teste (neste lote): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest, etc.; mesclados com os 24 arquivos existentes, 136 casos no total, todos aprovados
- Módulos cobertos: autenticação/middleware/JWT, usuários, posts, comentários, seguir, notificações, sincronização de busca, IM, salas, chamadas (CallCenter/CallState), voz, relações de modelos, tratamento de ações (WS)

### Correção: travamento aleatório da suíte de testes (importante)

- Sintoma: em execuções completas o processo congela aleatoriamente; executar um único arquivo/subconjunto passa
- Causa raiz: `new Worker()` em `ActionHandlerTest::setUp` registra a instância no **registro estático** `Worker::$workers`; depois, qualquer `CallCenter::start` vê "existe um Worker" e chama `Timer::add` → `pcntl_alarm(1)` instala um temporizador SIGALRM, e o processo trava ao sair
- Correção: setUp captura um snapshot do registro, tearDown o restaura (`ReflectionProperty` devolve `workers`/`pidMap`)
- Local: `service/tests/ActionHandlerTest.php`

## admin (49/60; as falhas são todas testes pré-existentes e são problemas de ambiente/configuração)

| Caso de teste | Motivo da falha | Categoria |
|------|----------|------|
| EnvConfigTest (4 reprovados + 1 erro) | `admin/.env` não existe; asserções getenv/dotenv falham | Ambiente de teste sem .env |
| CaptchaTest (3 erros + 1 reprovado + 1 risky) | Captcha depende de serviço/Redis em execução; ambiente de teste unitário retorna null | Dependência de ambiente |
| BackendEnhancementTest (2 reprovados) | Assert que `app/middleware/Cors` existe e admin_user contém searchable — a configuração atual não corresponde às asserções | Asserções de configuração desatualizadas |

Nota: admin/tests são todos arquivos históricos pré-existentes; nenhum teste unitário novo de admin foi adicionado neste lote (o foco foi service).

## Não coberto / a adicionar

- Módulos do admin (model/middleware/view) sem testes unitários
- Caminhos do service que dependem de serviços externos (ES/gRPC) tiveram apenas validação unitária via stub; cobertura de integração recomendada por meio de testes de API
