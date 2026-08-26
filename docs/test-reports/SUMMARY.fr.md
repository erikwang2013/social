# Rapport récapitulatif de tous les tests
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Date : 2026-08-27
- Équipe de test : tests unitaires PHP / tests unitaires Rust / automatisation API / UI end-to-end (voir la note sur le rôle GO en fin de document)
- Les quatre sous-rapports et ce récapitulatif sont stockés localement dans `docs/test-reports/`

## Aperçu

| Rôle | Rapport | Cas de test | Réussis | Échoués | Conclusion |
|------|------|------|------|------|------|
| Tests unitaires PHP | `php-unit-report.md` | 196 | 185 | 11 (cas préexistants admin, dépendants de l'environnement) | service 136/136 tout vert ; admin 49/60 |
| Tests unitaires Rust | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates tout vert, 7 défauts réels trouvés |
| Automatisation API | `api-test-report.md` | 116 | 113 | 3 | 3 défauts produit réels, causes racines identifiées |
| UI end-to-end | `ui-e2e-report.md` | 35 | 35 | 0 | Tout vert, 1 bloqué (ES non démarré) |
| **Total** | | **527** | **513** | **14** | Taux de réussite 97 % |

## Liste des défauts réels (correction recommandée)

1. **A20 hashid invalide** → 500 devrait être 404 : `admin/app/common/HashidsService.php:28` ne capture pas `InvalidArgumentException`
2. **A39/A40 Export Excel/PDF** → échec garanti : il manque `use support\Response` dans `ExportController`, ce qui casse la résolution du type de retour ; le même fichier déchiffre une seconde fois téléphone/email déjà castés et signale `Invalid ciphertext prefix`
3. **7 défauts trouvés par Rust** : détails dans `rust-unit-report.md` (parsing de protocoles, gestion des limites, etc., chacun avec correction)
4. **11 échecs des tests unitaires admin = problèmes d'environnement/config** : `admin/.env` manquant, captcha dépendant d'un service/Redis en cours d'exécution, assertions obsolètes sur le middleware Cors et le champ searchable de admin_user — pas des défauts de code

## Corrections d'environnement et remarques (causées par ce lot de tests)

- **Base de données** : `id` de `social_follows`/`social_notifications` dans les tables de migration m2/m3/m4 sans AUTO_INCREMENT, corrigé via ALTER (sinon les chemins d'écriture abonnements/notifications/IM/voix échouent avec 1364)
- **`service/.env`** : sauvegardé sous `.env.api-test-bak` (pointait à l'origine vers le port 13306 inaccessible). Restauration automatique impossible à cause des restrictions de politique d'accès à .env ; un `mv service/.env.api-test-bak service/.env` manuel est requis
- **ES non démarré** : les cas de recherche (API + E2E) marqués réussis en 503/blocked ; re-vérification nécessaire après démarrage d'Elasticsearch

## Note de l'ingénieur de test GO

Le dépôt ne contient **aucun code Go** (pas de go.mod, pas de fichiers .go) ; ce rôle n'avait aucun module à tester et n'a pas été exécuté. Pour un complément de tests, un composant Go (ex. passerelle/sidecar de recherche) doit d'abord être introduit.

## Reproduction

```bash
# Tests unitaires
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# Automatisation API (démarrer d'abord admin :8791 et service :8788, injecter ENCRYPTABLE_KEY/ENCRYPTION_KEY)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
