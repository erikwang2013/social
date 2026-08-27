# Rapport de tests unitaires PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Date : 2026-08-27
- Exécution : `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Périmètre : admin/ (panneau d'administration webman) + service/ (service principal webman)

## Aperçu général

| Projet | Cas de test | Assertions | Résultat |
|------|------|------|------|
| service | 159 | 408 | ✅ Tout réussi (OK) |
| admin | 67 | 180 | ✅ Tout réussi (OK) |

## Notes d'environnement

- MySQL 127.0.0.1:3306 (root, mot de passe vide) ; bases `social` (social_*) et `open_admin` (erik_*) créées et peuplées (rôle super_admin, 39 permissions)
- Redis 127.0.0.1:6379 en cours d'exécution (stockage captcha `poster:captcha:*`) ; Elasticsearch non démarré (le health check dégrade en unavailable, non compté comme échec)
- service sur 8788, admin sur 8791
- Ni service ni admin n'a de `.env` (le dépôt a retiré les env committés par erreur, commit e5379fc) ; les applis s'appuient sur les fallbacks `getenv('X') ?: valeur par défaut` dans `config/*.php`
- **L'extension Imagick est chargée mais la constante `RESOURCETYPE_PIXELS` manque** (ce build n'a que le nouvel ensemble de constantes RESOURCETYPE_*) ; le constructeur d'ImagickDriver de poster-php référence cette constante et plante

## service (159/159 tout vert)

- Conforme à la baseline du lot précédent ; couvert : authentification/middleware/JWT, utilisateurs, publications, commentaires, abonnements, notifications, synchronisation de recherche, IM, salles, appels (CallCenter/CallState), voix, relations de modèles, traitement d'actions (WS)
- M5 a ajouté le module live (LiveCenter : créer/détail/danmaku/liaison micro/fermer), 23 cas, aucune régression

## admin (lot précédent 49/60 → ce lot 67/67 tout vert)

### Correction : vrai défaut de code (1 emplacement)

| Emplacement | Cause racine | Correction |
|------|------|------|
| `config/poster.php` | `image.driver` par défaut `auto` ; DriverFactory choisit ImagickDriver quand l'extension Imagick est détectée, mais l'Imagick de cette machine manque de la constante `RESOURCETYPE_PIXELS` → génération de captcha/affiche en 500 direct (le service en ligne est également affecté) | Garde sur la constante ajoutée à la détection du driver : `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')` ; repli automatique sur GD si la constante manque |

### Correction : assertions obsolètes (mises à jour après vérification du code actuel)

| Fichier de test | Cas | Cause racine | Correction |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 échecs + 1 erreur) | Affirme l'existence de `.env`/`.env.example` et des valeurs getenv ; mais le dépôt a retiré les fichiers env et ils ne sont pas reconstruisibles | Réécrit comme contrat « fonctionner sans .env » : chaque clé `getenv()` doit avoir un défaut `?:`, la config par défaut pointe vers les services locaux (127.0.0.1:3306/open_admin), types de la config critique corrects |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser n'utilise plus le trait Searchable (désormais `Erikwang2013\Encryptable\Encryptable` pour le chiffrement/déchiffrement transparent des champs ; `toSearchableArray()` conservé) | Assertion changée pour le trait Encryptable ; l'assertion toSearchableArray passait déjà, conservée |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` utilise désormais le format de clé de groupe global `'@'` ; le tableau de premier niveau ne contient plus directement les classes middleware | Assertion changée pour vérifier que `$middlewares['@']` contient Cors et RateLimit |
| CaptchaTest | les 7 cas (à l'origine 6 erreurs + 1 échec) | Double obsolescence : (a) constante Imagick manquante (déjà corrigée par poster.php) ; (b) assertions basées sur l'ancien contrat poster-php — `extra.targets` (avec x/y) devenu `extra.texts` (text+order uniquement), les coordonnées ne vivent que dans la couche de stockage ; format de clic changé de `['x'=>, 'y'=>]` à des paires numériques `[x, y]` | Réécrit selon le contrat actuel : structure/nombres de difficultés (2/3/4)/validation des champs ; les clics corrects lisent les coordonnées depuis Redis (`poster:captcha:{key}` → `data.targets`) et valident ; clic erroné échoue ; après max_attempts (3) la clé est consommée/supprimée ; unicité de la clé |

### Nouveaux tests (1 fichier, 12 cas)

`tests/AdminControllerTest.php` (avec en-tête de copyright), couvrant :

- **BaseController::decodeId** (le comportement 404 tout juste corrigé) : les allers-retours encode/decode sont cohérents ; un hashid invalide lève `support\exception\NotFoundException` avec code=404 ; encodeIds ne réécrit que les champs ID
- **RoleController** : la mise à jour du rôle super_admin renvoie 403 (données DB réelles)
- **PermissionController::buildTree** : imbrication de l'arbre de permissions (2 niveaux) + tous les ids de nœuds hashidisés
- **ConfigController** : group/key/value manquants → validation 422 ; hashid invalide → 404
- **ExportController** : l'export `admin_user` liste les champs sensibles phone/email/id_card (autres tables vides) ; le HTML PDF échappe titre/valeurs de cellules avec htmlspecialchars (protection XSS) et inclut la mention de copyright

### Remarques connues

- Le Request webman construit dans les tests est passé comme message HTTP brut (buffer) — le paramètre du constructeur du Request workerman est un buffer ; passer seulement method/uri ne permet pas de parser le corps POST ; voir les commentaires d'AdminControllerTest
- Le cas de clic correct du captcha lit les cibles stockées dans Redis ; si Redis est indisponible, le cas est markTestSkipped et n'affecte pas le résultat de la suite

## Non couvert / à compléter

- Le chiffrement/déchiffrement Encryptable des modèles admin, le middleware OperationLog/AdminPermission et les chemins de cache RBAC manquent toujours de tests unitaires ; couverture recommandée via les tests API ou un lot ultérieur
- Les chemins de service dépendant de services externes (ES/gRPC) restent en validation unitaire par stub uniquement ; le niveau intégration est couvert par les tests API
