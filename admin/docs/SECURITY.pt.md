# Documento de Arquitetura de Segurança

**语言 / Languages:** [中文](SECURITY.md) · [English](SECURITY.en.md) · [한국어](SECURITY.ko.md) · [Русский](SECURITY.ru.md) · [Deutsch](SECURITY.de.md) · [Français](SECURITY.fr.md) · [Español](SECURITY.es.md) · [Português](SECURITY.pt.md) · [हिन्दी](SECURITY.hi.md) · [العربية](SECURITY.ar.md) · [বাংলা](SECURITY.bn.md) · [Bahasa Indonesia](SECURITY.id.md) · [日本語](SECURITY.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Visão geral da defesa em profundidade

O sistema adota um modelo de defesa em profundidade de 7 camadas, filtrando solicitações maliciosas de fora para dentro, camada por camada, garantindo que, mesmo se uma camada individual falhar, as linhas de defesa subsequentes ainda forneçam cobertura.

Toda a cadeia de middleware é executada na seguinte ordem (ver `config/middleware.php`):

```
请求 → Cors → Locale(Accept-Language) → SecurityMiddleware (erikwang2013/security-php, 31种检测器) → RateLimit → [路由组中间件: AdminAuth → AdminPermission → OperationLog] → Controller
```

| Camada | Middleware / mecanismo | Objetivo de proteção |
|----|--------|---------|
| 1 | SecurityMiddleware (erikwang2013/security-php) | 31 detecções de ataques + validação de método HTTP + limite de tamanho do corpo da solicitação + validação de Content-Type + CSRF + lista negra de escalada de ataques por IP |
| 2 | Cors | Segurança entre origens + injeção de cabeçalhos de resposta de segurança |
| 3 | RateLimit | Limitação de taxa com janela deslizante Redis, contra força bruta |
| 4 | AdminAuth | Autenticação JWT + logout por lista negra |
| 5 | AdminPermission | Autorização RBAC com granularidade method.path |
| 6 | OperationLog | Auditoria de operações + rastreamento da origem do cliente |
| 7 | Criptografia de dados | Ofuscação de IDs com Hashids + criptografia de banco Encryptable + criptografia de transporte EncryptionService |

As três camadas de frontend (Flutter) possuem validação de entrada independente; o backend não confia em nada, e cada camada se defende de forma independente.

---

## 2. Motor de detecção de ataques

## 2. 攻击检测引擎 (erikwang2013/security-php)

A detecção de ataques foi migrada do SecurityMiddleware próprio para o pacote de segurança dedicado `erikwang2013/security-php` v1.1+, que fornece **31 detectores**, cobrindo 5 grandes categorias de ataques.

### 2.1 Classificação dos detectores

**Ataques de injeção (11):** XSS, injeção SQL, injeção de comandos, injeção NoSQL, injeção LDAP, injeção XPath, JNDI/Log4Shell, inclusão do lado do servidor SSI, injeção GraphQL, injeção de templates SSTI

**Ataques de protocolo e de solicitação (9):** SSRF, XXE, injeção de cabeçalho de resposta HTTP, ataque de cabeçalho Host, Request Smuggling, Open Redirect, bypass de CORS, sequestro de WebSocket, DNS Rebinding

**Validação da camada de protocolo HTTP (6):** validação de método HTTP (405), limite de tamanho do corpo da solicitação (413), validação de Content-Type (415), verificação de Origin CSRF, lista negra de escalada de ataques por IP, detecção de vazamento de dados sensíveis

**Ataques de dados e serialização (5):** desserialização PHP, injeção de fórmula CSV, injeção de cabeçalho de e-mail, ataques JWT (análise estruturada), JS Prototype Pollution

**Ataques de arquivo e caminho (2):** path traversal, upload de arquivo malicioso

### 2.2 Modos de tratamento

Cada detector suporta independentemente dois modos:
- `block` — bloqueia ao detectar um ataque, retornando o código de status configurado
- `log` — apenas registra em log sem bloquear (`header_injection`, `ssti`, `nosql_injection` usam o modo log por padrão para evitar falsos positivos)

### 2.3 Lista negra de escalada de ataques por IP

O mesmo IP que dispara 5 detecções de ataque em 60 segundos → banido automaticamente por 15 minutos. O backend de armazenamento pode ser Redis (distribuído), File (JSON de máquina única) ou Cache (arquivo independente de alta concorrência); a configuração atual usa armazenamento Redis.

### 2.4 Logs de segurança

Local do arquivo: `runtime/logs/security.log` (rotação automática, 10MB/arquivo)

---

## 4. Cabeçalhos de resposta de segurança

Todos os cabeçalhos são injetados no middleware `Cors`, anexados a cada resposta via `$response->withHeaders()`.

| Cabeçalho | Valor | Função |
|----|-----|------|
| Access-Control-Allow-Origin | `*` | Permite qualquer origem entre domínios (cenário de painel administrativo em intranet) |
| Access-Control-Allow-Methods | `GET,POST,PUT,DELETE,OPTIONS` | Conjunto de métodos permitidos |
| Access-Control-Allow-Headers | `Authorization,Content-Type,API-Version` | Cabeçalhos personalizados permitidos |
| Access-Control-Max-Age | `86400` | Cache de preflight por 24 horas |
| X-Content-Type-Options | `nosniff` | Proíbe o MIME sniffing do navegador |
| X-Frame-Options | `DENY` | Proíbe toda incorporação em iframe, previne clickjacking |
| X-XSS-Protection | `1; mode=block` | Ativa o filtro XSS integrado do navegador e bloqueia a renderização da página |
| Referrer-Policy | `strict-origin-when-cross-origin` | Mesma origem envia URL completa; entre origens envia apenas o domínio |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | Desativa APIs de câmera/microfone/geolocalização no site inteiro |

Solicitações preflight OPTIONS retornam diretamente resposta vazia 204, sem entrar na cadeia de middleware subsequente.

### 4.2 Content-Security-Policy (CSP)

Injetado no middleware Cors juntamente com outros cabeçalhos de segurança, fornece defesa em profundidade limitando as origens dos recursos que o navegador pode carregar e executar.

| Cabeçalho | Valor | Função |
|----|-----|------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | Restringe origens de scripts/estilos/imagens/conexões/frames/formulários etc. |
| X-Permitted-Cross-Domain-Policies | `none` | Proíbe carregamento de arquivos de política entre domínios do Adobe Flash/PDF |

Pontos-chave da política CSP:
- `default-src 'self'`: por padrão, apenas recursos da mesma origem
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'`: permite scripts da mesma origem + scripts inline (necessário para Flutter Web) + eval (necessário para depuração do Flutter Web)
- `frame-ancestors 'none'`: proíbe incorporação em iframe por qualquer página, dupla garantia com X-Frame-Options: DENY
- `base-uri 'self'`: restringe a tag `<base>` a apontar apenas para a mesma origem
- `form-action 'self'`: restringe formulários a enviar apenas para a mesma origem

---

## 5. Estratégia de limitação de taxa

### Algoritmo

Janela deslizante Redis Sorted Set + script Lua atômico, operações principais:

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

O script Lua é executado em thread única no servidor Redis, sendo **naturalmente atômico**, eliminando a condição de corrida TOCTOU (Time-of-check to Time-of-use).

### Configuração de limitação

| Rota | Limite | Janela | Cenário |
|------|------|------|------|
| Padrão (todas as rotas) | 60 vezes/minuto | 60s | API geral |
| `/api/auth/login` | 10 vezes/minuto | 60s | Login (contra força bruta) |
| `/api/auth/register` | 5 vezes/minuto | 60s | Registro (contra registro em massa) |

### Cabeçalhos de resposta

Ao disparar a limitação, retorna HTTP 429 com body JSON:
```json
{"code": 429, "message": "请求过于频繁，请稍后再试", "data": []}
```

Todas as respostas (incluindo as normais) carregam os seguintes cabeçalhos:

| Cabeçalho | Descrição |
|----|------|
| X-RateLimit-Limit | Número máximo de solicitações permitidas na janela atual |
| X-RateLimit-Remaining | Número de solicitações restantes na janela atual |
| X-RateLimit-Reset | Timestamp Unix da redefinição da janela |
| Retry-After | Enviado apenas quando há limitação; segundos sugeridos de espera |

### Estratégia de degradação

Quando o Redis apresenta falha (timeout de conexão, indisponibilidade etc.), aplica-se **fail-open**:

```php
try {
    $result = Redis::eval($lua, ...);
} catch (\Throwable $e) {
    return $handler($request); // Redis down, 放行所有请求
}
```

Prefere-se perder temporariamente a proteção de limitação a bloquear solicitações comerciais normais.

### 5.4 Mecanismo de bloqueio de conta

Além da limitação de taxa, o endpoint de login adiciona um mecanismo de **bloqueio de conta** para prevenir força bruta direcionada a usuários específicos.

**Fluxo de bloqueio**:

```
登录失败 → Redis INCR account_lockout:{userId} TTL=900s
连续 5 次失败 → Redis SETEX account_locked:{userId} 900 1
            → 返回 429 "账号已被锁定，请15分钟后再试"
            → 清除计数器 DEL account_lockout:{userId}
```

**Comportamento durante o bloqueio**:

Durante o bloqueio, todas as solicitações de login retornam diretamente 429, sem validação de senha, bloqueando completamente tentativas de força bruta.

**Constantes de configuração**:

| Constante | Valor | Significado |
|------|-----|------|
| MAX_LOGIN_ATTEMPTS | 5 | Número máximo de falhas consecutivas |
| LOCKOUT_DURATION | 900 | Duração do bloqueio (segundos), ou seja, 15 minutos |

Observação: o bloqueio de conta é baseado em `userId`, não em IP, portanto, trocar de IP não contorna o bloqueio. Combinado com a limitação por IP (10 vezes/minuto), forma dupla proteção:
- Nível de IP: limitação de 10 vezes/minuto impede força bruta distribuída
- Nível de conta: bloqueio após 5 falhas impede força bruta direcionada

---

## 6. Autenticação e autorização

### 6.1 Autenticação JWT

Implementada pelo middleware AdminAuth, montado nos grupos de rotas que exigem autenticação.

**Configuração de parâmetros** (`config/plugin/erikwang2013/jwt/jwt`, injetada via `.env`):

| Parâmetro | Valor | Descrição |
|------|-----|------|
| Algoritmo | HS256 | Assinatura simétrica HMAC-SHA256 |
| Chave | `JWT_SECRET` | Injetada via variável de ambiente; deve ser alterada em produção |
| access_token TTL | 7200s (2h) | `JWT_TTL` |
| refresh_token TTL | 1209600s (14d) | `JWT_REFRESH_TTL` |
| Emissor | `open-admin` | `JWT_ISSUER` |
| Audiência | `open-admin` | `JWT_AUDIENCE` |

**Extração do Token**: extraído do cabeçalho `Authorization: Bearer <token>`, removendo o prefixo `Bearer ` para obter o JWT original.

**Fluxo de autenticação**:
1. Token vazio → 401 direto `{"code": 401, "message": "未登录"}`
2. Verifica lista negra Redis `jwt_blacklist:{md5(token)}` → encontrado → 401 `Token已失效，请重新登录`
3. JWT decode → falha (expirado/assinatura incompatível) → 401 `Token已过期或无效`
4. Sucesso → injeta `$request->adminId` e `$request->adminUsername`

**Mecanismo de lista negra**: ao fazer logout, `md5(token)` é gravado no Redis com TTL igual ao tempo restante de validade do JWT. Quando o Redis falha, a verificação da lista negra é ignorada (fail-open); nesse caso, tokens já deslogados podem ser usados por um curto período, mas a curta validade do próprio JWT (2h) atua como proteção de segurança.

### 6.2 Limite de sessões concorrentes

Para evitar o uso abusivo de tokens vazados em múltiplos dispositivos, o sistema limita o número de tokens válidos que um mesmo usuário pode manter simultaneamente.

**Lógica de limitação**:

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

**Constantes de configuração**:

| Constante | Valor | Significado |
|------|-----|------|
| MAX_CONCURRENT_SESSIONS | 3 | Número máximo de tokens concorrentes por usuário |

**Cenário de expulsão**: quando o usuário faz login no 4º dispositivo, o token do 1º dispositivo é forçado para a lista negra; solicitações subsequentes retornam 401 "Token已失效，请重新登录".

No logout, o token atual é removido do conjunto. Quando o token expira naturalmente, a chave Redis expira automaticamente e os membros do conjunto diminuem.

### 6.3 Modelo de permissões RBAC

Implementado pelo middleware AdminPermission.

**Modelo de dados**: associação de três camadas User -> Role -> Permission

- `erik_admin_user` (tabela de usuários)
- `erik_admin_user_role` (tabela de associação usuário-role)
- `erik_admin_role` (tabela de roles)
- `erik_admin_role_permission` (tabela de associação role-permissão)
- `erik_admin_permission` (tabela de permissões)

**Tipos de permissão**:
| type | Significado | Exemplo |
|------|------|------|
| 1 | Permissão de menu | Controla a visibilidade da navegação à esquerda |
| 2 | Permissão de botão | Controla botões de operação na página (criar/editar/excluir) |
| 3 | Permissão de API | Controla chamadas de interface do backend |

Formato do identificador de permissão de API: `{method}.{path}`

Por exemplo:
- `post.admin/user` — criar usuário
- `put.admin/user` — editar usuário
- `delete.admin/user` — excluir usuário
- `get.admin/user` — ver lista de usuários

**Fluxo de autorização**:
1. `$request->adminId` vazio → libera (rota sem pré-autenticação configurada)
2. Obtém usuário → roles (pulando roles desabilitadas com `status=0`) → lista de permissões
3. Super administrador (`slug = '*'`) → libera diretamente
4. Constrói `strtolower(method) . '.' . trim(path, '/')` → compara com a lista de permissões
5. Sem correspondência → 403 `{"code": 403, "message": "无权限访问"}`

**Confirmação secundária**: BaseController fornece o método `confirmPassword()`; operações sensíveis (exclusão de usuários, exportação de dados etc.) exigem a senha atual na camada Controller, evitando operações não autorizadas após sequestro de sessão.

---

## 7. Logs de auditoria

### 7.1 Logs de operação

O middleware OperationLog registra automaticamente logs de operação para solicitações POST / PUT / DELETE. Solicitações GET não são registradas.

**Campos registrados**:

| Campo | Origem | Descrição |
|------|------|------|
| id | SnowflakeService::generate() | ID único global |
| user_id | `$request->adminId` | ID do operador; 0 se não autenticado |
| action | `$request->method()` | Equivalente ao method |
| method | `$request->method()` | POST / PUT / DELETE |
| path | `$request->path()` | Caminho da solicitação |
| ip | `$request->getRealIp()` | IP real do cliente |
| source | detectSource() | Plataforma de origem do cliente |
| input | body da solicitação (JSON mascarado) | Dados enviados pela operação |
| created_at | `date('Y-m-d H:i:s')` | Horário da operação |

**Filtro de campos sensíveis**: percorre recursivamente o corpo da solicitação; os valores dos seguintes campos são substituídos por `***`:

`password`, `old_password`, `new_password`, `new_password_confirmation`, `token`, `secret`, `access_token`, `refresh_token`

**Detecção de origem do cliente** (`detectSource()`): por prioridade:

1. Primeiro lê o cabeçalho personalizado `X-Client-Platform` (declarado explicitamente por clientes nativos)
2. Degrada para inferência pela string User-Agent (ordem de detecção do método `detectSource()`):

| Plataforma | Palavras-chave UA |
|------|----------|
| iPadOS | `iPad` |
| macOS | `Macintosh`, `Mac OS` |
| Windows | `Windows` |
| Linux | `Linux` |
| iOS | `iPhone`, `iOS`, `CFNetwork` |
| Android | `Android` |
| HarmonyOS | `HarmonyOS`, `OpenHarmony` |
| Web | Valor padrão de fallback |

**Tolerância a falhas**: falha ao escrever o log não bloqueia solicitações comerciais (`catch (\Throwable)` engole silenciosamente).

### 7.2 Logs de segurança

**Local do arquivo**: `runtime/logs/security.log`

**Conteúdo registrado**:
- Logs de bloqueio de ataques: categoria do ataque, IP, caminho, campo, origem, trecho do payload (primeiros 200 caracteres)
- Notificações de banimento de IP: IP banido, número de disparos

O log usa permissões `FILE_APPEND | LOCK_EX`, garantindo escrita segura sob concorrência.

---

## 8. Proteção de dados

O sistema adota uma estratégia de proteção de dados em três camadas, correspondendo às três fases do fluxo de dados.

### 8.1 Camada de transporte — EncryptionService

O `EncryptionService` usa o pacote `erikwang2013/encryption` para criptografar/descriptografar campos sensíveis nas solicitações/respostas da API.

**Detalhes técnicos**:
- Algoritmo: `aes-256-cbc-hmac` (com assinatura HMAC integrada contra adulteração)
- Chave: variável de ambiente `ENCRYPTION_KEY`, alinhada automaticamente a 32 bytes
- Uso: transporte de campos como telefone e número de identidade entre cliente e API

**Métodos utilitários de mascaramento**:
- `maskPhone('13812341234')` → `138****1234`
- `maskEmail('abc@example.com')` → `a***@example.com` (nome de usuário com mais de 2 caracteres) ou `a**@example.com`

### 8.2 Camada de armazenamento — Encryptable Cast

O modelo `AdminUser` usa o cast Eloquent `Erikwang2013\Encryptable\Encryptable`, nos campos correspondentes:

- `email` → cast para Encryptable, criptografia/descriptografia automática
- `phone` → cast para Encryptable, criptografia/descriptografia automática
- `id_card` → cast para Encryptable, criptografia/descriptografia automática

Ao gravar no banco, os dados são criptografados automaticamente em texto cifrado; ao ler, são descriptografados automaticamente em texto claro. O tipo da coluna no banco é `VARCHAR(500)`, e o texto cifrado é armazenado em base64.

**Sistema de chaves**: usa `ENCRYPTABLE_KEY` independente da criptografia da camada de transporte (`ENCRYPTION_KEY`); o vazamento de uma chave não invalida a outra camada.

Rotação de chaves: a variável de ambiente `ENCRYPTION_PREVIOUS_KEYS` suporta uma lista de chaves históricas (separadas por vírgula); ao ler dados antigos, tenta descriptografar com as chaves históricas; ao gravar, re-criptografa com a chave atual.

### 8.3 Camada de apresentação — Ofuscação de IDs e mascaramento

**Ofuscação de IDs com Hashids**: `HashidsService` usa o pacote `erikwang2013/hashids`.

- IDs BIGINT do banco retornados pela API externa são codificados em strings hash (ex.: `xK3mN9qR2pL7wV8b`)
- O cliente envia a string hash nas solicitações; o backend decodifica automaticamente para o ID original
- O salt `HASHIDS_SALT` é injetado via variável de ambiente; salts diferentes produzem resultados de codificação/decodificação completamente diferentes
- Comprimento mínimo do hash: 16 caracteres, usando conjunto alfanumérico de 62 caracteres
- BaseController fornece os métodos de conveniência `encodeId()`, `decodeId()`, `encodeIds()`

**Mascaramento em exportações**: na exportação Excel/PDF (ExportController), campos sensíveis são mascarados uniformemente:
- Telefone: `138****1234`
- E-mail: `a***@example.com`
- Número de identidade: coberto completamente como `********`

---

## 9. Gerenciamento de chaves

Todas as chaves são injetadas via variáveis de ambiente `.env`; os arquivos de configuração leem com `getenv()` e possuem valores padrão de fallback embutidos (seguros apenas em desenvolvimento).

| Variável de ambiente | Uso | Pacote | Requisito de produção |
|----------|------|-----|---------|
| JWT_SECRET | Chave de assinatura JWT | erikwang2013/jwt-webman | String aleatória com 64+ caracteres |
| JWT_ALGORITHM | Algoritmo de assinatura JWT | mesmo | Manter HS256 |
| HASHIDS_SALT | Salt de codificação de IDs | erikwang2013/hashids | String aleatória |
| SNOWFLAKE_DATACENTER_ID | ID do data center (0-31) | erikwang2013/snowflake-php | Manter padrão em data center único |
| ENCRYPTION_KEY | Chave de criptografia da camada de transporte da API | erikwang2013/encryption | String aleatória de 32 bytes |
| ENCRYPTABLE_KEY | Chave de criptografia da camada de armazenamento do banco | erikwang2013/encryptable | String aleatória de 32 bytes, diferente da chave de transporte |

**Requisitos de segurança**:
- O arquivo `.env` está no `.gitignore`; é estritamente proibido commitá-lo no repositório
- `.env.example` é um modelo público, sem chaves reais
- Em produção, **obrigatório** substituir todas as chaves padrão por strings aleatórias
- Recomenda-se gerar chaves com `openssl rand -base64 32`

### Isolamento do armazenamento de chaves

| Camada | Chave de configuração | Variável de ambiente da chave |
|----|--------|-------------|
| Criptografia de transporte | `config/encryption.php` → `key` | `ENCRYPTION_KEY` |
| Criptografia de armazenamento | `config/encryptable.php` → `key` | `ENCRYPTABLE_KEY` |
| Ofuscação de IDs | `config/hashids.php` → `connections.main.salt` | `HASHIDS_SALT` |
| Assinatura JWT | `config/plugin/erikwang2013/jwt/jwt` | `JWT_SECRET` |

---

## 10. security.txt (RFC 9116)

O sistema fornece um endpoint de informações de contato de segurança em `/.well-known/security.txt`, em conformidade com o padrão RFC 9116, permitindo que pesquisadores de segurança encontrem rapidamente o canal de reporte ao descobrir vulnerabilidades.

**Forma de acesso**:

```
GET /.well-known/security.txt
```

**Conteúdo da resposta**:

```text
Contact: mailto:security@erik.xyz
Expires: 2027-05-20T00:00:00.000Z
Preferred-Languages: zh, en
Canonical: https://erik.xyz/.well-known/security.txt
Policy: https://erik.xyz/security-policy
```

**Descrição dos campos**:

| Campo | Descrição |
|------|------|
| Contact | Contato para reporte de vulnerabilidades de segurança |
| Expires | Prazo de validade do arquivo; requer atualização periódica |
| Preferred-Languages | Idiomas preferidos de comunicação |
| Canonical | URL canônica deste arquivo |
| Policy | Link para a política de segurança / divulgação de vulnerabilidades |

Este endpoint não está sujeito a middlewares de limitação ou autenticação; qualquer pessoa pode acessá-lo diretamente.

---

## 11. Configuração de segurança Nginx

O projeto fornece `docs/nginx-security.conf` como configuração de referência de endurecimento para o proxy reverso Nginx em produção.

**Medidas de segurança incluídas**:

| Item de configuração | Função |
|--------|------|
| `server_tokens off` | Oculta o número de versão do Nginx |
| `client_max_body_size 10m` | Limita o tamanho do corpo da solicitação, em conjunto com o SecurityMiddleware (erikwang2013/security-php) |
| `limit_req_zone` | Limitação de frequência de solicitações no nível do Nginx |
| `limit_conn_zone` | Limitação de conexões concorrentes |
| `add_header` cabeçalhos de segurança | Acrescenta X-XSS-Protection e outros cabeçalhos de segurança no nível do Nginx |
| `if ($request_method)` | Rejeita métodos HTTP não padronizados no nível do Nginx |
| Configuração SSL/TLS | TLS 1.2/1.3 moderno, desativa suites de cifras fracas |
| Ocultar cabeçalhos do backend | `proxy_hide_header` remove cabeçalhos sensíveis como a versão do webman |

**Como usar**: mescle as configurações de `docs/nginx-security.conf` no bloco server do seu Nginx, ajustando domínio real e caminhos de certificados.

---

## 12. Modelo de ameaças

### 12.1 Ameaças protegidas

| Tipo de ameaça | Vetor de ataque | Camadas de defesa |
|----------|---------|---------|
| Abuso de método HTTP | Ataques TRACE/TRACK XST, tunelamento CONNECT, sondagem de métodos WebDAV | Detector http_method do SecurityMiddleware, lista branca 405 |
| Força bruta direcionada | Tentativas repetidas de senha contra usuário específico | Bloqueio de conta (5 falhas bloqueiam 15 min) + RateLimit (login 10/min) + Captcha |
| Força bruta | Tentativas distribuídas de usuário/senha a partir de múltiplos IPs | RateLimit (login 10/min) + Captcha |
| XSS (script entre sites) | `<script>`, onerror, javascript: | SecurityMiddleware (erikwang2013/security-php) (5 modos) + cabeçalho de resposta X-XSS-Protection + CSP |
| Injeção SQL | UNION SELECT, OR 1=1, bypass por comentários | SecurityMiddleware (erikwang2013/security-php) (6 modos) + consultas parametrizadas Eloquent ORM |
| CSRF (falsificação de solicitação entre sites) | Sites maliciosos enviam solicitações em nome do usuário | Validação Origin/Referer do SecurityMiddleware (erikwang2013/security-php) |
| Path traversal | `../../etc/passwd` | Modo path traversal do SecurityMiddleware (erikwang2013/security-php) + lista branca de extensões do UploadController |
| Injeção de comandos | `;ls`, `` `whoami` ``, `$(cat ...)` | SecurityMiddleware (erikwang2013/security-php) (4 modos) |
| Sequestro de sessão | Roubo de token JWT | JWT de curta validade (2h) + logout por lista negra + confirmação secundária de senha em operações sensíveis |
| Enumeração de IDs | Adivinhar IDs numéricos para estimar volume de dados | Ofuscação Hashids em strings aleatórias |
| Vazamento de dados | Roubo do banco / intermediário / vazamento de logs | Criptografia/mascaramento em três camadas + filtro de campos sensíveis do OperationLog |
| Ataque DoS | Corpo de solicitação gigante / alta frequência | Limite de 10MB no corpo + RateLimit 60/min + lista negra de IPs |
| Escalação de privilégios | Usuários de baixo privilégio acessando interfaces administrativas | Autorização RBAC com granularidade method.path |
| Ataque de upload de arquivos | Extensão dupla shell.php.png | Detecção de arquivos maliciosos do SecurityMiddleware (erikwang2013/security-php) |

### 12.2 Limitações conhecidas

| Limitação | Escopo de impacto | Medidas de mitigação |
|------|---------|---------|
| A proteção CSRF funciona apenas para navegadores | Clientes não navegador (curl, Postman, apps móveis) podem ignorar a verificação Origin/Referer | Clientes não navegador naturalmente não sofrem CSRF; autenticação JWT substitui cookies |
| Com Redis indisponível, limitação e lista negra degradam para fail-open | Atacantes podem contornar limitação e bloqueio de alta frequência | Monitorar disponibilidade do Redis com alertas; lista negra de IPs suporta três backends (file/redis/cache) com degradação |
| Sem motor WAF dedicado | Detecção baseada em regex, não um motor de regras WAF especializado | Em produção, recomenda-se Nginx ModSecurity ou Cloudflare WAF na frente |
| JWT sem estado não pode ser invalidado proativamente | Tokens não podem ser revogados pelo servidor antes da expiração (exceto lista negra) | Lista negra + TTL curto de 2h reduzem a janela de risco |
| Endpoints administrativos sem limitação especial | Interfaces administrativas compartilham o padrão 60/min com as demais | Frequência de operações administrativas é naturalmente baixa; distinção desnecessária por ora |
| Limite de backtracking PCRE | O pacote embute limite de 1.000.000 de backtracking + recuperação via finally; entradas extremamente complexas ainda apresentam risco de performance | Limite de tamanho do corpo (10MB) como salvaguarda |
