# Aceitação de linha de base Admin (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

Estado de linha de base e pontos de entrada de transformação para o open-admin (webman v2 + console de administração Flutter).

## Versão atual e estado de execução

| Item | Valor |
|---|---|
| Framework | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| Dependências | `composer install` com sucesso, 69 pacotes |
| .env | **Não existe** (o repositório não tem `.env` nem `.env.example`; crie localmente conforme MySQL/Redis) |
| Entrada de migrações | Nenhuma (`think`/`artisan` ausentes; webman não tem migração embutida, M0 não tem tarefas de migração) |
| Testes | `vendor/bin/phpunit`: 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky — nem tudo verde** |

## Módulos habilitados (confirmado no README)

- **Autenticação JWT**: login/refresh/logout, captcha de clique, bloqueio de conta (5 falhas → bloqueio de 15 minutos), limite de sessões simultâneas (≤3 tokens por usuário)
- **RBAC**: árvore de papéis/permissões, autorização na granularidade method.path
- **Auditoria de operações**: consulta de logs + identificação de 8 origens de plataforma
- **Gerenciamento de arquivos**: upload / exportação Excel / exportação PDF (mascarada)
- **i18n**: alternância chinês/inglês (Accept-Language / ?lang=)
- Outros: painel (cache Redis), configuração do sistema, health check/metrics/OpenAPI 3.0, proteção de segurança em 18 camadas

## Detalhes de falhas de testes (todas lacunas existentes do projeto, não introduzidas por esta mudança)

| Grupo de testes | Falha | Motivo |
|---|---|---|
| `EnvConfigTest` (5 casos) | 4 failure + 1 error | Os testes exigem que `.env`/`.env.example` existam e que getenv tenha valores para `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` etc.; o repositório não acompanha env de exemplo |
| `CaptchaTest` (4 casos) | 3 error + 1 failure (além de 1 risky sem asserções) | O captcha de clique depende do armazenamento Redis, não fornecido localmente |
| `BackendEnhancementTest` (2 casos) | 2 failure | Assere que a fonte de dados `user` contém searchable e o middleware cors/rate_limit — desvio entre configuração e asserções de teste |

Etapas locais para voltar ao verde: criar `.env` conforme as chaves de configuração em `config/` (adicionar as chaves das quais EnvConfigTest depende), fornecer MySQL + Redis (para CaptchaTest) e o responsável decide sobre os dois desvios de configuração em BackendEnhancementTest.

## Estado de prontidão gRPC (T3)

- Pacotes Composer instalados: `grpc/grpc 1.82.0`, `google/protobuf 5.35` (`--no-plugins` contorna o bug de carregamento duplicado do plugin security-php)
- Stubs PHP gerados: `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` etc., incluindo os três conjuntos de contratos: infra/user)
- **Extensão PHP grpc não instalada**: pecl sem permissão de escrita e sudo exige senha; `sudo pecl install grpc` é necessário antes de executar o cliente gRPC

## Pontos de entrada de transformação (oito novos itens do §3.4 do documento de design)

1. Workbench de moderação de conteúdo: revisão bilíngue lado a lado de posts/comentários/imagens, modelos multilingues de motivos de rejeição, sanções a usuários
2. Fila de processamento de denúncias
3. Mesa de solicitações GDPR (tickets de exportação/remoção)
4. Integração do painel de dados com bee_tsdb
5. Gerenciamento de entradas i18n (CRUD compartilhado entre os quatro clientes)
6. Gerenciamento da biblioteca de presentes (SKU, preço, efeitos, nomes multilingues)
7. Configuração de providers de transmissão (estratégia de roteamento, ordem de alternância)
8. Revisão de solicitações de saque

**Pontos de integração gRPC**: os stubs de contrato do lado admin estão em `admin/generated/` (reutilizando `Social/Admin/V1` para sondas + futuras mensagens de negócio); chamadas a service passam por `Social\User\V1\UserServiceClient` e a infrastructure por `Social\Infra\V1\InfraServiceClient`; a cadeia de sondas com service/infrastructure está descrita em `service/README.grpcs.md` e nas sondas de integração T10.
