# Document d'architecture de sécurité

**语言 / Languages:** [中文](SECURITY.md) · [English](SECURITY.en.md) · [한국어](SECURITY.ko.md) · [Русский](SECURITY.ru.md) · [Deutsch](SECURITY.de.md) · [Français](SECURITY.fr.md) · [Español](SECURITY.es.md) · [Português](SECURITY.pt.md) · [हिन्दी](SECURITY.hi.md) · [العربية](SECURITY.ar.md) · [বাংলা](SECURITY.bn.md) · [Bahasa Indonesia](SECURITY.id.md) · [日本語](SECURITY.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Vue d'ensemble de la défense en profondeur

Le système adopte un modèle de défense en profondeur sur 7 couches. Les requêtes malveillantes sont filtrées couche par couche de l'extérieur vers l'intérieur, de sorte que même si une couche tombe en panne, les lignes de défense suivantes continuent de protéger.

Toute la chaîne de middlewares s'exécute dans l'ordre suivant (voir `config/middleware.php`) :

```
请求 → Cors → Locale(Accept-Language) → SecurityMiddleware (erikwang2013/security-php, 31种检测器) → RateLimit → [路由组中间件: AdminAuth → AdminPermission → OperationLog] → Controller
```

| Couche | Middleware / mécanisme | Cible de protection |
|----|--------|---------|
| 1 | SecurityMiddleware (erikwang2013/security-php) | 31 détections d'attaques + validation des méthodes HTTP + limite de taille du corps de requête + validation Content-Type + CSRF + liste noire d'escalade d'attaques par IP |
| 2 | Cors | Sécurité inter-origines + injection d'en-têtes de réponse de sécurité |
| 3 | RateLimit | Limitation de débit à fenêtre glissante Redis, anti force brute |
| 4 | AdminAuth | Authentification JWT + déconnexion par liste noire |
| 5 | AdminPermission | Autorisation RBAC à granularité method.path |
| 6 | OperationLog | Audit des opérations + suivi de la source cliente |
| 7 | Chiffrement des données | Obfuscation des IDs Hashids + chiffrement base de données Encryptable + chiffrement de transport EncryptionService |

Les couches front-end (Flutter) disposent de leur propre validation d'entrée indépendante ; le back-end ne fait confiance à rien, et chaque couche se défend de manière indépendante.

---

## 2. Moteur de détection d'attaques

## 2. 攻击检测引擎 (erikwang2013/security-php)

La détection d'attaques a été migrée du SecurityMiddleware maison vers le paquet de sécurité dédié `erikwang2013/security-php` v1.1+, qui fournit **31 détecteurs** couvrant 5 grandes catégories d'attaques.

### 2.1 Classification des détecteurs

**Attaques par injection (11) :** XSS, injection SQL, injection de commandes, injection NoSQL, injection LDAP, injection XPath, JNDI/Log4Shell, inclusion côté serveur SSI, injection GraphQL, injection de templates SSTI

**Attaques protocoles et requêtes (9) :** SSRF, XXE, injection d'en-têtes de réponse HTTP, attaque d'en-tête Host, Request Smuggling, Open Redirect, contournement CORS, détournement WebSocket, DNS Rebinding

**Validation de la couche protocole HTTP (6) :** validation des méthodes HTTP (405), limite de taille du corps de requête (413), validation Content-Type (415), vérification Origin CSRF, liste noire d'escalade d'attaques par IP, détection de fuite de données sensibles

**Attaques données et sérialisation (5) :** désérialisation PHP, injection de formules CSV, injection d'en-têtes d'e-mail, attaques JWT (analyse structurée), JS Prototype Pollution

**Attaques fichiers et chemins (2) :** traversée de chemin, téléversement de fichiers malveillants

### 2.2 Modes de traitement

Chaque détecteur prend en charge indépendamment deux modes :
- `block` — bloquer lorsqu'une attaque est détectée, renvoyer le code de statut configuré
- `log` — journaliser uniquement sans bloquer (`header_injection`, `ssti`, `nosql_injection` sont par défaut en mode log pour éviter les faux positifs)

### 2.3 Liste noire d'escalade d'attaques par IP

Si une même IP déclenche 5 détections d'attaques en 60 secondes, elle est automatiquement bannie pendant 15 minutes. Le backend de stockage peut être Redis (distribué), File (JSON mononœud) ou Cache (fichiers indépendants pour haute concurrence) ; la configuration actuelle utilise Redis.

### 2.4 Journaux de sécurité

Emplacement du fichier : `runtime/logs/security.log` (rotation automatique, 10 Mo par fichier)

---

## 4. En-têtes de réponse de sécurité

Tous les en-têtes sont injectés dans le middleware `Cors` et ajoutés à chaque réponse via `$response->withHeaders()`.

| En-tête | Valeur | Rôle |
|----|-----|------|
| Access-Control-Allow-Origin | `*` | Autoriser les requêtes inter-origines depuis toute source (scénario console d'administration intranet) |
| Access-Control-Allow-Methods | `GET,POST,PUT,DELETE,OPTIONS` | Ensemble de méthodes autorisées |
| Access-Control-Allow-Headers | `Authorization,Content-Type,API-Version` | En-têtes personnalisés autorisés |
| Access-Control-Max-Age | `86400` | Mise en cache de la réponse de pré-vérification pendant 24 heures |
| X-Content-Type-Options | `nosniff` | Empêcher le sniffing MIME du navigateur |
| X-Frame-Options | `DENY` | Interdire tout embarquement iframe, anti clickjacking |
| X-XSS-Protection | `1; mode=block` | Activer le filtre XSS intégré du navigateur et bloquer le rendu de la page |
| Referrer-Policy | `strict-origin-when-cross-origin` | Même origine : URL complète ; inter-origines : domaine uniquement |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | Désactiver les API caméra/micro/géolocalisation sur tout le site |

Les requêtes de pré-vérification OPTIONS renvoient directement une réponse 204 vide et n'entrent pas dans la chaîne de middlewares suivante.

### 4.2 Content-Security-Policy (CSP)

Injectée avec les autres en-têtes de sécurité dans le middleware Cors, elle offre une défense en profondeur en restreignant les origines des ressources que le navigateur peut charger et exécuter.

| En-tête | Valeur | Rôle |
|----|-----|------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | Restreindre les origines des scripts/styles/images/connexions/frames/formulaires et autres ressources |
| X-Permitted-Cross-Domain-Policies | `none` | Interdire le chargement de fichiers de politiques inter-domaines type Adobe Flash/PDF |

Points clés de la politique CSP :
- `default-src 'self'` : seules les ressources de même origine sont autorisées par défaut
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'` : scripts de même origine + scripts en ligne (requis par Flutter Web) + eval (requis pour le débogage Flutter Web)
- `frame-ancestors 'none'` : interdire l'embarquement dans les iframes de toute page, double protection avec X-Frame-Options: DENY
- `base-uri 'self'` : limiter la balise `<base>` à la même origine
- `form-action 'self'` : limiter la soumission des formulaires à la même origine

---

## 5. Stratégie de limitation de débit

### Algorithme

Fenêtre glissante Redis Sorted Set + script Lua atomique, pour les opérations critiques :

```lua
-- 1. 清理窗口外的旧记录
redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, windowStart)
-- 2. 检查当前窗口计数
local count = redis.call('ZCARD', KEYS[1])
-- 3. 超限则返回 {0, count}，未超限则 ZADD 并返回 {1, count+1}
if count >= limit then return {0, count} end
redis.call('ZADD', KEYS[1], now, now . '.' . random)  -- 随机后缀避免同毫秒覆盖
redis.call('EXPIRE', KEYS[1], window + 10)
```

Le script Lua s'exécute en monothread sur le serveur Redis, **naturellement atomique**, éliminant les conditions de course TOCTOU (Time-of-check to Time-of-use).

### Configuration de la limitation de débit

| Route | Limite | Fenêtre | Scénario |
|------|------|------|------|
| Par défaut (toutes les routes) | 60 fois/minute | 60s | API générale |
| `/api/auth/login` | 10 fois/minute | 60s | Connexion (anti force brute) |
| `/api/auth/register` | 5 fois/minute | 60s | Inscription (anti inscription en masse) |

### En-têtes de réponse

Lorsque la limitation est déclenchée, HTTP 429 avec corps JSON est renvoyé :
```json
{"code": 429, "message": "请求过于频繁，请稍后再试", "data": []}
```

Toutes les réponses (y compris normales) portent les en-têtes suivants :

| En-tête | Description |
|----|------|
| X-RateLimit-Limit | Nombre maximal de requêtes autorisées dans la fenêtre courante |
| X-RateLimit-Remaining | Requêtes restantes disponibles dans la fenêtre courante |
| X-RateLimit-Reset | Horodatage Unix de réinitialisation de la fenêtre |
| Retry-After | Présent uniquement en cas de limitation ; secondes d'attente recommandées |

### Stratégie de dégradation

En cas de dysfonctionnement de Redis (délai de connexion dépassé, indisponibilité, etc.), **fail-open** :

```php
try {
    $result = Redis::eval($lua, ...);
} catch (\Throwable $e) {
    return $handler($request); // Redis down, 放行所有请求
}
```

Mieux vaut perdre temporairement la protection de limitation que de bloquer les requêtes métier normales.

### 5.4 Mécanisme de verrouillage de compte

En plus de la limitation de débit, le point de terminaison de connexion ajoute un mécanisme de **verrouillage de compte** pour empêcher la force brute ciblée contre des utilisateurs spécifiques.

**Processus de verrouillage** :

```
登录失败 → Redis INCR account_lockout:{userId} TTL=900s
连续 5 次失败 → Redis SETEX account_locked:{userId} 900 1
            → 返回 429 "账号已被锁定，请15分钟后再试"
            → 清除计数器 DEL account_lockout:{userId}
```

**Comportement pendant le verrouillage** :

Pendant la période de verrouillage, toutes les requêtes de connexion renvoient directement 429 sans vérification du mot de passe, bloquant complètement les tentatives de force brute.

**Constantes de configuration** :

| Constante | Valeur | Signification |
|------|-----|------|
| MAX_LOGIN_ATTEMPTS | 5 | Nombre maximal d'échecs consécutifs |
| LOCKOUT_DURATION | 900 | Durée du verrouillage en secondes, soit 15 minutes |

Remarque : le verrouillage de compte est basé sur `userId` et non sur l'IP ; changer d'IP ne permet donc pas de le contourner. Combiné à la limitation de débit IP (10 fois/minute), il forme une double protection :
- Niveau IP : la limitation à 10 fois/minute bloque la force brute distribuée
- Niveau compte : le verrouillage après 5 échecs bloque la force brute ciblée

---

## 6. Authentification et autorisation

### 6.1 Authentification JWT

Implémentée par le middleware AdminAuth, monté sur les groupes de routes nécessitant une authentification.

**Configuration des paramètres** (`config/plugin/erikwang2013/jwt/jwt`, injectée via `.env`) :

| Paramètre | Valeur | Description |
|------|-----|------|
| Algorithme | HS256 | Signature symétrique HMAC-SHA256 |
| Secret | `JWT_SECRET` | Injecté via variable d'environnement ; à changer en production |
| TTL access_token | 7200s (2h) | `JWT_TTL` |
| TTL refresh_token | 1209600s (14j) | `JWT_REFRESH_TTL` |
| Émetteur | `open-admin` | `JWT_ISSUER` |
| Audience | `open-admin` | `JWT_AUDIENCE` |

**Extraction du jeton** : extrait de l'en-tête `Authorization: Bearer <token>` ; retirer le préfixe `Bearer ` pour obtenir le JWT brut.

**Processus d'authentification** :
1. Jeton vide → directement 401 `{"code": 401, "message": "未登录"}`
2. Vérification de la liste noire Redis `jwt_blacklist:{md5(token)}` → correspondance → 401 `Token已失效，请重新登录`
3. Décodage JWT → échec (expiré/signature non conforme) → 401 `Token已过期或无效`
4. Succès → injection de `$request->adminId` et `$request->adminUsername`

**Mécanisme de liste noire** : à la déconnexion, `md5(token)` est écrit dans Redis avec un TTL égal à la validité restante du JWT. En cas de panne Redis, la vérification de la liste noire est ignorée (fail-open) ; le jeton déconnecté peut alors être utilisé brièvement, mais la courte validité du JWT lui-même (2h) sert de protection de secours.

### 6.2 Limitation des sessions simultanées

Pour empêcher l'exploitation d'un jeton divulgué sur plusieurs appareils, le système limite le nombre de jetons valides qu'un même utilisateur peut détenir simultanément.

**Logique de limitation** :

```
登录成功 → 签发新 Token
         → 查询当前用户有效 Token 数量: Redis SCARD user_tokens:{userId}
         → 若数量 >= 3（MAX_CONCURRENT_SESSIONS）:
            → 按创建时间升序排列，移除最旧的 Token:
              Redis SREM user_tokens:{userId} <oldest_token_id>
              Redis SETEX jwt_blacklist:{md5(oldest_token)} TTL remaining
         → 将新 Token 加入集合: Redis SADD user_tokens:{userId} <new_token_id>
            Redis SET user_token:{token_id} {userId} EX {TTL}
```

**Constante de configuration** :

| Constante | Valeur | Signification |
|------|-----|------|
| MAX_CONCURRENT_SESSIONS | 3 | Nombre maximal de jetons simultanés par utilisateur |

**Scénario de déconnexion forcée** : lorsque l'utilisateur se connecte sur un 4e appareil, le jeton du 1er appareil est forcément ajouté à la liste noire ; les requêtes suivantes renvoient 401 "Token已失效，请重新登录".

À la déconnexion, le jeton courant est retiré de l'ensemble. Lorsqu'un jeton expire naturellement, la clé Redis expire automatiquement et l'ensemble se réduit en conséquence.

### 6.3 Modèle de permissions RBAC

Implémenté par le middleware AdminPermission.

**Modèle de données** : association à trois niveaux User -> Role -> Permission

- `erik_admin_user` (table des utilisateurs)
- `erik_admin_user_role` (table d'association utilisateur-rôle)
- `erik_admin_role` (table des rôles)
- `erik_admin_role_permission` (table d'association rôle-permission)
- `erik_admin_permission` (table des permissions)

**Types de permissions** :
| type | Signification | Exemple |
|------|------|------|
| 1 | Permission de menu | Contrôle la visibilité de la navigation de gauche |
| 2 | Permission de bouton | Contrôle les boutons d'action dans la page (ajouter/modifier/supprimer) |
| 3 | Permission API | Contrôle les appels d'interface back-end |

Format de l'identifiant de permission API : `{method}.{path}`

Par exemple :
- `post.admin/user` — créer un utilisateur
- `put.admin/user` — modifier un utilisateur
- `delete.admin/user` — supprimer un utilisateur
- `get.admin/user` — consulter la liste des utilisateurs

**Processus d'autorisation** :
1. `$request->adminId` vide → laisser passer (la route n'a pas de prérequis d'authentification configuré)
2. Obtenir l'utilisateur → rôles (ignorer les rôles désactivés avec `status=0`) → liste des permissions
3. Super administrateur (`slug = '*'`) → laisser passer directement
4. Construire `strtolower(method) . '.' . trim(path, '/')` → comparer avec la liste des permissions
5. Aucune correspondance → 403 `{"code": 403, "message": "无权限访问"}`

**Double confirmation** : BaseController fournit la méthode `confirmPassword()` ; les opérations sensibles (suppression d'utilisateur, export de données, etc.) exigent en plus la saisie du mot de passe courant au niveau du Controller, empêchant les opérations non autorisées après détournement de session.

---

## 7. Journaux d'audit

### 7.1 Journaux d'opérations

Le middleware OperationLog enregistre automatiquement les journaux d'opérations pour les requêtes POST / PUT / DELETE. Les requêtes GET ne sont pas enregistrées.

**Champs enregistrés** :

| Champ | Source | Description |
|------|------|------|
| id | SnowflakeService::generate() | ID globalement unique |
| user_id | `$request->adminId` | ID de l'opérateur, 0 si non connecté |
| action | `$request->method()` | Identique à method |
| method | `$request->method()` | POST / PUT / DELETE |
| path | `$request->path()` | Chemin de la requête |
| ip | `$request->getRealIp()` | IP réelle du client |
| source | detectSource() | Plateforme source du client |
| input | Corps de la requête (JSON masqué) | Données d'opération soumises |
| created_at | `date('Y-m-d H:i:s')` | Heure de l'opération |

**Filtrage des champs sensibles** : parcours récursif du corps de la requête ; les valeurs des champs suivants sont remplacées par `***` :

`password`, `old_password`, `new_password`, `new_password_confirmation`, `token`, `secret`, `access_token`, `refresh_token`

**Détection de la source** (`detectSource()`) : par priorité :

1. Lire d'abord l'en-tête personnalisé `X-Client-Platform` (déclaré explicitement par les clients natifs)
2. Repli sur l'inférence à partir de la chaîne User-Agent (ordre de détection de la méthode `detectSource()`) :

| Plateforme | Mots-clés UA |
|------|----------|
| iPadOS | `iPad` |
| macOS | `Macintosh`, `Mac OS` |
| Windows | `Windows` |
| Linux | `Linux` |
| iOS | `iPhone`, `iOS`, `CFNetwork` |
| Android | `Android` |
| HarmonyOS | `HarmonyOS`, `OpenHarmony` |
| Web | Valeur par défaut de repli |

**Tolérance aux pannes** : les exceptions d'écriture de journal ne bloquent pas les requêtes métier (`catch (\Throwable)` avalé silencieusement).

### 7.2 Journaux de sécurité

**Emplacement du fichier** : `runtime/logs/security.log`

**Contenu enregistré** :
- Journaux d'interception d'attaques : catégorie d'attaque, IP, chemin, champ, source, extrait du payload (200 premiers caractères)
- Notifications de bannissement IP : IP bannie, nombre de déclenchements

La permission du journal est `FILE_APPEND | LOCK_EX`, garantissant une écriture sûre en concurrence.

---

## 8. Protection des données

Le système adopte une stratégie de protection des données à trois niveaux, correspondant aux trois étapes du flux de données.

### 8.1 Couche transport — EncryptionService

`EncryptionService` utilise le paquet `erikwang2013/encryption` pour chiffrer/déchiffrer les champs sensibles dans les requêtes/réponses API.

**Détails techniques** :
- Algorithme : `aes-256-cbc-hmac` (signature HMAC intégrée anti-altération)
- Clé : variable d'environnement `ENCRYPTION_KEY`, automatiquement alignée sur 32 octets
- Usage : transport entre client et API de champs tels que numéros de téléphone et numéros de carte d'identité

**Méthodes utilitaires de masquage** :
- `maskPhone('13812341234')` → `138****1234`
- `maskEmail('abc@example.com')` → `a***@example.com` (nom d'utilisateur de plus de 2 caractères) ou `a**@example.com`

### 8.2 Couche stockage — Encryptable Cast

Le modèle `AdminUser` utilise le cast Eloquent `Erikwang2013\Encryptable\Encryptable`, avec les champs correspondants :

- `email` → cast en Encryptable, chiffrement/déchiffrement automatique
- `phone` → cast en Encryptable, chiffrement/déchiffrement automatique
- `id_card` → cast en Encryptable, chiffrement/déchiffrement automatique

Chiffré automatiquement en texte chiffré à l'écriture en base, déchiffré automatiquement en texte clair à la lecture. Le type de colonne de stockage est `VARCHAR(500)`, le texte chiffré étant stocké en base64.

**Système de clés** : utilise `ENCRYPTABLE_KEY` indépendamment du chiffrement de transport (`ENCRYPTION_KEY`) ; la fuite d'une clé ne compromet pas l'autre couche.

Rotation des clés : la variable d'environnement `ENCRYPTION_PREVIOUS_KEYS` prend en charge une liste de clés historiques (séparées par des virgules). À la lecture d'anciennes données, le déchiffrement est tenté avec les clés historiques ; à la réécriture, le chiffrement est refait avec la clé courante.

### 8.3 Couche présentation — Obfuscation des IDs et masquage

**Obfuscation des IDs Hashids** : `HashidsService` utilise le paquet `erikwang2013/hashids`.

- Les IDs BIGINT de la base renvoyés par les API externes sont encodés en chaînes de hachage (ex. `xK3mN9qR2pL7wV8b`)
- Les clients transmettent la chaîne de hachage dans les requêtes ; le back-end la décode automatiquement en ID d'origine
- Le sel est injecté via la variable d'environnement `HASHIDS_SALT` ; un sel différent produit des résultats d'encodage/décodage totalement différents
- Longueur minimale du hachage : 16 caractères, jeu alphanumérique de 62 caractères
- BaseController fournit les méthodes pratiques `encodeId()`, `decodeId()`, `encodeIds()`

**Masquage à l'export** : lors des exports Excel/PDF (ExportController), les champs sensibles sont uniformément masqués :
- Téléphone : `138****1234`
- E-mail : `a***@example.com`
- Carte d'identité : entièrement couverte par `********`

---

## 9. Gestion des clés

Toutes les clés sont injectées via des variables d'environnement `.env` ; les fichiers de configuration les lisent avec `getenv()` et disposent de valeurs par défaut de repli intégrées (sûres uniquement en environnement de développement).

| Variable d'environnement | Usage | Paquet | Exigence de production |
|----------|------|-----|---------|
| JWT_SECRET | Clé de signature JWT | erikwang2013/jwt-webman | Chaîne aléatoire de 64+ caractères |
| JWT_ALGORITHM | Algorithme de signature JWT | idem | Conserver HS256 |
| HASHIDS_SALT | Sel d'encodage des IDs | erikwang2013/hashids | Chaîne aléatoire |
| SNOWFLAKE_DATACENTER_ID | ID du centre de données (0-31) | erikwang2013/snowflake-php | Conserver la valeur par défaut pour un seul DC |
| ENCRYPTION_KEY | Clé de chiffrement de la couche transport API | erikwang2013/encryption | Chaîne aléatoire de 32 octets |
| ENCRYPTABLE_KEY | Clé de chiffrement de la couche stockage DB | erikwang2013/encryptable | Chaîne aléatoire de 32 octets, différente de la clé de transport |

**Exigences de sécurité** :
- Le fichier `.env` est dans `.gitignore` ; son commit dans le dépôt est strictement interdit
- `.env.example` est un fichier modèle public ne contenant aucune clé réelle
- En production, **obligation** de remplacer toutes les clés par défaut par des chaînes aléatoires
- Génération recommandée : `openssl rand -base64 32`

### Isolation du stockage des clés

| Couche | Clé de configuration | Variable d'environnement de clé |
|----|--------|-------------|
| Chiffrement de transport | `config/encryption.php` → `key` | `ENCRYPTION_KEY` |
| Chiffrement de stockage | `config/encryptable.php` → `key` | `ENCRYPTABLE_KEY` |
| Obfuscation des IDs | `config/hashids.php` → `connections.main.salt` | `HASHIDS_SALT` |
| Signature JWT | `config/plugin/erikwang2013/jwt/jwt` | `JWT_SECRET` |

---

## 10. security.txt (RFC 9116)

Le système fournit à `/.well-known/security.txt` un point de terminaison d'informations de contact de sécurité conforme à la norme RFC 9116, permettant aux chercheurs en sécurité de trouver rapidement un canal de signalement lors de la découverte de vulnérabilités.

**Mode d'accès** :

```
GET /.well-known/security.txt
```

**Contenu de la réponse** :

```text
Contact: mailto:security@erik.xyz
Expires: 2027-05-20T00:00:00.000Z
Preferred-Languages: zh, en
Canonical: https://erik.xyz/.well-known/security.txt
Policy: https://erik.xyz/security-policy
```

**Description des champs** :

| Champ | Description |
|------|------|
| Contact | Contact pour le signalement des vulnérabilités de sécurité |
| Expires | Date d'expiration du fichier, à mettre à jour régulièrement |
| Preferred-Languages | Langues de communication préférées |
| Canonical | URL canonique de ce fichier |
| Policy | Lien vers la politique de sécurité / de divulgation des vulnérabilités |

Ce point de terminaison n'est soumis à aucune limitation de débit, authentification ou autre middleware ; tout le monde peut y accéder directement.

---

## 11. Configuration de sécurité Nginx

Le projet fournit `docs/nginx-security.conf` comme configuration de référence pour durcir le proxy inverse Nginx en production.

**Mesures de sécurité incluses** :

| Élément de configuration | Rôle |
|--------|------|
| `server_tokens off` | Masquer le numéro de version de Nginx |
| `client_max_body_size 10m` | Limiter la taille du corps de requête, en coordination avec SecurityMiddleware (erikwang2013/security-php) |
| `limit_req_zone` | Limitation de fréquence des requêtes au niveau Nginx |
| `limit_conn_zone` | Limitation du nombre de connexions simultanées |
| En-têtes de sécurité `add_header` | Ajout d'en-têtes de sécurité comme X-XSS-Protection au niveau Nginx |
| `if ($request_method)` | Refus des méthodes HTTP non standard au niveau Nginx |
| Configuration SSL/TLS | Configuration TLS 1.2/1.3 moderne, suites de chiffrement faibles désactivées |
| Masquage des en-têtes back-end | `proxy_hide_header` supprime les en-têtes sensibles comme la version webman |

**Utilisation** : fusionner la configuration de `docs/nginx-security.conf` dans votre bloc server Nginx, en l'adaptant à votre domaine et à vos chemins de certificats réels.

---

## 12. Modèle de menaces

### 12.1 Menaces protégées

| Type de menace | Vecteur d'attaque | Couche de défense |
|----------|---------|---------|
| Abus de méthodes HTTP | Attaques TRACE/TRACK XST, proxy tunnel CONNECT, sondage de méthodes WebDAV | Détecteur http_method de SecurityMiddleware, liste blanche de méthodes 405 |
| Force brute ciblée | Tentatives répétées de mot de passe contre un utilisateur spécifique | Verrouillage de compte (15 min après 5 échecs) + RateLimit (connexion 10/min) + Captcha |
| Force brute | Tentatives répétées nom/mot de passe depuis des IP distribuées | RateLimit (connexion 10/min) + Captcha |
| XSS (scripting inter-sites) | `<script>`, onerror, javascript: | SecurityMiddleware (erikwang2013/security-php) (5 motifs) + en-tête de réponse X-XSS-Protection + CSP |
| Injection SQL | UNION SELECT, OR 1=1, contournement par commentaire | SecurityMiddleware (erikwang2013/security-php) (6 motifs) + requêtes paramétrées Eloquent ORM |
| CSRF (falsification de requête inter-sites) | Sites malveillants forgeant des requêtes | Validation Origin/Referer dans SecurityMiddleware (erikwang2013/security-php) |
| Traversée de chemin | `../../etc/passwd` | Motif de traversée de chemin de SecurityMiddleware (erikwang2013/security-php) + liste blanche d'extensions UploadController |
| Injection de commandes | `;ls`, `` `whoami` ``, `$(cat ...)` | SecurityMiddleware (erikwang2013/security-php) (4 motifs) |
| Détournement de session | Vol de jetons JWT | Courte validité JWT (2h) + déconnexion par liste noire + double confirmation du mot de passe pour les opérations sensibles |
| Énumération d'IDs | Deviner le volume de données en parcourant les IDs numériques | Obfuscation Hashids en chaînes aléatoires |
| Fuite de données | Exfiltration de la base / homme du milieu / fuite de journaux | Chiffrement/masquage à trois niveaux + filtrage des champs sensibles OperationLog |
| Attaques DoS | Corps de requête surdimensionnés / requêtes à haute fréquence | Limite du corps de requête 10 Mo + RateLimit 60/min + liste noire IP |
| Élévation de privilèges | Utilisateurs à faibles droits accédant aux interfaces d'administration | Autorisation RBAC à granularité method.path |
| Attaques par téléversement de fichiers | Double extension shell.php.png | Détection de fichiers malveillants dans SecurityMiddleware (erikwang2013/security-php) |

### 12.2 Limitations connues

| Limitation | Périmètre d'impact | Mesures d'atténuation |
|------|---------|---------|
| La protection CSRF ne fonctionne que pour les navigateurs | Les clients non-navigateur (curl, Postman, apps mobiles) peuvent contourner les vérifications Origin/Referer | Les clients non-navigateur sont naturellement immunisés contre le CSRF ; s'appuyer sur l'authentification JWT plutôt que sur les cookies |
| En cas d'indisponibilité Redis, la limitation et la liste noire dégradent en fail-open | Les attaquants peuvent contourner la limitation de débit et l'interception haute fréquence | Surveiller la disponibilité de Redis avec alertes ; la liste noire IP prend en charge trois backends file/redis/cache pour la dégradation |
| Pas de moteur WAF autonome | Détection par expressions régulières, pas un moteur de règles WAF dédié | Recommandation : Nginx ModSecurity ou Cloudflare WAF en amont en production |
| JWT sans état impossible à invalider activement | Les jetons ne peuvent pas être révoqués côté serveur avant expiration (hors liste noire) | Liste noire + TTL court de 2h réduit la fenêtre de risque |
| Aucune limitation spécifique pour les points de terminaison administrateur | Les API d'administration partagent la limite par défaut de 60/min avec les API classiques | La fréquence des opérations d'administration est naturellement basse ; pas de distinction nécessaire pour l'instant |
| Limite de backtracking PCRE | Le paquet intègre une limite de 1 000 000 de backtracks + récupération via finally ; les entrées extrêmement complexes présentent toujours un risque de performance | Limite de taille du corps de requête (10 Mo) en secours |
