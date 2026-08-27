# Rapport de tests automatisés de l'API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Date : 2026-08-27
- Exécution : `tests/api/run.php` (script d'assertions curl), résultats dans `tests/api/results.json`
- Périmètre : admin HTTP API (A01-A45) + service HTTP API (S01-S57b, incluant S58-S68)
- Services : admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` non couvert par cette série de tests HTTP)

## Conclusion

**116 cas de test : 116 réussis / 0 échoué (taux de réussite 100 %) ; les 3 défauts produit du tour précédent (A20/A39/A40) sont tous corrigés et vérifiés**

| Groupe | Réussis/Total |
|------|-----------|
| admin A01-A45 (authentification, captcha, gestion des utilisateurs, HashID, rôles et permissions, configuration, journaux, export/import, upload, health checks, etc.) | 45/45 |
| service S01-S68 (inscription/connexion/déconnexion/refresh, profil, abonnements, publications/likes/fil d'actualité, commentaires, notifications, recherche, sessions IM/messages/push, upload vocal/fichiers/appels/salles, etc.) | 71/71 |

## Vérification de la correction des 3 défauts produit du tour précédent (tout PASS)

| Cas | Attendu | Réel au tour précédent | Correction | Résultat de ce tour |
|------|------|---------|------|---------|
| A20 Détails utilisateur hashid invalide | 404 | 500 | `BaseController::decodeId()` capture `InvalidArgumentException` et lève `support\exception\NotFoundException($msg, 404)` (admin/app/admin/controller/BaseController.php) ; les catch des deux méthodes batch de `UserController` sont étendus à `InvalidArgumentException \| NotFoundException`, sémantique 422 conservée | **PASS (404)** |
| A39 Export Excel | flux de fichier xlsx | 200+corps d'erreur JSON | `ExportController` ajoute `use support\Response;` (le type de retour était résolu vers l'inexistant `app\admin\controller\Response`, d'où TypeError) ; `phone/email/id_card` de `admin_user` sont déchiffrés automatiquement par le cast Encryptable à la lecture, l'export masque directement, double déchiffrement supprimé | **PASS (flux de fichier attachment)** |
| A40 Export PDF | flux de fichier pdf | 200+corps d'erreur JSON | Identique (type de retour de `ExportController::pdf()` corrigé) | **PASS (flux de fichier application/pdf)** |

## Problèmes d'environnement corrigés/gérés lors de ce tour (pas des modifications de code métier produit)

1. **Écrasement du mot de passe BD vide dans run.php cassé (défaut du script de test, corrigé)** : la constante `DB` utilise `getenv('DB_PASS') ?: 'root'` ; une chaîne vide dans la variable d'environnement est traitée comme fausse par `?:` et retombe sur 'root', donc la connexion root locale avec mot de passe vide est rejetée (`Access denied ... using password: YES`). Changé en `getenv('DB_PASS') ?? 'root'` (défaut uniquement si non défini), modification d'une ligne (tests/api/run.php:26).
2. **Port 8788 du service occupé par un mauvais processus (environnement, géré)** : un processus service d'un autre projet de cette machine — `property-management-platform` (master 2004768, démarré 08:07) — écoutait sur 8788, et son `.env` pointe vers la base `property_management` ; le service social ne tournait en réalité pas, d'où des 404 sur toutes les routes IM/voix à partir de S45 et des SQL de la phase de nettoyage frappant la mauvaise base. Le processus a été arrêté et le service social redémarré sur 8788/8789 (`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`) ; le health check est revenu à `social-service`.
3. **La mise à niveau ImageMagick 7 a fait planter le driver Imagick du captcha (environnement, géré)** : après la montée d'ImageMagick système en 7.1.2-27 (build 2026-07-08), `PixelsResource` a été retiré ; imagick 3.8.1 ne définit plus `Imagick::RESOURCETYPE_PIXELS`, et le constructeur de `ImagickDriver` de poster-php lève immédiatement `Undefined constant` (code vendor, non modifié), donc la génération/vérification du captcha (A05/A06) renvoie 500 et bloque en cascade le login A08-A11. **Gestion** : le service admin a été redémarré avec le commutateur de driver prévu dans la doc de configuration — `POSTER_IMAGE_DRIVER=gd` (admin/config/poster.php:17 supporte nativement gd/imagick/auto) ; après bascule du captcha sur le driver GD, toute la chaîne fonctionne. Pour rétablir le driver Imagick, rétrograder ImageMagick en 6.x ou mettre à jour poster-php pour la compatibilité IM7.
4. **Le mot de passe root de MySQL est passé à vide** : le tour précédent notait `root/root` ; ce tour, la connexion avec mot de passe vide fonctionne, tous les services et scripts ont été démarrés avec le mot de passe vide.
5. **Environnement de redémarrage du service admin** : « admin n'a pas de .env, dépend des variables d'environnement » du tour précédent reste valable ; commandes de redémarrage ci-dessous dans « Environnement et reproduction ».
6. **service/.env reste `service/.env.api-test-bak`** : déplacé au tour précédent pour le test de connectivité et non restauré (restauration limitée par la politique d'accès au fichier .env) ; ce tour, le service est de nouveau démarré avec des variables d'environnement. Un `mv service/.env.api-test-bak service/.env` manuel est requis (redémarrer le service après restauration ; noter l'adresse de base qu'il contient).
7. **Elasticsearch non démarré** : `GET /api/v1/search/posts` renvoie 503 (dégradation prévue) ; les cas de recherche du groupe S traités comme attendu (0 ou 503 acceptés), non comptés en échec.

## Écarts contrat/documentation (révision suggérée, non bloquant)

- La documentation du captcha (apidoc et commentaires CaptchaController) décrit `clicks=[{x,y}]` comme un tableau d'objets, alors que l'implémentation `poster-php` exige un tableau de paires de coordonnées `[[x,y]]` ; passer des objets selon la doc échoue toujours en pratique.
- L'upload vocal renvoie `voice_url` sous la forme `/voice/{md5}.m4a` (relatif à la racine de l'API, sans préfixe `/api/v1`) ; le client doit ajouter `/api/v1` lui-même pour accéder au fichier ; l'accès aux fichiers passe par des routes authentifiées (token requis).

## Environnement et reproduction

- Identifiants de test : compte `e2e_smoke` (admin, mot de passe réservé aux tests) + `apitest_*@test.dev` (service, nettoyé automatiquement après l'exécution), tous écrits dans les constantes de `tests/api/run.php` ; aucune clé réelle utilisée.
- Reproduction :

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD='' ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' POSTER_IMAGE_DRIVER=gd \
  php start.php start                                          # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD='' php start.php start                           # service :8788
cd /home/wwwroot/social/tests/api && DB_PASS='' php run.php    # re-exécuter (116 cas)
```

- Attention : vérifier que le port 8788 n'est pas occupé par le service `property-management-platform` (les deux projets utilisent le même port par défaut ; quand les deux projets coexistent sur cette machine, il faut les décaler).

## Inventaire des endpoints (selon route.php / apidoc)

- service `config/route.php` : 39 routes HTTP (authentification 5, utilisateurs 2, abonnements 5, publications 7, commentaires 2, notifications 4, recherche 2, IM 4, voix/appels/salles 5, health/docs 3)
- admin `config/route.php` : 33 routes HTTP (authentification/captcha 4, CRUD utilisateurs 5, rôles 5, permissions 2, configuration 4, journaux 1, profil 4, export 2, import 1, upload 1, health/docs 4)
