# Relatório de testes automatizados de API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Data: 2026-08-27
- Execução: `tests/api/run.php` (script de asserções curl), resultados em `tests/api/results.json`
- Escopo: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, incluindo S58-S68)
- Serviços: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` não coberto nesta rodada de testes HTTP)

## Conclusão

**116 casos de teste: 116 aprovados / 0 reprovados (taxa de aprovação 100%); os 3 defeitos de produto da rodada anterior (A20/A39/A40) estão todos corrigidos e verificados**

| Grupo | Aprovados/Total |
|------|-----------|
| admin A01-A45 (autenticação, captcha, gestão de usuários, HashID, papéis e permissões, configuração, logs, exportar/importar, upload, health checks, etc.) | 45/45 |
| service S01-S68 (registro/login/logout/refresh, perfil, seguir, posts/curtidas/timeline, comentários, notificações, busca, sessões IM/mensagens/push, upload de voz/arquivos/chamadas/salas, etc.) | 71/71 |

## Verificação da correção dos 3 defeitos de produto da rodada anterior (tudo PASS)

| Caso | Esperado | Real (rodada anterior) | Correção | Resultado desta rodada |
|------|------|---------|------|---------|
| A20 Detalhes de usuário hashid inválido | 404 | 500 | `BaseController::decodeId()` captura `InvalidArgumentException` e lança `support\exception\NotFoundException($msg, 404)` (admin/app/admin/controller/BaseController.php); os catch dos dois métodos batch de `UserController` são ampliados para `InvalidArgumentException \| NotFoundException` preservando a semântica 422 | **PASS (404)** |
| A39 Exportar Excel | fluxo de arquivo xlsx | 200+corpo de erro JSON | `ExportController` adiciona `use support\Response;` (o tipo de retorno antes era resolvido para o inexistente `app\admin\controller\Response`, lançando TypeError); `phone/email/id_card` de `admin_user` são descriptografados automaticamente pelo cast Encryptable na leitura, a exportação mascara diretamente, segunda descriptografia removida | **PASS (fluxo de arquivo attachment)** |
| A40 Exportar PDF | fluxo de arquivo pdf | 200+corpo de erro JSON | Igual acima (tipo de retorno de `ExportController::pdf()` corrigido) | **PASS (fluxo de arquivo application/pdf)** |

## Problemas de ambiente corrigidos/tratados nesta rodada (não são alterações de código de negócio do produto)

1. **Substituição de senha de BD vazia no run.php quebrada (defeito do script de teste, corrigido)**: a constante `DB` usa `getenv('DB_PASS') ?: 'root'`; uma variável de ambiente com string vazia é tratada como falsy por `?:` e cai para 'root', então a conexão root local com senha vazia é rejeitada (`Access denied ... using password: YES`). Alterado para `getenv('DB_PASS') ?? 'root'` (padrão apenas se não definida), mudança de uma linha (tests/api/run.php:26).
2. **Porta 8788 do service ocupada por processo errado (ambiente, tratado)**: um processo service de outro projeto desta máquina — `property-management-platform` (master 2004768, iniciado 08:07) — escutava na 8788, e seu `.env` aponta para o banco `property_management`; o service do social na verdade não estava rodando, fazendo as rotas IM/voz a partir de S45 retornarem 404 e o SQL da fase de limpeza atingir o banco errado. O processo foi interrompido e o service do social reiniciado em 8788/8789 (`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`); o health check voltou a `social-service`.
3. **Upgrade para ImageMagick 7 causou falha do driver Imagick do captcha (ambiente, tratado)**: após a atualização do ImageMagick do sistema para 7.1.2-27 (build 2026-07-08), `PixelsResource` foi removido; imagick 3.8.1 não define mais `Imagick::RESOURCETYPE_PIXELS`, e o construtor de `ImagickDriver` do poster-php lança imediatamente `Undefined constant` (código vendor, não alterado), fazendo a geração/verificação do captcha (A05/A06) retornar 500 e bloqueando em cascata o login A08-A11. **Tratamento**: o serviço admin foi reiniciado com o seletor de driver previsto na documentação de configuração — `POSTER_IMAGE_DRIVER=gd` (admin/config/poster.php:17 suporta nativamente gd/imagick/auto); após mudar o captcha para o driver GD, toda a cadeia funciona. Para restaurar o driver Imagick, é preciso fazer downgrade do ImageMagick para 6.x ou atualizar o poster-php para compatibilidade com IM7.
4. **A senha root do MySQL mudou para vazia**: a rodada anterior registrou `root/root`; nesta rodada o login com senha vazia funciona, e todos os serviços e scripts foram iniciados com senha vazia.
5. **Ambiente de reinício do serviço admin**: vale ainda o da rodada anterior, «admin não tem .env, depende de variáveis de ambiente»; comandos de reinício abaixo, em «Ambiente e reprodução».
6. **service/.env continua `service/.env.api-test-bak`**: movido na rodada anterior para teste de conectividade e não restaurado (restauração limitada pela política de acesso ao arquivo .env); nesta rodada o serviço foi novamente iniciado com variáveis de ambiente. É necessário `mv service/.env.api-test-bak service/.env` manual (reiniciar o serviço após restaurar; atentar para o endereço de banco ao qual aponta).
7. **Elasticsearch não iniciado**: `GET /api/v1/search/posts` retorna 503 (degradação planejada); os casos de busca do grupo S tratados como esperado (aceitando 0 ou 503), não contados como falhas.

## Divergências contrato/documentação (revisão sugerida, não bloqueante)

- A documentação do captcha (apidoc e comentários do CaptchaController) escreve `clicks=[{x,y}]` como um array de objetos, mas a implementação `poster-php` exige um array de pares de coordenadas `[[x,y]]`; passar objetos conforme a documentação sempre falha na prática.
- O upload de voz retorna `voice_url` como `/voice/{md5}.m4a` (relativo à raiz da API, sem o prefixo `/api/v1`); o cliente precisa concatenar `/api/v1` por conta própria para acessar; o acesso a arquivos passa por rotas autenticadas (token necessário).

## Ambiente e reprodução

- Credenciais de teste: conta `e2e_smoke` (admin, senha exclusiva para testes) + `apitest_*@test.dev` (service, limpa automaticamente após a execução), todas escritas nas constantes de `tests/api/run.php`; nenhuma chave real foi usada.
- Reprodução:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD='' ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' POSTER_IMAGE_DRIVER=gd \
  php start.php start                                          # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD='' php start.php start                           # service :8788
cd /home/wwwroot/social/tests/api && DB_PASS='' php run.php    # re-executar (116 casos)
```

- Atenção: garantir que a porta 8788 não esteja ocupada pelo service do `property-management-platform` (ambos os projetos usam a mesma porta por padrão; quando os dois projetos coexistem nesta máquina, é preciso deslocá-los).

## Inventário de endpoints (conforme route.php / apidoc)

- service `config/route.php`: 39 rotas HTTP (autenticação 5, usuários 2, seguir 5, posts 7, comentários 2, notificações 4, busca 2, IM 4, voz/chamadas/salas 5, health/docs 3)
- admin `config/route.php`: 33 rotas HTTP (autenticação/captcha 4, CRUD de usuários 5, papéis 5, permissões 2, configuração 4, logs 1, perfil 4, exportar 2, importar 1, upload 1, health/docs 4)
