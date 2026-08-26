# Painel de administração aberto (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Um painel de administração full-stack baseado em webman v2 + Flutter.

> [English version](README_EN.md) | [Diagramas de arquitetura](docs/ARCHITECTURE.md) | [Documento de design](docs/DESIGN.md) | [Arquitetura de segurança](docs/SECURITY.md) | [Referência da API](docs/API.md)

## Funcionalidades

| Domínio | Função | Descrição |
|--------|------|------|
| 🔐 Autenticação | Login / renovação de token / logout | Captcha de clique + JWT + lista negra |
| | Bloqueio de conta | 5 tentativas falhas bloqueiam por 15 minutos |
| | Limite de sessões simultâneas | Máx. 3 tokens válidos por usuário |
| 📊 Dashboard | Estatísticas em tempo real / tendências / distribuição / ações recentes | Cache Redis por 5 minutos |
| 👥 Gestão de usuários | CRUD + exclusão em massa / ativar-desativar | Soft delete + confirmação de senha |
| | Importação em massa Excel | Validação linha por linha + relatório de erros |
| 🔒 Papéis e permissões | CRUD de papéis + árvore de permissões | Autorização RBAC com granularidade method.path |
| ⚙ Configuração do sistema | CRUD de pares chave-valor | Gerenciamento por grupos |
| 📋 Auditoria de operações | Consulta de logs + detecção de origem | 8 plataformas detectadas automaticamente |
| 📁 Gestão de arquivos | Upload / exportação Excel / exportação PDF | Mascaramento automático de dados sensíveis |
| 🛡 Segurança | Defesa em profundidade com 18 camadas | XSS/injeção SQL/traversal de caminho/injeção de comando/CSRF/limite de taxa/CSP... |
| 🏥 Operação | Health check / metrics / documentação da API / security.txt | Prometheus + OpenAPI 3.0 + documentação interativa hg/apidoc |
| 🌐 Internacionalização | Alternar chinês/inglês | Cabeçalho Accept-Language / parâmetro ?lang= |

## Stack tecnológico

| Camada | Tecnologia | Descrição |
|---|------|------|
| Framework backend | webman v2 (workerman) | Framework PHP de altíssimo desempenho com processos residentes |
| Versão PHP | 8.3+ | |
| Banco de dados | MySQL 8.0+ | Prefixo de tabela `erik_`, chaves primárias BIGINT sem auto-incremento |
| Mecanismo de busca | Elasticsearch | Sincronização e consulta via `webman-scout` |
| Frontend admin | Flutter 3.x | Web com estilo de painel administrativo de PC (`apps/flutter/`) |
| Móvel | HarmonyOS ArkTS | Cliente nativo HarmonyOS (`apps/harmonyos/`), suporta celular/tablet/2em1 |

## Dependências principais

| Pacote | Finalidade |
|---|------|
| `erikwang2013/snowflake-php` | Geração de chaves primárias BIGINT globalmente únicas via algoritmo Snowflake |
| `erikwang2013/hashids` | Criptografia de IDs na camada de API, oculta os IDs reais do banco |
| `erikwang2013/jwt-webman` | Emissão e verificação de tokens JWT |
| `erikwang2013/encryption` | Criptografia de dados sensíveis na camada de transporte |
| `erikwang2013/encryptable` | Criptografia automática de campos sensíveis no banco |
| `erikwang2013/webman-scout` | Sincronização de dados e busca de texto completo no Elasticsearch |
| `erikwang2013/season` | Dados de bandeiras de países |
| `erikwang2013/poster-php` | Geração/verificação de captcha de clique + geração de pôsteres |
| `phpoffice/phpspreadsheet` | Exportação Excel |
| `barryvdh/laravel-dompdf` | Exportação PDF (baseado em Dompdf) |

## Estrutura do projeto

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

## Requisitos de ambiente

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (apenas para desenvolvimento frontend)
- Elasticsearch >= 7.x (opcional, necessário para a busca)

## Início rápido

### 1. Instalar dependências

```bash
composer install
```

### 2. Configurar variáveis de ambiente

Copie e modifique as variáveis de ambiente (opcional; se não forem configuradas, são usados os valores padrão de `config/*.php`):

```bash
cp .env.example .env
```

Itens de configuração principais:

| Variável de ambiente | Descrição | Valor padrão |
|---------|------|--------|
| `JWT_SECRET` | Chave de assinatura JWT | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Salt do Hashids | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | Chave de criptografia da API | Valor padrão de 32 bytes |
| `SNOWFLAKE_DATACENTER_ID` | ID do datacenter (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | ID do nó de trabalho (0-31) | `1` |
| `SCOUT_HOSTS` | Endereço ES | `http://localhost:9200` |

**Em produção, altere obrigatoriamente todas as chaves para strings aleatórias.**

### 3. Instalação em um clique

Após iniciar o serviço, acesse o assistente de instalação no navegador para concluir a inicialização do banco de dados e a criação do administrador:

```bash
php start.php start
```

Ouve por padrão em `http://0.0.0.0:8787` (a porta pode ser alterada em `config/server.php`).

Abra **`http://localhost:8787/install`** no navegador e preencha conforme o assistente:

| Etapa | Conteúdo |
|------|------|
| ① Configuração do banco | Host, porta, nome do banco, usuário, senha |
| ② Administrador | Usuário e senha do administrador (padrão: admin / admin888) |

Ao clicar em "Iniciar instalação", a criação das tabelas, o seed das permissões, a criação da conta de administrador e a gravação da configuração do banco em `.env` são concluídas automaticamente.

> Após a instalação, é gerado o arquivo de bloqueio `runtime/install.lock`. Para reinstalar, basta excluir esse arquivo.

### 4. Login

Acesse `http://localhost:8787` e faça login com as credenciais de administrador definidas na instalação.

### 5. Iniciar o frontend (opcional)

**Painel de administração Flutter (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**Cliente HarmonyOS (móvel):**

Abra o diretório `apps/harmonyos/` no DevEco Studio e execute em um dispositivo real ou emulador.

### 6. Implantação em um clique com Docker Compose (recomendado para produção)

O projeto fornece uma orquestração Docker completa com 5 serviços: Nginx, PHP (webman app), MySQL, Redis, Elasticsearch.

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

- `Dockerfile`: PHP 8.3 + OPcache + Composer, baseado em `php:8.3-cli`
- `docker-compose.yml`: orquestração de 5 serviços, isolamento de rede, volumes de dados persistentes
- `.env.docker`: variáveis de ambiente específicas do Docker


## Convenções de banco de dados

- **Prefixo de tabela**: `erik_`
- **Chave primária**: todas as tabelas usam `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT desabilitado**
- **Geração de ID**: as chaves primárias são geradas na camada de aplicação via `SnowflakeService::generate()`, únicas em ambiente distribuído
- **Campos obrigatórios**: toda tabela deve conter `id`, `created_at`, `updated_at`
- **Soft delete**: tabelas que precisarem adicionam `deleted_at DATETIME DEFAULT NULL`
- **Campos sensíveis**: telefone, e-mail, CPF etc. são criptografados automaticamente pelo plugin `encryptable`; a coluna usa `VARCHAR(500)` para armazenar o texto cifrado

## Referência da API

A especificação completa da API (formato de resposta unificado, códigos de erro de negócio, tratamento de IDs, versões de API, limite de taxa, arquitetura de middleware, fluxos de autenticação e captcha) e a lista completa de endpoints estão na **[Referência da API](docs/API.md)**.

## Notas de frontend

### Painel de administração Flutter (estilo PC)

- **Layout**: barra lateral recolhível (64px/240px) + barra superior + área de conteúdo, três breakpoints responsivos (celular/tablet/desktop)
- **Páginas**: login, dashboard, gestão de usuários, papéis e permissões, configuração do sistema, logs de operações, perfil
- **Gerenciamento de estado**: GetX (singleton `ApiService` + persistência de token no `AuthService`)
- **Dashboard**: cartões de estatísticas, gráfico de linhas de tendência (fl_chart), gráfico de pizza, logs de operações recentes
- **Exportação**: exportação Excel/PDF; os PDFs incluem informações de copyright não removíveis
- **Operações em massa**: exclusão em massa com seleção múltipla, ativação/desativação em massa
- **Tema**: Material 3, tema claro/escuro

### Cliente móvel HarmonyOS

- **Páginas**: login, dashboard, lista/detalhes de usuário, perfil
- **Autenticação**: JWT Bearer + renovação silenciosa do token em 401, redirecionamento automático para o login em caso de falha
- **Armazenamento**: o token é gerenciado via AppStorage

## Regras de desenvolvimento

- Funções/classes globais sem `\` inicial, importadas uniformemente com `use`
- Todos os arquivos PHP devem conter o aviso de copyright no cabeçalho
- Todos os arquivos de configuração devem conter comentários em chinês
- As chaves primárias devem ser geradas por snowflake na camada de aplicação; auto-incremento é proibido
- Todos os IDs em parâmetros e respostas da API devem ser criptografados/descriptografados com hashids
- O middleware AdminPermission armazena em cache as permissões do usuário no Redis (TTL=60s), eliminando o gargalo de consultas N+1

## Implantação

### Docker Compose (recomendado)

A raiz do projeto fornece `docker-compose.yml`, orquestrando 5 serviços:

| Serviço | Imagem | Porta |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | Build a partir do `Dockerfile` local | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

A imagem PHP é construída via `Dockerfile`, imagem base `php:8.3-cli`, com OPcache habilitado.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

Pipeline de integração contínua com GitHub Actions: `.github/workflows/ci.yml`

- Verificação de sintaxe PHP (`php -l`)
- Testes unitários PHPUnit
- Análise estática Flutter (`flutter analyze`)

### Backup do banco de dados

Diretório `database/backup/`:

- `backup.sh` — backup com mysqldump + gzip, limpeza automática de backups com mais de 30 dias
- `restore.sh` — restauração interativa, lista os backups disponíveis para escolha

### Configuração de segurança do Nginx

Para produção, consulte `docs/nginx-security.conf` para o endurecimento de segurança do proxy reverso.

## Código aberto é um caminho difícil — apoio é bem-vindo

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
