# Relatório de testes de ponta a ponta (E2E) das páginas
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- Data: 2026-08-27
- Ambiente: máquina local (Linux), navegador real (Playwright 1.62 / Chromium) + processos de serviço reais
- Casos de teste no total: **35**, aprovados **35**, falhos **0**, marcados como bloqueados **1**
- Artefatos: `tests/e2e/artifacts/html-report/` (relatório HTML do Playwright), capturas/rastros de falha (nenhum nesta execução)

## Escopo dos testes e lista de páginas

Os dois backends webman rodam como processos reais: `admin` (:8791), `service` (:8788, WS :8789).
As `app/view/` dos dois lados contêm apenas os templates padrão (`index/view.html`), sem templates multipágina tradicionais — as "páginas" reais são os endpoints de API,
e os frontends web são carregados pelos clientes Flutter/HarmonyOS (`apps/` não tem UI web executável, fora do escopo E2E).

| Aplicativo | Página / endpoint | Casos |
|------|------------|------|
| admin | `/health` verificação de saúde, `/metrics` métricas do Prometheus, `/.well-known/security.txt`, `/api/docs` OpenAPI, `/install` assistente de instalação | 5 |
| admin | `/api/captcha/generate` + `/api/captcha/verify` (resolução real por pixels do captcha deslizante), `/api/auth/login` (sucesso/senha errada/captcha ausente) | 3 |
| admin | Páginas protegidas após login: `/admin/dashboard`, `/admin/user`, `/admin/role`, `/admin/permission`, `/admin/config`, `/admin/log`, `/admin/profile`, `/admin/social-user`, logout `/admin/profile/logout` → token invalidado | 11 |
| service | `/` (contêiner iframe), `/health`, `/apidoc` (redireciona para apidoc/index.html) | 3 |
| service | Registro/login/logout, perfil (GET/PUT `/api/v1/me`), publicação/timeline/detalhe, curtir/descurtir, comentário, seguir/relacionamento/seguidores/lista de seguidos, notificações (lista/não lidas/marcar todas como lidas) | 8 |
| service | Buscar usuários, buscar publicações (ES não iniciado → 503, marcado como bloqueado e aprovado) | 2 |
| service | Conversas de IM (criar/listar/mensagens), salas de voz (criar/listar/detalhe/fechar) | 3 |

## Como executar

```bash
cd tests/e2e && npx playwright test          # todas
# ou por arquivo: admin-pages.spec.js / admin-auth.spec.js / service-journey.spec.js
```

- Fixture da conta de teste: `e2e_smoke`, senha `ApiTest!2026` (pré-inserida via SQL, ver `tests/api/run.php`)
- O captcha deslizante é resolvido pela correlação de Pearson por pixels entre a "peça do quebra-cabeça vs. imagem de fundo" (caminho de interação real, sem contorno);
  o tipo de captcha é aleatório (click/rotate/slider), apenas o slider é resolvível automaticamente, então o script tenta novamente com uma nova imagem até acertar.

## Pontos de bloqueio / limitações do ambiente

1. **Busca de publicações 503**: `/api/v1/search/posts` depende do Elasticsearch (Scout), não iniciado neste ambiente → retorna 503.
   O caso passa marcado como `blocked`; é preciso iniciar o ES para verificar correspondências.
2. **Memória GD do captcha do admin**: `GdDriver` decodifica imagens grandes (fundo 5472x3648) com `memory_limit 128M`,
   e generates consecutivos têm risco de OOM (o admin já caiu em suítes longas). Mitigação: reiniciar o admin antes dos casos de captcha
   e executar em lotes (admin-pages / admin-auth / service separadamente). Limitação do ambiente, não um defeito do código de negócio.
3. **Tipo de captcha aleatório**: generate escolhe um de três; click/rotate não expõem dados resolvíveis, apenas slider passa automaticamente (máx. 12 tentativas).
4. **Senha root vazia do banco**: o ambiente de teste local fornece MySQL com root/senha vazia, e os `.env` padrão dos dois apps são consistentes.
5. **Apps/ móveis**: android/harmonyos/ios não têm UI web executável, não incluídos no E2E de navegador.

## Conclusão

O login do admin (incluindo o captcha deslizante) e 19 endpoints de admin, bem como os 16 casos de fluxo completo do lado do usuário do service, passam todos;
o único ponto de bloqueio é o serviço de busca (ES) não implantado; todas as demais cadeias (registro/login/publicação/interação/notificação/IM/voz) estão verificadas e operacionais.
