# Rapport de tests unitaires PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Date : 2026-08-27
- Exécution : `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Périmètre : admin/ (panneau d'administration webman) + service/ (service principal webman)

## Aperçu général

| Projet | Cas de test | Assertions | Résultat |
|------|------|------|------|
| service | 136 | 348 | ✅ Tout réussi (OK) |
| admin | 60 | 136 | ⚠️ 49 réussis / 4 erreurs / 7 échoués |

## service (tout vert)

- Nouveaux fichiers de test (ce lot) : AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest, etc. ; fusionnés avec les 24 fichiers existants, 136 cas au total, tous réussis
- Modules couverts : authentification/middleware/JWT, utilisateurs, publications, commentaires, abonnements, notifications, synchronisation de recherche, IM, salles, appels (CallCenter/CallState), voix, relations de modèles, traitement d'actions (WS)

### Correction : blocage aléatoire de la suite de tests (important)

- Symptôme : lors d'un exécution complète, le processus se fige aléatoirement ; l'exécution d'un seul fichier/d'un sous-ensemble réussit
- Cause racine : `new Worker()` dans `ActionHandlerTest::setUp` enregistre l'instance dans le **registre statique** `Worker::$workers` ; ensuite tout `CallCenter::start` voit « un Worker existe » et appelle `Timer::add` → `pcntl_alarm(1)` installe un minuteur SIGALRM, le processus se bloque à la sortie
- Correction : setUp prend un instantané du registre, tearDown le restaure (`ReflectionProperty` réécrit `workers`/`pidMap`)
- Emplacement : `service/tests/ActionHandlerTest.php`

## admin (49/60 ; les échecs sont tous des tests préexistants et relèvent de l'environnement/configuration)

| Cas de test | Raison de l'échec | Catégorie |
|------|----------|------|
| EnvConfigTest (4 échecs + 1 erreur) | `admin/.env` n'existe pas ; les assertions getenv/dotenv échouent | Environnement de test sans .env |
| CaptchaTest (3 erreurs + 1 échec + 1 risky) | Le captcha dépend d'un service/Redis en cours d'exécution ; l'environnement de test unitaire renvoie null | Dépendance d'environnement |
| BackendEnhancementTest (2 échecs) | Affirme l'existence de `app/middleware/Cors` et le champ searchable de admin_user — la configuration actuelle ne correspond pas aux assertions | Assertions de configuration obsolètes |

Remarque : admin/tests sont tous des fichiers historiques préexistants ; aucun nouveau test unitaire admin n'a été ajouté dans ce lot (l'effort portait sur service).

## Non couvert / à compléter

- Les modules admin (model/middleware/view) manquent de tests unitaires
- Les chemins de service dépendant de services externes (ES/gRPC) n'ont reçu qu'une validation unitaire par stub ; une couverture au niveau intégration est recommandée via les tests API
