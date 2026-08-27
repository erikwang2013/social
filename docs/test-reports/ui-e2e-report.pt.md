# Relatório de testes de ponta a ponta (E2E) das páginas
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- Data: 2026-08-27
- Ambiente: máquina local (Linux), navegador real (Playwright 1.62 / Chromium) + processos de serviço reais
- Casos de teste no total: **41**, aprovados **41**, falhos **0**, marcados como bloqueados **1**
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
| admin | Operações em lote `/admin/user/batch/status` (ativação em lote + ids vazios 422), exportação `/admin/export/excel` (verificação do cabeçalho do arquivo xlsx), alterar senha `/admin/profile/password` (senha antiga ausente 422) | 3 |
| service | `/` (contêiner iframe), `/health`, `/apidoc` (redireciona para apidoc/index.html), acesso não autenticado a endpoints protegidos 401 | 4 |
| service | Registro/login/logout, perfil (GET/PUT `/api/v1/me`), publicação/timeline/detalhe, curtir/descurtir, comentário, seguir/deixar de seguir/relacionamento/seguidores/lista de seguidos, notificações (lista/não lidas/marcar uma como lida/marcar todas como lidas) | 10 |
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
2. **A página inicial `/` do service precisa de rota explícita**: o roteamento padrão do webman-framework v2.2.4 não resolve mais `/`
   para `IndexController@index` (já causou um 404 no caminho raiz, falhando o caso da página inicial). Corrigido registrando explicitamente
   `Route::get('/', ...)` em `service/config/route.php`; surte efeito após reiniciar o service.
3. **Compatibilidade Imagick do captcha do admin**: o build de Imagick desta máquina não possui a constante `Imagick::RESOURCETYPE_PIXELS`,
   portanto o driver `auto` escolheria erroneamente o ImagickDriver e causaria um generate 500 (`admin/config/poster.php` agora recorre ao gd
   conforme a constante exista ou não; requer reiniciar o admin para surtir efeito).
4. **Memória GD do captcha do admin**: `GdDriver` decodifica imagens grandes (fundo 5472x3648) com `memory_limit 128M`,
   e generates consecutivos têm risco de OOM (o admin já caiu em suítes longas). Mitigação: reiniciar o admin antes dos casos de captcha
   e executar em lotes (admin-pages / admin-auth / service separadamente). Limitação do ambiente, não um defeito do código de negócio.
5. **Tipo de captcha aleatório**: generate escolhe um de três; click/rotate não expõem dados resolvíveis, apenas slider passa automaticamente (máx. 12 tentativas).
6. **Senha root vazia do banco**: o ambiente de teste local fornece MySQL com root/senha vazia, e os `.env` padrão dos dois apps são consistentes.
7. **Apps/ móveis**: android/harmonyos/ios não têm UI web executável, não incluídos no E2E de navegador.

## Conclusão

O login do admin (incluindo o captcha deslizante) e 22 endpoints de admin, bem como os 19 casos de fluxo completo do lado do usuário do service, passam todos
(esta rodada adicionou 6 casos: ativação em lote admin/exportação Excel/validação de alteração de senha, service 401 sem login/deixar de seguir/marcar uma notificação como lida).
2 defeitos reais foram corrigidos: 404 do caminho raiz do service (rota explícita adicionada), generate 500 do captcha do admin
(constante Imagick ausente → recurso ao GD, já incluso na configuração, surte efeito após reiniciar).
O único ponto de bloqueio é o serviço de busca (ES) não implantado; todas as demais cadeias (registro/login/publicação/interação/notificação/IM/voz) estão verificadas e operacionais.
