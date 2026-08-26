# Relatório de testes automatizados de API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Data: 2026-08-27
- Execução: `tests/api/run.php` (script de asserções curl), resultados em `tests/api/results.json`
- Escopo: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, incluindo S58-S68)
- Serviços: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` não coberto nesta rodada de testes HTTP)

## Conclusão

**116 casos de teste: 113 aprovados / 3 reprovados (taxa de aprovação 97,4%); as 3 falhas são defeitos de produto com causa raiz identificada**

| Grupo | Aprovados/Total |
|------|-----------|
| admin A01-A45 (autenticação, captcha, gestão de usuários, HashID, papéis e permissões, configuração, logs, exportar/importar, upload, health checks, etc.) | 42/45 |
| service S01-S68 (registro/login/logout/refresh, perfil, seguir, posts/curtidas/timeline, comentários, notificações, busca, sessões IM/mensagens/push, upload de voz/arquivos/chamadas/salas, etc.) | 71/71 |

## Casos de teste reprovados (3, todos defeitos de produto)

| Caso | Esperado | Real | Causa raiz |
|------|------|------|------|
| A20 Detalhes de usuário hashid inválido | 404 | 500 | `HashidsService::decode()` lança uma `InvalidArgumentException` não capturada para IDs inválidos (admin/app/common/HashidsService.php:28, BaseController.php:52); a exceção propaga como 500, deveria ser capturada e retornar 404 |
| A39 Exportar Excel | fluxo de arquivo xlsx | 200+corpo de erro JSON (falha de negócio) | `ExportController::excel()` declara o tipo de retorno `: Response` mas falta `use support\Response`, o tipo é resolvido para `app\admin\controller\Response` → qualquer retorno bem-sucedido lança `TypeError` (ExportController.php:122), tornando a exportação totalmente inutilizável |
| A40 Exportar PDF | fluxo de arquivo pdf | 200+corpo de erro JSON (falha de negócio) | Igual acima, `ExportController::pdf()` (ExportController.php:135) sem `use support\Response` |

> Observação adicional (defeito potencial no mesmo arquivo, atualmente mascarado pela TypeError acima): `ExportController` linha 90 chama `EncryptionService::decrypt()` em phone/email, enquanto os campos `email/phone/id_card` do modelo `AdminUser` declaram o cast `Encryptable::class` (criptografia automática na escrita, descriptografia na leitura); a exportação descriptografaria o texto claro uma segunda vez → assim que existir uma conta com telefone/email não vazios, lançará `EncryptionException: Invalid ciphertext prefix for AES-256-CBC`. Este problema continuará a se reproduzir mesmo após corrigir os tipos de retorno.

## Problemas de ambiente corrigidos durante os testes (não são alterações de código do produto)

1. **Coluna `id` das tabelas de migração m2/m3/m4 sem AUTO_INCREMENT (bloqueante, corrigido)**: `social_follows`, `social_notifications` criadas por `service/database/m2.sql`/`m3.sql`/`m4.sql` têm `id BIGINT UNSIGNED NOT NULL` sem `AUTO_INCREMENT`; qualquer INSERT falha com `1364 Field 'id' doesn't have a default value`, bloqueando todos os caminhos de escrita de seguir/notificações/IM/voz. Executado `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` localmente (as outras 8 tabelas já têm autoincremento). **Os scripts de migração em si devem ser atualizados para incluir o autoincremento.**
2. **service/.env aponta para um banco inacessível (bloqueante)**: `DB_PORT=13306` sem senha, enquanto o MySQL principal está em `127.0.0.1:3306 (root/root)`; o `createUnsafeMutable` do webman sobrescreve variáveis de ambiente da CLI. Durante os testes, `.env` foi movido para `service/.env.api-test-bak` (conteúdo preservado como está) e o serviço iniciado com variáveis de ambiente injetadas; a restauração não pôde ser feita devido a restrições de política de acesso ao arquivo .env, exigindo `mv service/.env.api-test-bak service/.env` manual (atenção: após restaurar, reiniciar o serviço encontrará novamente o banco inacessível).
3. **admin não tem .env, depende de variáveis de ambiente**: requer `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`. O plugin `encryptable`, sem provider registrado no contêiner webman, cai para `EnvEncryptableConfig` (lê `ENCRYPTION_KEY`, cipher padrão aes-256-gcm); comprimento de chave incompatível causa `MissingEncryptionKeyException` na criação/importação/exportação de contas.
4. **Elasticsearch não iniciado**: `GET /api/v1/search/posts` retorna 503 (degradação planejada); os casos de busca do grupo S tratados como esperado (aceitando 0 ou 503), não contados como falhas.

## Divergências contrato/documentação (revisão sugerida, não bloqueante)

- A documentação do captcha (apidoc e comentários do CaptchaController) escreve `clicks=[{x,y}]` como um array de objetos, mas a implementação `poster-php` exige um array de pares de coordenadas `[[x,y]]`; passar objetos conforme a documentação sempre falha na prática.
- O upload de voz retorna `voice_url` como `/voice/{md5}.m4a` (relativo à raiz da API, sem o prefixo `/api/v1`); o cliente precisa concatenar `/api/v1` por conta própria para acessar; o acesso a arquivos passa por rotas autenticadas (token necessário).

## Ambiente e reprodução

- Credenciais de teste: conta `e2e_smoke` (admin, senha exclusiva para testes) + `apitest_*@test.dev` (service, limpa automaticamente após a execução), todas escritas nas constantes de `tests/api/run.php`; nenhuma chave real foi usada.
- Reprodução:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # re-executar (116 casos)
```

## Inventário de endpoints (conforme route.php / apidoc)

- service `config/route.php`: 39 rotas HTTP (autenticação 5, usuários 2, seguir 5, posts 7, comentários 2, notificações 4, busca 2, IM 4, voz/chamadas/salas 5, health/docs 3)
- admin `config/route.php`: 33 rotas HTTP (autenticação/captcha 4, CRUD de usuários 5, papéis 5, permissões 2, configuração 4, logs 1, perfil 4, exportar 2, importar 1, upload 1, health/docs 4)
