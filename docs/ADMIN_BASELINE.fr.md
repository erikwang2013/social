# Recette de référence Admin (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

État de référence et points d'entrée de transformation pour open-admin (webman v2 + console d'administration Flutter).

## Version actuelle et état d'exécution

| Élément | Valeur |
|---|---|
| Framework | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| Dépendances | `composer install` réussi, 69 paquets |
| .env | **Absent** (le dépôt ne contient ni `.env` ni `.env.example` ; à créer localement selon MySQL/Redis) |
| Point d'entrée des migrations | Aucun (`think`/`artisan` absents ; webman n'intègre pas de migrations, M0 n'a pas de tâche de migration) |
| Tests | `vendor/bin/phpunit` : 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky — pas entièrement verts** |

## Modules activés (confirmé dans le README)

- **Authentification JWT** : connexion/rafraîchissement/déconnexion, captcha à clic, verrouillage de compte (5 échecs → 15 minutes de verrouillage), limite de sessions simultanées (≤3 tokens par utilisateur)
- **RBAC** : arborescence rôles/permissions, autorisation à la granularité method.path
- **Audit des opérations** : interrogation des journaux + identification de 8 sources de plateforme
- **Gestion des fichiers** : upload / export Excel / export PDF (masqué)
- **i18n** : bascule chinois/anglais (Accept-Language / ?lang=)
- Autres : tableau de bord (cache Redis), configuration système, health check/metrics/OpenAPI 3.0, protection de sécurité en 18 couches

## Détails des échecs de tests (tous des manques existants du projet, non introduits par cette modification)

| Groupe de tests | Échec | Raison |
|---|---|---|
| `EnvConfigTest` (5 cas) | 4 failure + 1 error | Les tests exigent que `.env`/`.env.example` existent et que les valeurs getenv de `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` etc. soient définies ; le dépôt ne fournit pas d'env d'exemple |
| `CaptchaTest` (4 cas) | 3 error + 1 failure (plus 1 risky sans assertion) | Le captcha à clic dépend du stockage Redis, non fourni localement |
| `BackendEnhancementTest` (2 cas) | 2 failure | Assertions : la source de données `user` contient searchable et le middleware cors/rate_limit — dérive entre configuration et assertions des tests |

Étapes locales pour revenir au vert : créer `.env` selon les clés de configuration dans `config/` (ajouter les clés dont dépend EnvConfigTest), fournir MySQL + Redis (pour CaptchaTest), puis le responsable tranche les deux dérives de configuration de BackendEnhancementTest.

## État de préparation gRPC (T3)

- Paquets Composer installés : `grpc/grpc 1.82.0`, `google/protobuf 5.35` (`--no-plugins` contourne le bug de double chargement du plugin security-php)
- Stubs PHP générés : `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` etc., incluant les trois jeux de contrats : infra/user)
- **Extension PHP grpc non installée** : pecl sans droit d'écriture et sudo exige un mot de passe ; `sudo pecl install grpc` requis avant d'exécuter le client gRPC

## Points d'entrée de transformation (huit nouveaux éléments du §3.4 du document de conception)

1. Atelier de modération de contenu : examen bilingue côte à côte des publications/commentaires/images, modèles multilingues de motifs de rejet, sanctions utilisateur
2. File d'attente de traitement des signalements
3. Guichet des demandes GDPR (tickets d'export/suppression)
4. Intégration du tableau de bord de données avec bee_tsdb
5. Gestion des entrées i18n (CRUD commun aux quatre clients)
6. Gestion de la bibliothèque de cadeaux (SKU, prix, effets, noms multilingues)
7. Configuration des providers de live (stratégie de routage, ordre de bascule)
8. Examen des demandes de retrait

**Points d'intégration gRPC** : les stubs de contrats côté admin sont dans `admin/generated/` (réutilisation de `Social/Admin/V1` pour les sondes de disponibilité + futurs messages métier) ; les appels vers service passent par `Social\User\V1\UserServiceClient` et vers infrastructure par `Social\Infra\V1\InfraServiceClient` ; la chaîne de sondes avec service/infrastructure est décrite dans `service/README.grpcs.md` et les sondes d'intégration T10.
