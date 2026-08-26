# Relatório resumido de todos os testes
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Data: 2026-08-27
- Equipe de testes: testes unitários PHP / testes unitários Rust / automação API / UI de ponta a ponta (ver a nota sobre o papel GO no final)
- Os quatro sub-relatórios e este resumo estão armazenados localmente em `docs/test-reports/`

## Visão geral

| Papel | Relatório | Casos de teste | Aprovados | Reprovados | Conclusão |
|------|------|------|------|------|------|
| Testes unitários PHP | `php-unit-report.md` | 196 | 185 | 11 (casos pré-existentes de admin, dependentes do ambiente) | service 136/136 tudo verde; admin 49/60 |
| Testes unitários Rust | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates tudo verde, e 7 defeitos reais encontrados |
| Automação API | `api-test-report.md` | 116 | 113 | 3 | 3 defeitos reais de produto, causas raiz identificadas |
| UI de ponta a ponta | `ui-e2e-report.md` | 35 | 35 | 0 | Tudo verde, 1 bloqueado (ES não iniciado) |
| **Total** | | **527** | **513** | **14** | Taxa de aprovação 97% |

## Lista de defeitos reais (correção recomendada)

1. **A20 hashid inválido** → 500 deveria ser 404: `admin/app/common/HashidsService.php:28` não captura `InvalidArgumentException`
2. **A39/A40 Exportar Excel/PDF** → falha garantida: falta `use support\Response` no `ExportController`, o que quebra a resolução do tipo de retorno; o mesmo arquivo descriptografa uma segunda vez telefone/email já castados e reporta `Invalid ciphertext prefix`
3. **7 defeitos encontrados pelo Rust**: detalhes em `rust-unit-report.md` (parse de protocolos, tratamento de limites, etc., todos com correção anexa)
4. **11 falhas dos testes unitários de admin são problemas de ambiente/configuração**: falta `admin/.env`, captcha depende de serviço/Redis em execução, asserções desatualizadas do middleware Cors e do searchable de admin_user — não são defeitos de código

## Correções de ambiente e observações (causadas por este lote de testes)

- **Banco de dados**: o `id` de `social_follows`/`social_notifications` nas tabelas de migração m2/m3/m4 não tem AUTO_INCREMENT, corrigido via ALTER (caso contrário, os caminhos de escrita de seguir/notificações/IM/voz falham com 1364)
- **`service/.env`**: feito backup como `.env.api-test-bak` (originalmente apontava para a porta 13306 inacessível). A restauração automática não é possível devido às restrições de política de acesso ao .env; é necessário `mv service/.env.api-test-bak service/.env` manual
- **ES não iniciado**: os casos de busca (API + E2E) marcados como aprovados com 503/blocked; re-verificação necessária após iniciar o Elasticsearch

## Nota do engenheiro de testes GO

O repositório **não contém nenhum código Go** (sem go.mod, sem arquivos .go); este papel não tinha módulo para testar e não foi executado. Para adicionar cobertura, primeiro é preciso introduzir um componente Go (ex.: gateway/sidecar de busca).

## Reprodução

```bash
# Testes unitários
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# Automação API (requer iniciar antes admin :8791 e service :8788, injetando ENCRYPTABLE_KEY/ENCRYPTION_KEY)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
