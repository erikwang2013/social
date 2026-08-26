# Documentação de referência da API
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Visão geral

O painel de administração open-admin é construído sobre webman v2 e fornece uma API JSON RESTful. Todos os endpoints de administração exigem autenticação JWT e verificação de permissões RBAC; os endpoints públicos são roteados para controladores versionados por meio do cabeçalho de versão da API.

- **URL base**: `http://localhost:8787`
- **Versão da API**: controlada pelo cabeçalho `API-Version: v1` (padrão v1 se ausente)
- **Idioma**: alternado pelo cabeçalho `Accept-Language` ou pelo parâmetro `?lang=zh_CN|en` (padrão zh_CN), detectado automaticamente pelo middleware Locale

> **Visão geral dos endpoints**: Autenticação(5) | Painel(1) | Usuários(7) | Papéis(4) | Permissões(4) | Configuração(4) | Logs(1) | Perfil(3) | Importar/Exportar(3) | Upload(1) | Operação(4: health/metrics/docs/security.txt) | 37 endpoints no total
- **Autenticação**: `Authorization: Bearer <token>` (JWT)
- **Formato de resposta**: `{ "code": 0, "message": "success", "data": {...} }`
- **Endpoint de documentação**: `GET /api/docs` retorna a especificação JSON do OpenAPI 3.0

### Requisitos das requisições

- Apenas os métodos `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` são permitidos; outros métodos HTTP (ex.: TRACE, CONNECT, PATCH) retornam 405
- Todas as requisições `POST` / `PUT` devem definir `Content-Type: application/json` (exceto upload de arquivos), caso contrário retorna 415
- O corpo da requisição não pode exceder 10MB, caso contrário retorna 413
- O filtro de segurança verifica todas as entradas das requisições em busca de XSS, injeção SQL, path traversal e injeção de comandos; correspondências retornam 403
- 5 falhas consecutivas de login disparam o bloqueio da conta (15 minutos); requisições de login durante o bloqueio retornam 429
- Um usuário pode manter no máximo 3 tokens válidos simultaneamente; ao exceder, o token mais antigo é adicionado automaticamente à lista negra

## 2. Códigos de erro

| code | Significado | Cenário de acionamento |
|------|------|---------|
| 0 | Sucesso | |
| 400 | Erro nos parâmetros da requisição | Formato de requisição incorreto |
| 401 | Não autenticado | Token ausente / expirado / na lista negra |
| 403 | Sem permissão / bloqueio de segurança | Permissões RBAC insuficientes / detecção do SecurityFilter |
| 404 | Recurso não encontrado | O alvo da consulta/atualização/exclusão não existe |
| 405 | Método não permitido | Apenas GET/POST/PUT/DELETE/OPTIONS/HEAD são permitidos; métodos não padrão são rejeitados |
| 413 | Corpo da requisição grande demais | Content-Length excede 10MB |
| 415 | Tipo de mídia não suportado | Content-Type de POST/PUT não é JSON nem upload de arquivo |
| 422 | Falha na validação de parâmetros | Campos obrigatórios ausentes, formato incorreto ou validação de negócio falhou |
| 429 | Requisições demais | RateLimit acionado / bloqueio de conta (5 falhas de login consecutivas bloqueiam por 15 minutos) |
| 500 | Erro interno do servidor | |

## 3. Endpoints públicos

Todos os endpoints públicos são montados no grupo `/api`; o middleware `ApiVersion` os distribui para o controlador versionado correspondente ao cabeçalho `API-Version` (ex.: `app\api\v1\controller\AuthController`).

### 3.1 Verificação de saúde

```
GET /health
```

- **Autenticação**: nenhuma necessária
- **Limite de taxa**: nenhum

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "app": "open-admin",
    "version": "1.0",
    "php": "8.3.0",
    "database": "ok",
    "redis": "ok",
    "elasticsearch": "ok",
    "timestamp": 1716249600
  }
}
```

Valores de `database`, `redis`, `elasticsearch`: `"ok"` | `"unavailable"`. `elasticsearch` retorna `"unavailable"` quando o ES está inacessível; se o estado de saúde do cluster não for green/yellow, o valor real de status é retornado (ex.: `"red"`).

### 3.2 Documentação da API

```
GET /api/docs
```

- **Autenticação**: nenhuma necessária
- **Limite de taxa**: padrão global (60/min)
- **Resposta**: especificação JSON do OpenAPI 3.0.3, incluindo todas as definições de endpoints, parâmetros e schemas

### 3.3 Gerar captcha

```
POST /api/captcha/generate
```

- **Autenticação**: nenhuma necessária
- **Cabeçalho da requisição**: `API-Version: v1` (obrigatório)
- **Limite de taxa**: padrão global (60/min)

**Corpo da requisição**:
```json
{
  "difficulty": "medium"
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| difficulty | string | Não | `easy` / `medium` / `hard`, padrão `medium` |

**Exemplo de resposta** — tipo clique (`type: "click"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "abc123def456",
    "type": "click",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "targets": [
        { "order": 1, "text": "A", "x": 120, "y": 85 },
        { "order": 2, "text": "B", "x": 310, "y": 42 }
      ]
    }
  }
}
```

**Exemplo de resposta** — tipo deslizante (`type: "slider"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "def456abc789",
    "type": "slider",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "x": 120,
      "y": 60,
      "puzzle_w": 50,
      "puzzle_h": 50,
      "puzzle": "data:image/png;base64,iVBORw0KGgo..."
    }
  }
}
```

**Exemplo de resposta** — tipo rotação (`type: "rotate"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "ghi789abc012",
    "type": "rotate",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "angle": 45
    }
  }
}
```

| Campo | Tipo | Descrição |
|------|------|------|
| key | string | Identificador do captcha, enviado de volta na verificação |
| type | string | Tipo de captcha: `click` / `slider` / `rotate` |
| image | string | Imagem em data URI base64 |
| extra | object | Dados adicionais conforme o tipo (veja abaixo) |

**`extra` por tipo**:

| type | campos extra | Tipo | Descrição |
|------|-----------|------|------|
| click | targets | array | Alvos de clique, contendo `order` (ordem) `text` (texto de dica) `x` `y` (coordenadas) |
| slider | x, y | int | Coordenadas do canto superior esquerdo do vão (com base em um canvas de 300×200) |
| slider | puzzle_w, puzzle_h | int | Largura e altura da imagem do quebra-cabeça |
| slider | puzzle | string | Imagem do quebra-cabeça em data URI base64 |
| rotate | angle | int | Ângulo de rotação correto (0-359); é necessário girar `360-angle` para endireitar a imagem |

### 3.4 Verificar captcha

```
POST /api/captcha/verify
```

- **Autenticação**: nenhuma necessária
- **Cabeçalho da requisição**: `API-Version: v1` (obrigatório)
- **Limite de taxa**: padrão global (60/min)

**Corpo da requisição** — tipo clique (`type: "click"`):
```json
{
  "key": "abc123def456",
  "type": "click",
  "clicks": [
    { "x": 120, "y": 85 },
    { "x": 310, "y": 42 }
  ]
}
```

**Corpo da requisição** — tipo deslizante (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**Corpo da requisição** — tipo rotação (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| key | string | Sim | Chave do captcha, retornada pelo generate |
| type | string | Sim | Tipo de captcha, deve corresponder ao `type` retornado pelo generate |
| clicks | variante | Sim | Dados da resposta; o formato varia conforme o type (veja abaixo) |

**`clicks` por tipo**:

| type | tipo de clicks | Descrição | Tolerância de erro |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | Matriz de coordenadas de clique, na ordem order | raio 18px |
| slider | `int` | Deslocamento do deslizante no eixo X | ±4px |
| rotate | `int` | Ângulo de rotação (0-359) | ±5° |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

Após a verificação bem-sucedida, o backend grava `captcha_verified:{key}` no Redis (TTL 300s), e o endpoint de login libera a requisição com base nisso.
Em caso de falha, `code` é 422, `message` é `"验证失败，请重试"` e `data.valid` é `false`.

### 3.5 Login

```
POST /api/auth/login
```

- **Autenticação**: nenhuma necessária
- **Cabeçalho da requisição**: `API-Version: v1` (obrigatório)
- **Limite de taxa**: 10/min (por IP + caminho)

**Corpo da requisição**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| Campo | Tipo | Obrigatório | Regra de validação | Descrição |
|------|------|------|---------|------|
| username | string | Sim | min:3, max:50 | Nome de usuário |
| password | string | Sim | min:6, max:32 (texto puro) | Criptografado com AES-256-CBC-HMAC e codificado em Base64 (compatível com texto puro) |
| captcha_key | string | Sim | | Chave do captcha (deve ser verificada antes via `/api/captcha/verify`) |

### Protocolo de criptografia de senha

Usa **criptografia assimétrica RSA-2048**; a chave pública fica no código do frontend (pode ser exposta com segurança), a chave privada é mantida apenas pelo servidor.

```
Fluxo de criptografia (cliente):
  Chave pública RSA (PEM) → criptografia PKCS1v1.5 → codificação Base64 → transmissão

Fluxo de descriptografia (servidor, fallback em etapas):
  1. Descriptografia com chave privada RSA → sucesso e UTF-8 válido → usar o resultado descriptografado
  2. Descriptografia AES-256-CBC-HMAC → sucesso → usar o resultado descriptografado (compatibilidade com clientes antigos)
  3. Fallback para texto puro → usar diretamente a entrada original
```

A chave pública está embutida no aplicativo frontend e não precisa ser transmitida pela rede. A chave privada é armazenada apenas em `RSA_PRIVATE_KEY` no `.env` e não deve vazar.

> A criptografia simétrica AES é um esquema de compatibilidade com versões antigas e será removida quando todos os clientes migrarem para RSA.

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "a1b2c3d4",
      "username": "admin",
      "real_name": "管理员"
    }
  }
}
```

| Campo | Tipo | Descrição |
|------|------|------|
| access_token | string | Token de acesso JWT |
| refresh_token | string | Token de atualização JWT |
| expires_in | int | Validade do token de acesso (segundos), padrão 7200 |
| user.id | string | ID de usuário criptografado com hashid |
| user.username | string | Nome de usuário |
| user.real_name | string | Nome real |

**Erros possíveis**:
- 422: Falha na validação de parâmetros (campos obrigatórios ausentes, formato incorreto)
- 422: Conclua primeiro a verificação do captcha (captcha_key não passou em `/api/captcha/verify`)
- 401: Nome de usuário ou senha incorretos
- 403: Conta desabilitada
- 429: Conta bloqueada, tente novamente em 15 minutos (acionado por 5 falhas de login consecutivas)

### 3.6 Registro

```
POST /api/auth/register
```

- **Autenticação**: nenhuma necessária
- **Cabeçalho da requisição**: `API-Version: v1` (obrigatório)
- **Limite de taxa**: 5/min (por IP + caminho)

**Corpo da requisição**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| Campo | Tipo | Obrigatório | Regra de validação | Descrição |
|------|------|------|---------|------|
| username | string | Sim | min:3, max:50 | Nome de usuário (único) |
| password | string | Sim | min:6, max:32 (texto puro) | Criptografado com AES-256-CBC-HMAC e codificado em Base64 |
| real_name | string | Sim | max:50 | Nome real |
| captcha_key | string | Sim | | Chave do captcha (deve ser verificada antes via `/api/captcha/verify`) |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "注册成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "e5f6g7h8",
      "username": "newuser",
      "real_name": "新用户"
    }
  }
}
```

Os tokens JWT são retornados diretamente após o registro bem-sucedido; o status do usuário é habilitado por padrão (status=1).

### 3.7 Atualizar token

```
POST /api/auth/refresh
```

- **Autenticação**: nenhuma necessária
- **Cabeçalho da requisição**: `API-Version: v1` (obrigatório)
- **Limite de taxa**: padrão global (60/min)

**Corpo da requisição**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| refresh_token | string | Sim | refresh_token obtido no login/registro |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200
  }
}
```

Uma atualização bem-sucedida retorna simultaneamente novos access_token e refresh_token; os tokens antigos são invalidados automaticamente. Ao atualizar, a última hora de login e o IP do usuário são atualizados.

**Erros possíveis**:
- 422: Token de atualização ausente
- 401: Token de atualização inválido ou expirado

### 3.8 Métricas do Prometheus

```
GET /metrics
```

- **Autenticação**: nenhuma necessária
- **Limite de taxa**: nenhum
- **Formato de resposta**: formato de texto Prometheus (`text/plain; version=0.0.4`)

Endpoint público de métricas do Prometheus para coleta pelo Grafana/Prometheus.

**Exemplo de resposta**:
```
# HELP openadmin_http_requests_total Total number of HTTP requests.
# TYPE openadmin_http_requests_total gauge
openadmin_http_requests_total 15234

# HELP openadmin_active_users Number of active users.
# TYPE openadmin_active_users gauge
openadmin_active_users 156

# HELP openadmin_db_connection_status Database connection status (1=ok, 0=fail).
# TYPE openadmin_db_connection_status gauge
openadmin_db_connection_status 1

# HELP openadmin_redis_connection_status Redis connection status (1=ok, 0=fail).
# TYPE openadmin_redis_connection_status gauge
openadmin_redis_connection_status 1

# HELP openadmin_memory_usage_bytes Memory usage in bytes.
# TYPE openadmin_memory_usage_bytes gauge
openadmin_memory_usage_bytes 18874368
```

| Nome da métrica | Tipo | Descrição |
|------|------|------|
| `openadmin_http_requests_total` | gauge | Total acumulado de requisições HTTP |
| `openadmin_active_users` | gauge | Usuários ativos atuais (login nas últimas 24 horas) |
| `openadmin_db_connection_status` | gauge | Estado da conexão com o banco de dados, 1=ok, 0=erro |
| `openadmin_redis_connection_status` | gauge | Estado da conexão com o Redis, 1=ok, 0=erro |
| `openadmin_memory_usage_bytes` | gauge | Uso atual de memória do processo PHP (bytes) |

## 4. Painel

Todos os endpoints de administração são montados no grupo `/admin` e passam por três middlewares: `AdminAuth` (autenticação JWT), `AdminPermission` (verificação de permissões RBAC) e `OperationLog` (registro de operações).

### 4.1 Dados do painel

```
GET /admin/dashboard
```

- **Autenticação**: JWT + RBAC
- **Cache**: Redis 5 minutos

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "stats": [
      {
        "label": "用户总数",
        "value": "1280",
        "icon": "people",
        "color": "#1677FF",
        "trend": 12.5
      },
      {
        "label": "今日新增",
        "value": "23",
        "icon": "person_add",
        "color": "#52C41A"
      },
      {
        "label": "活跃用户",
        "value": "156",
        "icon": "bolt",
        "color": "#FA8C16"
      },
      {
        "label": "操作日志",
        "value": "432",
        "icon": "description",
        "color": "#722ED1"
      }
    ],
    "trends": {
      "dates": ["2026-04-22", "2026-04-23", "..."],
      "series": [
        { "name": "累计用户", "data": [1200, 1210, "..."], "color": "#1677FF" },
        { "name": "操作日志", "data": [45, 52, "..."], "color": "#52C41A" }
      ]
    },
    "distribution": {
      "user_status": [
        { "name": "启用", "value": 1250 },
        { "name": "禁用", "value": 30 }
      ]
    },
    "recent_logs": [
      {
        "id": "hashid...",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "user_name": "admin",
        "created_at": "2026-05-21 10:30:00"
      }
    ]
  }
}
```

| campos stats | Tipo | Descrição |
|------|------|------|
| label | string | Nome da métrica |
| value | string | Valor da métrica (tipo string) |
| icon | string | Nome do ícone Material |
| color | string | Cor do cartão |
| trend | float? | Taxa de crescimento diária (percentual); apenas "total de usuários" possui este campo |

| campos trends | Tipo | Descrição |
|------|------|------|
| dates | array{string} | Sequência de datas dos últimos 30 dias |
| series | array{object} | Dados da linha de tendência: name (nome), data (matriz de valores), color (cor) |

## 5. Gerenciamento de usuários

O `id` retornado por todos os endpoints de gerenciamento de usuários é uma string criptografada com hashid. Os campos de senha são excluídos das respostas. Telefones e e-mails são mascarados nos endpoints de lista e retornados em texto puro nos de detalhe (os campos criptografados do banco são descriptografados automaticamente pelo trait Encryptable).

### 5.1 Lista de usuários

```
GET /admin/user
```

- **Autenticação**: JWT + RBAC

**Parâmetros de consulta**:

| Parâmetro | Tipo | Obrigatório | Padrão | Descrição |
|------|------|------|------|------|
| page | int | Não | 1 | Número da página |
| limit | int | Não | 15 | Itens por página |
| keyword | string | Não | | Palavra-chave de busca, corresponde a nome de usuário e nome real |
| status | int | Não | | Filtro de status, 0=desabilitado, 1=habilitado |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "a1b2c3d4",
        "username": "admin",
        "real_name": "管理员",
        "phone": "138****5678",
        "email": "a***@example.com",
        "status": 1,
        "last_login_at": "2026-05-21 10:30:00",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 100,
    "page": 1,
    "limit": 15
  }
}
```

| Campo | Tipo | Descrição |
|------|------|------|
| id | string | ID de usuário criptografado com hashid |
| username | string | Nome de usuário |
| real_name | string | Nome real |
| phone | string | Telefone mascarado (formato `138****5678`) |
| email | string | E-mail mascarado (formato `a***@example.com`) |
| status | int | 1=habilitado, 0=desabilitado |
| last_login_at | string | Último login (datetime) |
| created_at | string | Data de criação (datetime) |

### 5.2 Criar usuário

```
POST /admin/user
```

- **Autenticação**: JWT + RBAC

**Corpo da requisição**:
```json
{
  "username": "newuser",
  "password": "123456",
  "real_name": "新用户",
  "phone": "13800138000",
  "email": "newuser@example.com",
  "status": 1
}
```

| Campo | Tipo | Obrigatório | Regra de validação | Descrição |
|------|------|------|---------|------|
| username | string | Sim | min:3, max:50 | Nome de usuário (único) |
| password | string | Sim | min:6, max:32 | Senha (armazenada com bcrypt) |
| real_name | string | Sim | max:50 | Nome real |
| phone | string | Não | | Telefone (criptografado com Encryptable) |
| email | string | Não | | E-mail (criptografado com Encryptable) |
| status | int | Não | in:0,1 | Status, padrão 1 (habilitado) |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "e5f6g7h8",
    "username": "newuser",
    "real_name": "新用户",
    "phone": "13800138000",
    "email": "newuser@example.com",
    "status": 1,
    "created_at": "2026-05-21 12:00:00"
  }
}
```

**Erros possíveis**:
- 422: Nome de usuário já existe
- 422: Falha na validação de parâmetros (campos obrigatórios ausentes)

### 5.3 Detalhe do usuário

```
GET /admin/user/{id}
```

- **Autenticação**: JWT + RBAC
- **Parâmetro de caminho**: `{id}` é o ID de usuário criptografado com hashid

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "管理员",
    "phone": "13800138000",
    "email": "admin@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00",
    "last_login_ip": "192.168.1.1",
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-05-20 08:00:00"
  }
}
```

No detalhe, `phone` e `email` são retornados em texto puro (armazenados criptografados no banco, descriptografados automaticamente pelo cast Encryptable) e não são mascarados. `password` e `id_card` nunca aparecem na resposta.

**Erros possíveis**:
- 404: Usuário não encontrado

### 5.4 Atualizar usuário

```
PUT /admin/user/{id}
```

- **Autenticação**: JWT + RBAC
- **Parâmetro de caminho**: `{id}` é o ID de usuário criptografado com hashid

**Corpo da requisição**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| real_name | string | Não | Nome real; se não enviado, mantém o valor original |
| password | string | Não | Nova senha; não altera se for string vazia ou omitida |
| phone | string | Não | Telefone |
| email | string | Não | E-mail |
| status | int | Não | 0=desabilitado, 1=habilitado |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13900139000",
    "email": "new@example.com",
    "status": 1,
    "updated_at": "2026-05-21 12:30:00"
  }
}
```

**Erros possíveis**:
- 404: Usuário não encontrado

### 5.5 Excluir usuário

```
DELETE /admin/user/{id}
```

- **Autenticação**: JWT + RBAC
- **Parâmetro de caminho**: `{id}` é o ID de usuário criptografado com hashid
- **Operação sensível**: requer confirmação de senha

**Corpo da requisição**:
```json
{
  "password": "admin_password"
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| password | string | Sim | Senha do usuário atualmente conectado (confirmação) |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Executa exclusão lógica (Eloquent SoftDeletes); os dados são marcados com deleted_at sem exclusão física.

**Erros possíveis**:
- 404: Usuário não encontrado
- 422: Operações sensíveis exigem confirmação de senha (password vazio)
- 422: Falha na verificação da senha (senha incorreta)

### 5.6 Exclusão em massa de usuários

```
POST /admin/user/batch/destroy
```

- **Autenticação**: JWT + RBAC
- **Operação sensível**: requer confirmação de senha

**Corpo da requisição**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| ids | array{string} | Sim | Matriz de IDs de usuários criptografados com hashid |
| password | string | Sim | Senha do usuário atualmente conectado (confirmação) |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

Executa exclusão lógica; `data.count` é o número efetivamente excluído.

**Erros possíveis**:
- 422: Selecione os usuários a excluir (ids vazio)
- 422: ID inválido (falha na decodificação hashid)
- 422: Falha na verificação da senha

### 5.7 Habilitar/desabilitar usuários em massa

```
POST /admin/user/batch/status
```

- **Autenticação**: JWT + RBAC

**Corpo da requisição**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| ids | array{string} | Sim | Matriz de IDs de usuários criptografados com hashid |
| status | int | Sim | 0=desabilitado, 1=habilitado |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

message muda dinamicamente conforme o valor de status: `"批量启用成功"` ou `"批量禁用成功"`.

**Erros possíveis**:
- 422: Selecione usuários (ids vazio)
- 422: Valor de status inválido (status não é 0 nem 1)

## 6. Gerenciamento de papéis

### 6.1 Lista de papéis

```
GET /admin/role
```

- **Autenticação**: JWT + RBAC

**Parâmetros de consulta**:

| Parâmetro | Tipo | Obrigatório | Padrão | Descrição |
|------|------|------|------|------|
| page | int | Não | 1 | Número da página |
| limit | int | Não | 15 | Itens por página |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "r1r2r3r4",
        "name": "超级管理员",
        "slug": "super_admin",
        "description": "拥有所有权限",
        "status": 1,
        "users_count": 3,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 5,
    "page": 1,
    "limit": 15
  }
}
```

| Campo | Tipo | Descrição |
|------|------|------|
| id | string | ID de papel criptografado com hashid |
| name | string | Nome do papel |
| slug | string | Identificador do papel (único, usado para verificação de permissões) |
| description | string | Descrição do papel |
| status | int | 1=habilitado, 0=desabilitado |
| users_count | int | Número de usuários com este papel |

### 6.2 Criar papel

```
POST /admin/role
```

- **Autenticação**: JWT + RBAC

**Corpo da requisição**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| Campo | Tipo | Obrigatório | Regra de validação | Descrição |
|------|------|------|---------|------|
| name | string | Sim | max:50 | Nome do papel |
| slug | string | Sim | max:50 | Identificador do papel |
| description | string | Não | | Descrição do papel, padrão string vazia |
| status | int | Não | | Status, padrão 1 |
| permission_ids | array{int} | Não | | Matriz de IDs de permissões (IDs INT originais, não hashids) |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "编辑员",
    "slug": "editor",
    "description": "内容编辑角色",
    "status": 1
  }
}
```

### 6.3 Atualizar papel

```
PUT /admin/role/{id}
```

- **Autenticação**: JWT + RBAC

**Corpo da requisição**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| name | string | Não | Nome do papel |
| description | string | Não | Descrição |
| status | int | Não | 0=desabilitado, 1=habilitado |
| permission_ids | array{int} | Não | Matriz de IDs de permissões; se enviada, as permissões do papel são sincronizadas (sobrescritas) |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "内容编辑",
    "slug": "editor",
    "description": "更新后的描述",
    "status": 1
  }
}
```

### 6.4 Excluir papel

```
DELETE /admin/role/{id}
```

- **Autenticação**: JWT + RBAC
- **Operação sensível**: requer confirmação de senha

**Corpo da requisição**:
```json
{
  "password": "admin_password"
}
```

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Ao excluir, as associações do papel com todas as permissões e usuários são removidas automaticamente e o registro do papel é excluído fisicamente.

## 7. Gerenciamento de permissões

As permissões usam uma estrutura de árvore (autorreferência parent_id) e são divididas em três tipos. O endpoint de lista retorna a árvore de permissões completa.

### 7.1 Árvore de permissões

```
GET /admin/permission
```

- **Autenticação**: JWT + RBAC

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "id": "p1p2p3p4",
      "parent_id": "0",
      "name": "用户管理",
      "slug": "/admin/user",
      "type": 1,
      "icon": "people",
      "path": "/user",
      "sort": 1,
      "created_at": "2026-01-01 00:00:00",
      "children": [
        {
          "id": "p5p6p7p8",
          "parent_id": "p1p2p3p4",
          "name": "用户列表",
          "slug": "/admin/user/index",
          "type": 2,
          "icon": "",
          "path": "/user/index",
          "sort": 1
        }
      ]
    }
  ]
}
```

| Campo | Tipo | Descrição |
|------|------|------|
| id | string | Criptografado com hashid |
| parent_id | string | hashid da permissão pai; "0" representa o nó raiz |
| name | string | Nome da permissão |
| slug | string | Identificador da permissão (rota/botão) |
| type | int | 1=menu, 2=botão, 3=API |
| icon | string | Ícone do menu (nome de ícone Material) |
| path | string | Caminho de rota do frontend |
| sort | int | Valor de ordenação (crescente) |
| children | array? | Lista de permissões filhas (recursiva); ausente se não houver nós filhos |

### 7.2 Criar permissão

```
POST /admin/permission
```

- **Autenticação**: JWT + RBAC

**Corpo da requisição**:
```json
{
  "parent_id": 0,
  "name": "系统设置",
  "slug": "/admin/config",
  "type": 1,
  "icon": "settings",
  "path": "/config",
  "sort": 99
}
```

| Campo | Tipo | Obrigatório | Regra de validação | Descrição |
|------|------|------|---------|------|
| parent_id | int | Não | | ID da permissão pai (tipo INT original), padrão 0 |
| name | string | Sim | max:50 | Nome da permissão |
| slug | string | Sim | max:100 | Identificador da permissão |
| type | int | Sim | in:1,2,3 | 1=menu, 2=botão, 3=API |
| icon | string | Não | | Ícone do menu, padrão vazio |
| path | string | Não | | Caminho de rota do frontend, padrão vazio |
| sort | int | Não | | Valor de ordenação, padrão 0 |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "p9p0a1b2",
    "parent_id": "0",
    "name": "系统设置",
    "slug": "/admin/config",
    "type": 1,
    "icon": "settings",
    "path": "/config",
    "sort": 99
  }
}
```

### 7.3 Atualizar permissão

```
PUT /admin/permission/{id}
```

- **Autenticação**: JWT + RBAC

**Corpo da requisição**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| name | string | Não | Nome da permissão |
| icon | string | Não | Ícone |
| path | string | Não | Caminho de rota |
| sort | int | Não | Valor de ordenação |

### 7.4 Excluir permissão

```
DELETE /admin/permission/{id}
```

- **Autenticação**: JWT + RBAC
- **Operação sensível**: requer confirmação de senha

**Corpo da requisição**:
```json
{
  "password": "admin_password"
}
```

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Ao excluir, todas as permissões filhas são excluídas em cascata (registros cujo `parent_id` é o ID da permissão atual) e as associações com todos os papéis são removidas.

## 8. Configuração do sistema

As configurações do sistema são únicas pela combinação de `group` + `key`.

### 8.1 Lista de configurações

```
GET /admin/config
```

- **Autenticação**: JWT + RBAC

**Parâmetros de consulta**:

| Parâmetro | Tipo | Obrigatório | Padrão | Descrição |
|------|------|------|------|------|
| page | int | Não | 1 | Número da página |
| limit | int | Não | 15 | Itens por página |
| group | string | Não | | Filtrar por grupo de configuração |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "c1c2c3c4",
        "group": "system",
        "key": "site_name",
        "value": "开放管理后台",
        "type": "string",
        "description": "站点名称",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 20,
    "page": 1,
    "limit": 15
  }
}
```

| Campo | Tipo | Descrição |
|------|------|------|
| id | string | hashid |
| group | string | Grupo de configuração (ex.: `system`, `email`, `storage`) |
| key | string | Chave de configuração |
| value | string | Valor de configuração |
| type | string | Indicação do tipo de valor (`string`, `integer`, `boolean`, `json`, etc.) |
| description | string | Descrição da configuração |

### 8.2 Criar configuração

```
POST /admin/config
```

- **Autenticação**: JWT + RBAC

**Corpo da requisição**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| Campo | Tipo | Obrigatório | Regra de validação | Descrição |
|------|------|------|---------|------|
| group | string | Sim | max:100 | Grupo de configuração |
| key | string | Sim | max:100 | Chave de configuração (única dentro do mesmo grupo) |
| value | string | Sim | | Valor de configuração |
| type | string | Não | | Tipo de valor, padrão `string` |
| description | string | Não | | Descrição da configuração, padrão vazio |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "c5c6c7c8",
    "group": "email",
    "key": "smtp_host",
    "value": "smtp.example.com",
    "type": "string",
    "description": "SMTP 服务器地址"
  }
}
```

**Erros possíveis**:
- 422: Item de configuração já existe (mesmo group + key)

### 8.3 Atualizar configuração

```
PUT /admin/config/{id}
```

- **Autenticação**: JWT + RBAC

**Corpo da requisição**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| value | string | Não | Atualizar o valor de configuração |
| type | string | Não | Atualizar o tipo de valor |
| description | string | Não | Atualizar o texto da descrição |

### 8.4 Excluir configuração

```
DELETE /admin/config/{id}
```

- **Autenticação**: JWT + RBAC
- **Operação sensível**: requer confirmação de senha

**Corpo da requisição**:
```json
{
  "password": "admin_password"
}
```

Exclui fisicamente o registro de configuração.

## 9. Registro de operações

O registro de operações é somente leitura; o middleware `OperationLog` grava automaticamente a cada requisição POST/PUT/DELETE. Os campos armazenados incluem `user_id`, `action`, `method`, `path`, `ip`, `source`, `input`.

### 9.1 Lista do registro de operações

```
GET /admin/log
```

- **Autenticação**: JWT + RBAC

**Parâmetros de consulta**:

| Parâmetro | Tipo | Obrigatório | Padrão | Descrição |
|------|------|------|------|------|
| page | int | Não | 1 | Número da página |
| limit | int | Não | 15 | Itens por página |
| user_id | int | Não | | Filtro exato por ID de usuário (tipo INT original) |
| action | string | Não | | Filtro exato por ação |
| path | string | Não | | Filtro aproximado por caminho da requisição |
| start_date | string | Não | | Data de início (formato Y-m-d) |
| end_date | string | Não | | Data de fim (formato Y-m-d) |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "l1l2l3l4",
        "user_name": "admin",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "source": "web",
        "input": "{\"username\":\"admin\"}",
        "created_at": "2026-05-21 10:30:00"
      }
    ],
    "total": 500,
    "page": 1,
    "limit": 15
  }
}
```

| Campo | Tipo | Descrição |
|------|------|------|
| id | string | hashid |
| user_name | string | Nome de usuário da operação (via relação user; operações não autenticadas exibem "系统") |
| action | string | Descrição da ação |
| method | string | Método HTTP (POST/PUT/DELETE) |
| path | string | Caminho da requisição |
| ip | string | IP do cliente |
| source | string | Origem da requisição |
| input | string | Parâmetros da requisição como string JSON (sem arquivos) |
| created_at | string | Hora da operação (datetime) |

## 10. Perfil pessoal

Os endpoints do perfil exigem apenas autenticação JWT (sem verificação RBAC — o middleware `AdminPermission` deve adicioná-los à lista de permissões).

### 10.1 Atualizar informações pessoais

```
PUT /admin/profile
```

- **Autenticação**: JWT

**Corpo da requisição**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| real_name | string | Não | Nome real |
| phone | string | Não | Telefone (criptografado com Encryptable) |
| email | string | Não | E-mail (criptografado com Encryptable) |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13800138000",
    "email": "me@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00"
  }
}
```

Na resposta, `phone` e `email` são retornados em texto puro; `password` e `id_card` são removidos.

### 10.2 Alterar senha

```
PUT /admin/profile/password
```

- **Autenticação**: JWT

**Corpo da requisição**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| Campo | Tipo | Obrigatório | Regra de validação | Descrição |
|------|------|------|---------|------|
| old_password | string | Sim | | Senha atual |
| new_password | string | Sim | min:6, max:32 | Nova senha |

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**Erros possíveis**:
- 422: Informe a senha antiga e a nova
- 422: Senha antiga incorreta
- 422: A nova senha deve ter entre 6 e 32 caracteres

### 10.3 Sair

```
POST /admin/profile/logout
```

- **Autenticação**: JWT

**Corpo da requisição**: nenhum (sem requestBody; o token é lido do cabeçalho Authorization)

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

Lógica de saída: decodificar o JWT para obter a validade restante (exp - now), gravar o hash md5 do token na lista negra do Redis `jwt_blacklist:{md5}` com TTL = validade restante. Tokens na lista negra são bloqueados pelo middleware `AdminAuth`, retornando 401.

Sem token, retorna 401. Tokens expirados/inválidos (a decodificação lança exceção) ainda são considerados saída bem-sucedida.

## 11. Importação e exportação

### 11.1 Exportar Excel

```
POST /admin/export/excel
```

- **Autenticação**: JWT + RBAC
- **Tipo de resposta**: download de arquivo (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Corpo da requisição**:
```json
{
  "table": "admin_user",
  "columns": ["username", "real_name", "phone", "status", "created_at"],
  "conditions": {
    "status": 1
  },
  "title": "用户列表导出"
}
```

| Campo | Tipo | Obrigatório | Padrão | Descrição |
|------|------|------|------|------|
| table | string | Não | `admin_user` | Tabela a exportar. Suportadas: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | Não | | Matriz de nomes de colunas a exportar; vazia exporta todas as colunas da tabela |
| conditions | object | Não | `{}` | Condições de filtro, pares chave-valor; valores não vazios são usados no WHERE |
| title | string | Não | `数据导出` | Título do Excel (exibido como nome da planilha) |

**Tabelas e colunas suportadas**:

| table | Colunas disponíveis |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

Os campos sensíveis `phone`, `email`, `id_card` são mascarados automaticamente na exportação. Limite de dados: 10000 linhas. A primeira linha do Excel é congelada e o autofiltro está ativado.

### 11.2 Exportar PDF

```
POST /admin/export/pdf
```

- **Autenticação**: JWT + RBAC
- **Tipo de resposta**: download de arquivo (`application/pdf`, A4 paisagem)

**Corpo da requisição**:
```json
{
  "type": "dashboard",
  "title": "管理仪表盘报告",
  "data": {
    "stats": [
      { "label": "用户总数", "value": "1280" }
    ]
  }
}
```

Ou modo tabela:
```json
{
  "type": "table",
  "title": "用户列表",
  "data": {
    "columns": ["用户名", "真实姓名", "状态"],
    "rows": [
      ["admin", "管理员", "启用"],
      ["editor", "编辑员", "启用"]
    ]
  }
}
```

| Campo | Tipo | Obrigatório | Padrão | Descrição |
|------|------|------|------|------|
| type | string | Não | `table` | Tipo de exportação: `table` / `dashboard` |
| title | string | Não | `数据导出` | Título do PDF |
| data | object | Não | `{}` | Dados a exportar |

Com `type=dashboard`, `data` deve conter uma matriz `stats` (renderizada como cartões); com `type=table`, `data` deve conter as matrizes `columns` e `rows`.

O modelo do PDF inclui informações de copyright e um carimbo de tempo de exportação.

### 11.3 Importar usuários (Excel)

```
POST /admin/import/users
```

- **Autenticação**: JWT + RBAC
- **Tipo de requisição**: `multipart/form-data` (upload de arquivo)

**Campos do formulário**:

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| file | file | Sim | Formato `.xlsx` ou `.xls` |

**Requisitos das colunas do Excel**:

| Nome da coluna | Obrigatório | Descrição |
|------|------|------|
| username | Sim | Nome de usuário (único) |
| password | Sim | Senha (armazenada como hash bcrypt) |
| real_name | Sim | Nome real |
| phone | Não | Telefone |
| email | Não | E-mail |
| status | Não | Status, padrão 1 |

A linha 1 é o cabeçalho das colunas (insensível a maiúsculas/minúsculas); os dados começam na linha 2.

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "导入完成",
  "data": {
    "total": 10,
    "success": 8,
    "failed": 2,
    "errors": [
      { "row": 3, "reason": "用户名为空" },
      { "row": 7, "reason": "用户名 zhangsan 已存在" }
    ]
  }
}
```

| Campo | Tipo | Descrição |
|------|------|------|
| total | int | Número total de linhas (sem a linha de cabeçalho) |
| success | int | Número importado com sucesso |
| failed | int | Número de falhas |
| errors | array | Detalhes das falhas: row (número da linha do Excel) e reason (motivo) |

## 12. Upload de arquivos

```
POST /admin/upload
```

- **Autenticação**: JWT + RBAC
- **Tipo de requisição**: `multipart/form-data`

**Campos do formulário**:

| Campo | Tipo | Obrigatório | Descrição |
|------|------|------|------|
| file | file | Sim | O arquivo a enviar |

**Tipos de arquivo permitidos**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**Tamanho máximo do arquivo**: 10MB

**Exemplo de resposta**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

Os arquivos são armazenados em diretórios por data `public/upload/{Y-m-d}/` com nome `md5(uniqid) + extensão original`. `url` é um caminho relativo à raiz do site.

**Erros possíveis**:
- 422: Selecione um arquivo (nenhum enviado)
- 422: Tipo de arquivo não suportado
- 422: O tamanho do arquivo não pode exceder 10MB
- 500: Falha no upload do arquivo (arquivo inválido)

## 13. Cabeçalhos de resposta

Todos os endpoints (injetados na camada de middleware global) incluem os seguintes cabeçalhos de resposta:

| Cabeçalho | Descrição |
|----|------|
| `X-RateLimit-Limit` | Limite máximo de taxa (quantidade) |
| `X-RateLimit-Remaining` | Quantidade restante de requisições |
| `X-RateLimit-Reset` | Carimbo de tempo de reinício da janela de limite |
| `Retry-After` | Retornado apenas quando o limite é acionado; segundos de espera recomendados |
| `X-Content-Type-Options` | `nosniff` (padrão do webman, desativa o sniffing MIME) |
| `X-Frame-Options` | `DENY` (fornecido pelo middleware CORS/configuração base do webman) |

Detalhes do limite de taxa:
- Limite global padrão: 60/min / IP+caminho
- Endpoint de login `/api/auth/login`: 10/min
- Endpoint de registro `/api/auth/register`: 5/min
- Usa o algoritmo atômico de janela deslizante do Redis (Lua ZSET), evitando corridas TOCTOU
- Quando o Redis está indisponível, fail open (deixar passar); as requisições não são bloqueadas

## 14. Fluxo de autenticação

A sequência completa de autenticação:

```
1. O cliente solicita POST /api/captcha/generate
   (Cabeçalho da requisição: API-Version: v1)
    ↓
   O servidor retorna: key + type(click|slider|rotate) + imagem base64 + extra(dados conforme o tipo)
   
2. O usuário conclui a interação do captcha (clique/arrastar/girar) e o cliente coleta a resposta
   
3. O cliente solicita POST /api/captcha/verify
   (Cabeçalho da requisição: API-Version: v1, Content-Type: application/json)
   Corpo da requisição: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // matriz de coordenadas
   - type=slider: clicks = 120                   // deslocamento em X
   - type=rotate: clicks = 315                   // ângulo de rotação
    ↓
   Servidor:
   a. Lê os dados captcha:key do armazenamento (TTL 300s)
   b. Valida a resposta conforme o type (click: distância euclidiana ≤18px / slider: ±4px / rotate: ±5°)
   c. Validação bem-sucedida → grava Redis `captcha_verified:{key}` = 1 (TTL 300s)
   d. Validação falhou → retorna 422, contador +1, key invalidada após 3 tentativas
    ↓
   O servidor retorna: { valid: true/false }

4. O cliente solicita POST /api/auth/login
   (Cabeçalho da requisição: API-Version: v1, Content-Type: application/json)
   Corpo da requisição: { username, password(criptografada), captcha_key }
    ↓
   Servidor:
   a. Validação de parâmetros → 422
   b. Verifica se captcha_verified:{key} existe → 422
   c. Exclui captcha_verified:{key} (uso único)
   d. Descriptografa a senha: EncryptionService::decrypt(password) → texto puro
   e. Valida as credenciais (password_verify) → 401
   f. Verifica o status da conta → 403/429
   g. Emite o JWT (access + refresh) → 200
   h. Atualiza last_login_at / last_login_ip
    ↓
   O cliente salva: access_token, refresh_token, expires_in

5. As requisições subsequentes carregam o JWT
   Cabeçalho da requisição: Authorization: Bearer <access_token>
    ↓
   Middleware AdminAuth:
   a. Extrai o token Bearer
   b. Verifica a lista negra (Redis jwt_blacklist:{md5}) → 401
   c. Decodifica o JWT e valida a expiração → 401
   d. Define $request->adminId = campo sub
    ↓
   Middleware AdminPermission:
   a. Resolve o identificador de permissão da rota do recurso
   b. Consulta os papéis do usuário → permissões dos papéis e faz a correspondência
   c. Sem permissão → 403
    ↓
   Controller processa a requisição
    ↓
   Response + cabeçalhos X-RateLimit-*

6. Atualizar antes de o access token expirar
   O cliente solicita POST /api/auth/refresh
   Corpo da requisição: { refresh_token: "..." }
    ↓
   O servidor decodifica refresh_token → emite novos access + refresh
    ↓
   O cliente atualiza seus tokens locais

7. Sair
   O cliente solicita POST /admin/profile/logout
   Cabeçalho da requisição: Authorization: Bearer <access_token>
    ↓
   Servidor:
   a. Decodifica o JWT para obter o TTL restante
   b. Grava na lista negra do Redis: jwt_blacklist:{md5(token)} = 1, TTL = validade restante
   c. Retorna sucesso
```

### Estrutura do JWT

- **access_token**: `{ sub: <user_id>, username: "<name>" }`, TTL padrão 7200 segundos (controlado pela configuração JWT `default_expire`)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`, TTL padrão 1209600 segundos (controlado pela configuração JWT `refresh_expire`, ou seja, 14 dias)

### Gerenciamento de segurança

- As senhas são armazenadas como hash `PASSWORD_BCRYPT`
- As senhas são criptografadas em trânsito com AES-256-CBC-HMAC (o cliente criptografa → o servidor descriptografa), com fallback para texto puro
- Os campos sensíveis (phone, email, id_card) são criptografados/descriptografados de forma transparente na camada do banco com `erikwang2013/encryptable`
- Os IDs na camada da API são transmitidos criptografados com `erikwang2013/hashids`, evitando expor a sequência original de IDs snowflake
- SecurityFilter verifica globalmente XSS, injeção SQL, path traversal e injeção de comandos; mesmo IP 5 vezes/60s → lista negra temporária de 15 minutos
- Operações sensíveis (excluir usuários, papéis, permissões, configurações) exigem confirmação de senha do usuário atualmente conectado
- Limite de sessões simultâneas: no máximo 3 tokens válidos por usuário; ao fazer login em um 4.º dispositivo, o token mais antigo é forçado para a lista negra
- Bloqueio de conta: 5 falhas de login consecutivas acionam um bloqueio de 15 minutos, durante o qual 429 é retornado

## 15. Implantação e operação

### Docker Compose

A raiz do projeto fornece `docker-compose.yml` orquestrando 5 serviços (Nginx, webman app, MySQL, Redis, Elasticsearch). O PHP é construído via `Dockerfile` (baseado em `php:8.3-cli`, com OPcache ativado).

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` define o pipeline de integração contínua do GitHub Actions:
- Verificação de sintaxe `php -l`
- Testes unitários PHPUnit
- Análise estática `flutter analyze`

### Backup do banco de dados

O diretório `database/backup/` fornece scripts de backup e restauração:
- `backup.sh` — backups compactados com mysqldump + gzip, limpa automaticamente backups com mais de 30 dias
- `restore.sh` — restauração interativa que lista os backups disponíveis

### Configuração de segurança do Nginx

Para implantação em produção, consulte `docs/nginx-security.conf` para o endurecimento de segurança do proxy reverso.
