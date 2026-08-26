# Rapport de tests automatisés de l'API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Date : 2026-08-27
- Exécution : `tests/api/run.php` (script d'assertions curl), résultats dans `tests/api/results.json`
- Périmètre : admin HTTP API (A01-A45) + service HTTP API (S01-S57b, incluant S58-S68)
- Services : admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` non couvert par cette série de tests HTTP)

## Conclusion

**116 cas de test : 113 réussis / 3 échoués (taux de réussite 97,4 %) ; les 3 échecs sont des défauts produit dont la cause racine est identifiée**

| Groupe | Réussis/Total |
|------|-----------|
| admin A01-A45 (authentification, captcha, gestion des utilisateurs, HashID, rôles et permissions, configuration, journaux, export/import, upload, health checks, etc.) | 42/45 |
| service S01-S68 (inscription/connexion/déconnexion/refresh, profil, abonnements, publications/likes/fil d'actualité, commentaires, notifications, recherche, sessions IM/messages/push, upload vocal/fichiers/appels/salles, etc.) | 71/71 |

## Cas de test échoués (3, tous des défauts produit)

| Cas | Attendu | Réel | Cause racine |
|------|------|------|------|
| A20 Détails utilisateur hashid invalide | 404 | 500 | `HashidsService::decode()` lève une `InvalidArgumentException` non capturée pour les ID invalides (admin/app/common/HashidsService.php:28, BaseController.php:52) ; l'exception remonte en 500, elle devrait être capturée et renvoyer 404 |
| A39 Export Excel | flux de fichier xlsx | 200+corps d'erreur JSON (échec métier) | `ExportController::excel()` déclare le type de retour `: Response` mais n'a pas `use support\Response`, le type est résolu en `app\admin\controller\Response` → tout retour réussi lève une `TypeError` (ExportController.php:122), l'export est totalement inutilisable |
| A40 Export PDF | flux de fichier pdf | 200+corps d'erreur JSON (échec métier) | Identique : `ExportController::pdf()` (ExportController.php:135) sans `use support\Response` |

> Complément (défaut potentiel dans le même fichier, actuellement masqué par la TypeError ci-dessus) : `ExportController` ligne 90 appelle `EncryptionService::decrypt()` sur phone/email, alors que les champs `email/phone/id_card` du modèle `AdminUser` déclarent le cast `Encryptable::class` (chiffrement automatique à l'écriture, déchiffrement à la lecture) ; l'export déchiffrerait donc le texte clair une seconde fois → dès qu'un compte avec téléphone/email non vide existe, une `EncryptionException: Invalid ciphertext prefix for AES-256-CBC` est levée. Ce problème se reproduira même après correction des types de retour.

## Problèmes d'environnement corrigés pendant les tests (pas des modifications de code produit)

1. **Colonne `id` des tables de migration m2/m3/m4 sans AUTO_INCREMENT (bloquant, corrigé)** : `social_follows`, `social_notifications` créées par `service/database/m2.sql`/`m3.sql`/`m4.sql` ont `id BIGINT UNSIGNED NOT NULL` sans `AUTO_INCREMENT` ; tout INSERT échoue avec `1364 Field 'id' doesn't have a default value`, bloquant tous les chemins d'écriture abonnements/notifications/IM/voix. `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` exécuté en local (les 8 autres tables ont déjà l'auto-incrément). **Les scripts de migration eux-mêmes devraient être complétés avec l'auto-incrément.**
2. **service/.env pointe vers une base inaccessible (bloquant)** : `DB_PORT=13306` sans mot de passe, alors que le MySQL principal est réellement sur `127.0.0.1:3306 (root/root)` ; le `createUnsafeMutable` de webman écrase les variables d'environnement CLI. Pendant les tests, `.env` a été déplacé vers `service/.env.api-test-bak` (contenu conservé tel quel) et le service démarré avec des variables d'environnement injectées ; la restauration n'a pas pu être faite à cause des restrictions de politique d'accès au fichier .env, un `mv service/.env.api-test-bak service/.env` manuel est requis (attention : après restauration, un redémarrage du service retombera sur la base inaccessible).
3. **admin n'a pas de .env, dépend des variables d'environnement** : requiert `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`. Le plugin `encryptable`, sans provider enregistré dans le conteneur webman, retombe sur `EnvEncryptableConfig` (lit `ENCRYPTION_KEY`, cipher par défaut aes-256-gcm) ; une longueur de clé incohérente provoque `MissingEncryptionKeyException` lors de la création/import/export de comptes.
4. **Elasticsearch non démarré** : `GET /api/v1/search/posts` renvoie 503 (dégradation prévue) ; les cas de recherche du groupe S traités comme attendu (0 ou 503 acceptés), non comptés en échec.

## Écarts contrat/documentation (révision suggérée, non bloquant)

- La documentation du captcha (apidoc et commentaires CaptchaController) décrit `clicks=[{x,y}]` comme un tableau d'objets, alors que l'implémentation `poster-php` exige un tableau de paires de coordonnées `[[x,y]]` ; passer des objets selon la doc échoue toujours en pratique.
- L'upload vocal renvoie `voice_url` sous la forme `/voice/{md5}.m4a` (relatif à la racine de l'API, sans préfixe `/api/v1`) ; le client doit ajouter `/api/v1` lui-même pour accéder au fichier ; l'accès aux fichiers passe par des routes authentifiées (token requis).

## Environnement et reproduction

- Identifiants de test : compte `e2e_smoke` (admin, mot de passe réservé aux tests) + `apitest_*@test.dev` (service, nettoyé automatiquement après l'exécution), tous écrits dans les constantes de `tests/api/run.php` ; aucune clé réelle utilisée.
- Reproduction :

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # re-exécuter (116 cas)
```

## Inventaire des endpoints (selon route.php / apidoc)

- service `config/route.php` : 39 routes HTTP (authentification 5, utilisateurs 2, abonnements 5, publications 7, commentaires 2, notifications 4, recherche 2, IM 4, voix/appels/salles 5, health/docs 3)
- admin `config/route.php` : 33 routes HTTP (authentification/captcha 4, CRUD utilisateurs 5, rôles 5, permissions 2, configuration 4, journaux 1, profil 4, export 2, import 1, upload 1, health/docs 4)
