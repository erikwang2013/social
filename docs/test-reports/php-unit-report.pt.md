# Relatório de testes unitários PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Data: 2026-08-27
- Execução: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Escopo: admin/ (painel administrativo webman) + service/ (serviço principal webman)

## Visão geral

| Projeto | Casos de teste | Asserções | Resultado |
|------|------|------|------|
| service | 159 | 408 | ✅ Tudo aprovado (OK) |
| admin | 67 | 180 | ✅ Tudo aprovado (OK) |

## Notas de ambiente

- MySQL 127.0.0.1:3306 (root, sem senha); bancos `social` (social_*) e `open_admin` (erik_*) criados e com dados (papel super_admin, 39 permissões)
- Redis 127.0.0.1:6379 em execução (armazenamento de captcha `poster:captcha:*`); Elasticsearch não iniciado (health check degrada para unavailable, não conta como falha)
- service na 8788, admin na 8791
- Nem service nem admin têm `.env` (o repositório removeu os env commitados por engano, commit e5379fc); os apps rodam com os fallbacks `getenv('X') ?: valor padrão` em `config/*.php`
- **A extensão Imagick está carregada mas falta a constante `RESOURCETYPE_PIXELS`** (este build só tem o novo conjunto de constantes RESOURCETYPE_*); o construtor do ImagickDriver do poster-php referencia essa constante e quebra

## service (159/159 tudo verde)

- Consistente com a linha de base do lote anterior; cobre: autenticação/middleware/JWT, usuários, posts, comentários, seguir, notificações, sincronização de busca, IM, salas, chamadas (CallCenter/CallState), voz, relações de modelos, tratamento de ações (WS)
- M5 adicionou o módulo de lives (LiveCenter: criar/detalhe/danmaku/vínculo de microfone/fechar), 23 casos, sem regressões

## admin (lote anterior 49/60 → este lote 67/67 tudo verde)

### Correção: defeito real de código (1 local)

| Local | Causa raiz | Correção |
|------|------|------|
| `config/poster.php` | `image.driver` padrão `auto`; DriverFactory escolhe ImagickDriver ao detectar a extensão Imagick, mas o Imagick desta máquina não tem a constante `RESOURCETYPE_PIXELS` → geração de captcha/pôster direto em 500 (o serviço online também é afetado) | Guarda de constante adicionada na detecção do driver: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`; recuo automático para GD quando a constante falta |

### Correção: asserções desatualizadas (atualizadas após conferir o código atual)

| Arquivo de teste | Caso | Causa raiz | Correção |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 reprovados + 1 erro) | Assert de existência de `.env`/`.env.example` e de valores getenv; mas o repositório removeu os arquivos env e eles não podem ser reconstruídos | Reescrito como contrato "rodar sem .env": cada chave `getenv()` deve ter um padrão `?:`, a configuração padrão aponta para serviços locais (127.0.0.1:3306/open_admin), tipos da configuração crítica corretos |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser não usa mais o trait Searchable (agora `Erikwang2013\Encryptable\Encryptable` para criptografia/descriptografia transparente de campos; `toSearchableArray()` mantido) | Assert alterado para o trait Encryptable; o assert toSearchableArray já passava, mantido |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` agora usa o formato de chave de grupo global `'@'`; o array de nível superior não contém mais as classes de middleware diretamente | Assert alterado para verificar que `$middlewares['@']` contém Cors e RateLimit |
| CaptchaTest | todos os 7 casos (originalmente 6 erros + 1 reprovado) | Dupla obsolescência: (a) constante Imagick ausente (já corrigida pelo poster.php); (b) asserções baseadas no contrato antigo do poster-php — `extra.targets` (com x/y) mudou para `extra.texts` (apenas text+order), as coordenadas vivem só na camada de armazenamento; o formato de clique mudou de `['x'=>, 'y'=>]` para pares numéricos `[x, y]` | Reescrito conforme o contrato atual: estrutura/quantidades de dificuldade (2/3/4)/validação de campos; cliques corretos leem coordenadas do Redis (`poster:captcha:{key}` → `data.targets`) e validam; clique errado falha; após max_attempts (3) a chave é consumida/excluída; unicidade da chave |

### Novos testes (1 arquivo, 12 casos)

`tests/AdminControllerTest.php` (com cabeçalho de copyright), cobrindo:

- **BaseController::decodeId** (o comportamento 404 recém-corrigido): idas e vindas encode/decode consistentes; hashid inválido lança `support\exception\NotFoundException` com code=404; encodeIds só reescreve campos de ID
- **RoleController**: update do papel super_admin retorna 403 (dados reais de DB)
- **PermissionController::buildTree**: aninhamento da árvore de permissões (2 níveis) + todos os ids de nós com hashid
- **ConfigController**: falta group/key/value → validação 422; hashid inválido → 404
- **ExportController**: o export `admin_user` lista os campos sensíveis phone/email/id_card (demais tabelas vazias); o HTML do PDF escapa título/valores de célula com htmlspecialchars (proteção XSS) e inclui a declaração de copyright

### Notas conhecidas

- O Request webman construído nos testes é passado como mensagem HTTP bruta (buffer) — o parâmetro do construtor do Request workerman é um buffer; passar apenas method/uri não permite parsear o corpo POST; ver comentários no AdminControllerTest
- O caso de clique correto do captcha lê os alvos armazenados do Redis; quando o Redis está indisponível, o caso é markTestSkipped e não afeta o resultado da suíte

## Não coberto / a adicionar

- A criptografia/descriptografia Encryptable dos models do admin, o middleware OperationLog/AdminPermission e os caminhos de cache RBAC ainda não têm testes unitários; recomenda-se cobrir via testes de API ou lote posterior
- Caminhos do service que dependem de serviços externos (ES/gRPC) continuam apenas com validação unitária via stub; o nível de integração é coberto por testes de API
