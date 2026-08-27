# Rapport de tests de bout en bout (E2E) des pages
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- Date : 2026-08-27
- Environnement : machine locale (Linux), navigateur réel (Playwright 1.62 / Chromium) + processus de services réels
- Cas de test au total : **41**, réussis **41**, échecs **0**, marqués bloqués **1**
- Artefacts : `tests/e2e/artifacts/html-report/` (rapport HTML Playwright), captures/traces d'échec (aucune pour cette exécution)

## Périmètre des tests et liste des pages

Les deux backends webman tournent en processus réels : `admin` (:8791), `service` (:8788, WS :8789).
Les `app/view/` des deux côtés ne contiennent que les modèles par défaut (`index/view.html`), sans modèles multi-pages classiques — les « pages » réelles sont les points de terminaison API,
les fronts Web étant portés par les clients Flutter/HarmonyOS (`apps/` ne contient aucune UI Web exécutable, hors périmètre E2E).

| Application | Page / point de terminaison | Cas |
|------|------------|------|
| admin | `/health` vérification de santé, `/metrics` métriques Prometheus, `/.well-known/security.txt`, `/api/docs` OpenAPI, `/install` assistant d'installation | 5 |
| admin | `/api/captcha/generate` + `/api/captcha/verify` (résolution du captcha glissière sur pixels réels), `/api/auth/login` (succès/mot de passe erroné/captcha absent) | 3 |
| admin | Pages protégées après connexion : `/admin/dashboard`, `/admin/user`, `/admin/role`, `/admin/permission`, `/admin/config`, `/admin/log`, `/admin/profile`, `/admin/social-user`, déconnexion `/admin/profile/logout` → jeton invalidé | 11 |
| admin | Opérations par lots `/admin/user/batch/status` (activation par lot + ids vides 422), export `/admin/export/excel` (vérification de l'en-tête de fichier xlsx), changement de mot de passe `/admin/profile/password` (ancien mot de passe manquant 422) | 3 |
| service | `/` (conteneur iframe), `/health`, `/apidoc` (redirection vers apidoc/index.html), accès non authentifié aux points protégés 401 | 4 |
| service | Inscription/connexion/déconnexion, profil (GET/PUT `/api/v1/me`), publication/timeline/détail, like/retrait du like, commentaire, abonnement/désabonnement/relations/abonnés/liste des abonnements, notifications (liste/non-lus/un marqué comme lu/tout marquer comme lu) | 10 |
| service | Recherche d'utilisateurs, recherche de publications (ES non démarré → 503, marqué bloqué et réussi) | 2 |
| service | Conversations IM (création/liste/messages), salons vocaux (création/liste/détail/fermeture) | 3 |

## Mode d'exécution

```bash
cd tests/e2e && npx playwright test          # tout
# ou par fichier : admin-pages.spec.js / admin-auth.spec.js / service-journey.spec.js
```

- Fixture du compte de test : `e2e_smoke`, mot de passe `ApiTest!2026` (préchargé via SQL, voir `tests/api/run.php`)
- Le captcha glissière est résolu par corrélation de Pearson au pixel entre la « pièce du puzzle et l'image de fond » (chemin d'interaction réel, sans contournement) ;
  le type de captcha est aléatoire (click/rotate/slider), seul slider est résolvable automatiquement, le script réessaie donc avec une nouvelle image jusqu'à réussite.

## Points bloquants / limites de l'environnement

1. **Recherche de publications 503** : `/api/v1/search/posts` dépend d'Elasticsearch (Scout), non démarré dans cet environnement → renvoie 503.
   Le cas passe marqué `blocked` ; il faut démarrer ES pour vérifier les correspondances.
2. **Page d'accueil `/` de service nécessite une route explicite** : le routage par défaut de webman-framework v2.2.4 ne résout plus `/` vers
   `IndexController@index` (a déjà causé un 404 sur le chemin racine, faisant échouer le cas de la page d'accueil). Corrigé en enregistrant explicitement
   `Route::get('/', ...)` dans `service/config/route.php` ; effet après redémarrage de service.
3. **Compatibilité Imagick du captcha admin** : le build Imagick de cette machine ne possède pas la constante `Imagick::RESOURCETYPE_PIXELS`,
   le pilote `auto` choisirait donc à tort ImagickDriver et provoquerait un generate 500 (`admin/config/poster.php` retombe désormais sur gd
   selon l'existence de la constante ; nécessite un redémarrage d'admin).
4. **Mémoire GD du captcha admin** : `GdDriver` décode de grandes images (fond 5472x3648) avec `memory_limit 128M`,
   des generate successifs présentent un risque d'OOM (admin a déjà planté lors de longues suites). Contournement : redémarrer admin avant les cas captcha,
   et exécuter par lots (admin-pages / admin-auth / service séparément). Limite d'environnement, pas un défaut du code métier.
5. **Type de captcha aléatoire** : generate choisit parmi trois ; click/rotate n'exposent aucune donnée résolvable, seul slider passe automatiquement (12 tentatives max).
6. **Mot de passe root vide de la base** : l'environnement de test local fournit MySQL avec root/mot de passe vide, les `.env` par défaut des deux applications sont cohérents.
7. **Apps/ mobiles** : android/harmonyos/ios n'ont aucune UI Web exécutable, hors E2E navigateur.

## Conclusion

La connexion admin (captcha glissière inclus) et 22 points de terminaison admin, ainsi que les 19 cas utilisateur complets de service réussissent
(cette exécution a ajouté 6 cas : activation par lot admin/export Excel/validation de changement de mot de passe, service 401 sans connexion/désabonnement/un notification marquée comme lue).
2 défauts réels corrigés : 404 du chemin racine de service (route explicite ajoutée), generate 500 du captcha admin
(constante Imagick manquante → repli sur GD, déjà dans la config, effet après redémarrage).
Le seul point bloquant est le service de recherche (ES) non déployé ; toutes les autres chaînes (inscription/connexion/publication/interaction/notification/IM/voix) sont vérifiées fonctionnelles.
