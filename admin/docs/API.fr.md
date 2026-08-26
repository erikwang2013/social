# Documentation de référence de l'API
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Présentation

La console d'administration open-admin est construite sur webman v2 et fournit une API JSON RESTful. Tous les points d'accès d'administration exigent une authentification JWT et une vérification des droits RBAC ; les points d'accès publics sont routés vers des contrôleurs versionnés via l'en-tête de version de l'API.

- **URL de base** : `http://localhost:8787`
- **Version de l'API** : contrôlée par l'en-tête `API-Version: v1` (v1 par défaut si absent)
- **Langue** : commutée via l'en-tête `Accept-Language` ou le paramètre `?lang=zh_CN|en` (défaut zh_CN), détectée automatiquement par le middleware Locale

> **Aperçu des points d'accès** : Authentification(5) | Tableau de bord(1) | Utilisateurs(7) | Rôles(4) | Permissions(4) | Configuration(4) | Journaux(1) | Profil(3) | Import/Export(3) | Téléversement(1) | Exploitation(4: health/metrics/docs/security.txt) | 37 points d'accès au total
- **Authentification** : `Authorization: Bearer <token>` (JWT)
- **Format de réponse** : `{ "code": 0, "message": "success", "data": {...} }`
- **Point d'accès documentation** : `GET /api/docs` renvoie la spécification JSON OpenAPI 3.0

### Exigences des requêtes

- Seules les méthodes `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` sont autorisées ; les autres méthodes HTTP (par ex. TRACE, CONNECT, PATCH) renvoient 405
- Toutes les requêtes `POST` / `PUT` doivent définir `Content-Type: application/json` (sauf téléversement de fichiers), sinon 415 est renvoyé
- Le corps de la requête ne doit pas dépasser 10 Mo, sinon 413 est renvoyé
- Le filtre de sécurité analyse toutes les entrées des requêtes à la recherche de XSS, d'injection SQL, de traversée de chemin et d'injection de commandes ; les correspondances renvoient 403
- 5 échecs de connexion consécutifs déclenchent un verrouillage du compte (15 minutes) ; les demandes de connexion pendant le verrouillage renvoient 429
- Un utilisateur peut détenir au maximum 3 jetons valides simultanément ; en cas de dépassement, le jeton le plus ancien est automatiquement mis sur liste noire

## 2. Codes d'erreur

| code | Signification | Déclencheur |
|------|------|---------|
| 0 | Succès | |
| 400 | Erreur des paramètres de requête | Format de requête incorrect |
| 401 | Non authentifié | Jeton manquant / expiré / sur liste noire |
| 403 | Pas de permission / blocage de sécurité | Droits RBAC insuffisants / correspondance SecurityFilter |
| 404 | Ressource introuvable | La cible de la requête/mise à jour/suppression n'existe pas |
| 405 | Méthode non autorisée | Seuls GET/POST/PUT/DELETE/OPTIONS/HEAD sont autorisés ; les méthodes non standard sont rejetées |
| 413 | Corps de requête trop volumineux | Content-Length dépasse 10 Mo |
| 415 | Type de média non pris en charge | Content-Type de POST/PUT n'est ni JSON ni un téléversement de fichier |
| 422 | Échec de validation des paramètres | Champs obligatoires manquants, format incorrect ou validation métier échouée |
| 429 | Trop de requêtes | RateLimit déclenché / verrouillage du compte (5 échecs de connexion consécutifs verrouillent 15 minutes) |
| 500 | Erreur interne du serveur | |

## 3. Points d'accès publics

Tous les points d'accès publics sont montés sous le groupe `/api` ; le middleware `ApiVersion` les achemine vers le contrôleur versionné correspondant à l'en-tête `API-Version` (par ex. `app\api\v1\controller\AuthController`).

### 3.1 Contrôle de santé

```
GET /health
```

- **Authentification** : aucune requise
- **Limite de débit** : aucune

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "app": "open-admin",
    "version": "1.0",
    "php": "8.3.0",
    "database": "ok",
    "redis": "ok",
    "elasticsearch": "ok",
    "timestamp": 1716249600
  }
}
```

Valeurs de `database`, `redis`, `elasticsearch` : `"ok"` | `"unavailable"`. `elasticsearch` renvoie `"unavailable"` si ES est injoignable ; si l'état de santé du cluster n'est pas green/yellow, la valeur réelle du statut est renvoyée (par ex. `"red"`).

### 3.2 Documentation de l'API

```
GET /api/docs
```

- **Authentification** : aucune requise
- **Limite de débit** : défaut global (60/min)
- **Réponse** : spécification JSON OpenAPI 3.0.3, incluant toutes les définitions de points d'accès, paramètres et schémas

### 3.3 Générer un captcha

```
POST /api/captcha/generate
```

- **Authentification** : aucune requise
- **En-tête de requête** : `API-Version: v1` (obligatoire)
- **Limite de débit** : défaut global (60/min)

**Corps de la requête** :
```json
{
  "difficulty": "medium"
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| difficulty | string | Non | `easy` / `medium` / `hard`, défaut `medium` |

**Exemple de réponse** — type clic (`type: "click"`) :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "abc123def456",
    "type": "click",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "targets": [
        { "order": 1, "text": "A", "x": 120, "y": 85 },
        { "order": 2, "text": "B", "x": 310, "y": 42 }
      ]
    }
  }
}
```

**Exemple de réponse** — type curseur (`type: "slider"`) :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "def456abc789",
    "type": "slider",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "x": 120,
      "y": 60,
      "puzzle_w": 50,
      "puzzle_h": 50,
      "puzzle": "data:image/png;base64,iVBORw0KGgo..."
    }
  }
}
```

**Exemple de réponse** — type rotation (`type: "rotate"`) :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "ghi789abc012",
    "type": "rotate",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "angle": 45
    }
  }
}
```

| Champ | Type | Description |
|------|------|------|
| key | string | Identifiant du captcha, renvoyé lors de la vérification |
| type | string | Type de captcha : `click` / `slider` / `rotate` |
| image | string | Image en data URI base64 |
| extra | object | Données supplémentaires selon le type (voir ci-dessous) |

**`extra` selon le type** :

| type | champs extra | Type | Description |
|------|-----------|------|------|
| click | targets | array | Cibles de clic, contenant `order` (ordre) `text` (texte indicatif) `x` `y` (coordonnées) |
| slider | x, y | int | Coordonnées du coin supérieur gauche de l'encoche (sur un canevas de 300×200) |
| slider | puzzle_w, puzzle_h | int | Largeur et hauteur de l'image du puzzle |
| slider | puzzle | string | Image du puzzle en data URI base64 |
| rotate | angle | int | Angle de rotation correct (0-359) ; il faut tourner de `360-angle` pour redresser l'image |

### 3.4 Vérifier un captcha

```
POST /api/captcha/verify
```

- **Authentification** : aucune requise
- **En-tête de requête** : `API-Version: v1` (obligatoire)
- **Limite de débit** : défaut global (60/min)

**Corps de la requête** — type clic (`type: "click"`) :
```json
{
  "key": "abc123def456",
  "type": "click",
  "clicks": [
    { "x": 120, "y": 85 },
    { "x": 310, "y": 42 }
  ]
}
```

**Corps de la requête** — type curseur (`type: "slider"`) :
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**Corps de la requête** — type rotation (`type: "rotate"`) :
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| key | string | Oui | Clé du captcha, renvoyée par generate |
| type | string | Oui | Type de captcha, doit correspondre au `type` renvoyé par generate |
| clicks | variante | Oui | Données de réponse, le format varie selon le type (voir ci-dessous) |

**`clicks` selon le type** :

| type | type de clicks | Description | Tolérance d'erreur |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | Tableau de coordonnées de clic, dans l'ordre order | rayon 18 px |
| slider | `int` | Décalage du curseur sur l'axe X | ±4 px |
| rotate | `int` | Angle de rotation (0-359) | ±5° |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

Après vérification réussie, le backend écrit `captcha_verified:{key}` dans Redis (TTL 300 s), ce qui permet au point d'accès de connexion de laisser passer la requête.
En cas d'échec de vérification, `code` vaut 422, `message` vaut `"验证失败，请重试"` et `data.valid` vaut `false`.

### 3.5 Connexion

```
POST /api/auth/login
```

- **Authentification** : aucune requise
- **En-tête de requête** : `API-Version: v1` (obligatoire)
- **Limite de débit** : 10/min (par IP + chemin)

**Corps de la requête** :
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| Champ | Type | Obligatoire | Règle de validation | Description |
|------|------|------|---------|------|
| username | string | Oui | min:3, max:50 | Nom d'utilisateur |
| password | string | Oui | min:6, max:32 (texte en clair) | Chiffré AES-256-CBC-HMAC puis encodé en Base64 (texte en clair également compatible) |
| captcha_key | string | Oui | | Clé du captcha (doit d'abord être vérifiée via `/api/captcha/verify`) |

### Protocole de chiffrement du mot de passe

Utilise le **chiffrement asymétrique RSA-2048** ; la clé publique se trouve dans le code du frontend (peut être exposée en toute sécurité), la clé privée n'est détenue que par le serveur.

```
Flux de chiffrement (client) :
  Clé publique RSA (PEM) → chiffrement PKCS1v1.5 → encodage Base64 → transmission

Flux de déchiffrement (serveur, repli progressif) :
  1. Déchiffrement avec la clé privée RSA → succès et UTF-8 valide → utiliser le résultat déchiffré
  2. Déchiffrement AES-256-CBC-HMAC → succès → utiliser le résultat déchiffré (compatibilité avec les anciens clients)
  3. Repli en clair → utiliser directement l'entrée brute
```

La clé publique est intégrée dans l'application frontend et n'a pas besoin d'être transmise sur le réseau. La clé privée n'est stockée que dans `RSA_PRIVATE_KEY` dans `.env` et ne doit jamais fuiter.

> Le chiffrement symétrique AES est un schéma de compatibilité avec les anciennes versions ; il sera supprimé une fois tous les clients migrés vers RSA.

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "a1b2c3d4",
      "username": "admin",
      "real_name": "管理员"
    }
  }
}
```

| Champ | Type | Description |
|------|------|------|
| access_token | string | Jeton d'accès JWT |
| refresh_token | string | Jeton de rafraîchissement JWT |
| expires_in | int | Durée de validité du jeton d'accès (secondes), défaut 7200 |
| user.id | string | ID utilisateur chiffré par hashid |
| user.username | string | Nom d'utilisateur |
| user.real_name | string | Nom réel |

**Erreurs possibles** :
- 422 : Échec de validation des paramètres (champs obligatoires manquants, format incorrect)
- 422 : Veuillez d'abord terminer la vérification du captcha (captcha_key n'a pas passé `/api/captcha/verify`)
- 401 : Nom d'utilisateur ou mot de passe incorrect
- 403 : Compte désactivé
- 429 : Compte verrouillé, réessayez dans 15 minutes (déclenché par 5 échecs de connexion consécutifs)

### 3.6 Inscription

```
POST /api/auth/register
```

- **Authentification** : aucune requise
- **En-tête de requête** : `API-Version: v1` (obligatoire)
- **Limite de débit** : 5/min (par IP + chemin)

**Corps de la requête** :
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| Champ | Type | Obligatoire | Règle de validation | Description |
|------|------|------|---------|------|
| username | string | Oui | min:3, max:50 | Nom d'utilisateur (unique) |
| password | string | Oui | min:6, max:32 (texte en clair) | Chiffré AES-256-CBC-HMAC puis encodé en Base64 |
| real_name | string | Oui | max:50 | Nom réel |
| captcha_key | string | Oui | | Clé du captcha (doit d'abord être vérifiée via `/api/captcha/verify`) |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "注册成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "e5f6g7h8",
      "username": "newuser",
      "real_name": "新用户"
    }
  }
}
```

Les jetons JWT sont renvoyés directement après une inscription réussie ; le statut de l'utilisateur est activé par défaut (status=1).

### 3.7 Rafraîchir le jeton

```
POST /api/auth/refresh
```

- **Authentification** : aucune requise
- **En-tête de requête** : `API-Version: v1` (obligatoire)
- **Limite de débit** : défaut global (60/min)

**Corps de la requête** :
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| refresh_token | string | Oui | refresh_token obtenu lors de la connexion/inscription |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200
  }
}
```

Un rafraîchissement réussi renvoie simultanément de nouveaux access_token et refresh_token ; les anciens jetons sont invalidés automatiquement. La dernière connexion (heure et IP) de l'utilisateur est mise à jour lors du rafraîchissement.

**Erreurs possibles** :
- 422 : Jeton de rafraîchissement manquant
- 401 : Jeton de rafraîchissement invalide ou expiré

### 3.8 Métriques Prometheus

```
GET /metrics
```

- **Authentification** : aucune requise
- **Limite de débit** : aucune
- **Format de réponse** : format texte Prometheus (`text/plain; version=0.0.4`)

Point d'accès public des métriques Prometheus pour le scraping par Grafana/Prometheus.

**Exemple de réponse** :
```
# HELP openadmin_http_requests_total Total number of HTTP requests.
# TYPE openadmin_http_requests_total gauge
openadmin_http_requests_total 15234

# HELP openadmin_active_users Number of active users.
# TYPE openadmin_active_users gauge
openadmin_active_users 156

# HELP openadmin_db_connection_status Database connection status (1=ok, 0=fail).
# TYPE openadmin_db_connection_status gauge
openadmin_db_connection_status 1

# HELP openadmin_redis_connection_status Redis connection status (1=ok, 0=fail).
# TYPE openadmin_redis_connection_status gauge
openadmin_redis_connection_status 1

# HELP openadmin_memory_usage_bytes Memory usage in bytes.
# TYPE openadmin_memory_usage_bytes gauge
openadmin_memory_usage_bytes 18874368
```

| Nom de la métrique | Type | Description |
|------|------|------|
| `openadmin_http_requests_total` | gauge | Nombre total cumulé de requêtes HTTP |
| `openadmin_active_users` | gauge | Utilisateurs actifs actuels (connectés dans les 24 heures) |
| `openadmin_db_connection_status` | gauge | État de la connexion à la base de données, 1=ok, 0=erreur |
| `openadmin_redis_connection_status` | gauge | État de la connexion Redis, 1=ok, 0=erreur |
| `openadmin_memory_usage_bytes` | gauge | Utilisation mémoire actuelle du processus PHP (octets) |

## 4. Tableau de bord

Tous les points d'accès d'administration sont montés sous le groupe `/admin` et passent par trois middlewares : `AdminAuth` (authentification JWT), `AdminPermission` (vérification des droits RBAC) et `OperationLog` (journalisation des opérations).

### 4.1 Données du tableau de bord

```
GET /admin/dashboard
```

- **Authentification** : JWT + RBAC
- **Cache** : Redis 5 minutes

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "stats": [
      {
        "label": "用户总数",
        "value": "1280",
        "icon": "people",
        "color": "#1677FF",
        "trend": 12.5
      },
      {
        "label": "今日新增",
        "value": "23",
        "icon": "person_add",
        "color": "#52C41A"
      },
      {
        "label": "活跃用户",
        "value": "156",
        "icon": "bolt",
        "color": "#FA8C16"
      },
      {
        "label": "操作日志",
        "value": "432",
        "icon": "description",
        "color": "#722ED1"
      }
    ],
    "trends": {
      "dates": ["2026-04-22", "2026-04-23", "..."],
      "series": [
        { "name": "累计用户", "data": [1200, 1210, "..."], "color": "#1677FF" },
        { "name": "操作日志", "data": [45, 52, "..."], "color": "#52C41A" }
      ]
    },
    "distribution": {
      "user_status": [
        { "name": "启用", "value": 1250 },
        { "name": "禁用", "value": 30 }
      ]
    },
    "recent_logs": [
      {
        "id": "hashid...",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "user_name": "admin",
        "created_at": "2026-05-21 10:30:00"
      }
    ]
  }
}
```

| champs stats | Type | Description |
|------|------|------|
| label | string | Nom de la métrique |
| value | string | Valeur de la métrique (type chaîne) |
| icon | string | Nom de l'icône Material |
| color | string | Couleur de la carte |
| trend | float? | Taux de croissance jour sur jour (pourcentage) ; seul "total utilisateurs" possède ce champ |

| champs trends | Type | Description |
|------|------|------|
| dates | array{string} | Séquence de dates des 30 derniers jours |
| series | array{object} | Données de la ligne de tendance : name (nom), data (tableau de valeurs), color (couleur) |

## 5. Gestion des utilisateurs

Le `id` renvoyé par tous les points d'accès de gestion des utilisateurs est une chaîne chiffrée par hashid. Les champs de mot de passe sont exclus des réponses. Les numéros de téléphone et e-mails sont masqués dans les listes et renvoyés en clair dans les détails (les champs chiffrés de la base sont déchiffrés automatiquement par le trait Encryptable).

### 5.1 Liste des utilisateurs

```
GET /admin/user
```

- **Authentification** : JWT + RBAC

**Paramètres de requête** :

| Paramètre | Type | Obligatoire | Défaut | Description |
|------|------|------|------|------|
| page | int | Non | 1 | Numéro de page |
| limit | int | Non | 15 | Éléments par page |
| keyword | string | Non | | Mot-clé de recherche, correspond au nom d'utilisateur et au nom réel |
| status | int | Non | | Filtre de statut, 0=désactivé, 1=activé |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "a1b2c3d4",
        "username": "admin",
        "real_name": "管理员",
        "phone": "138****5678",
        "email": "a***@example.com",
        "status": 1,
        "last_login_at": "2026-05-21 10:30:00",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 100,
    "page": 1,
    "limit": 15
  }
}
```

| Champ | Type | Description |
|------|------|------|
| id | string | ID utilisateur chiffré par hashid |
| username | string | Nom d'utilisateur |
| real_name | string | Nom réel |
| phone | string | Numéro de téléphone masqué (format `138****5678`) |
| email | string | E-mail masqué (format `a***@example.com`) |
| status | int | 1=activé, 0=désactivé |
| last_login_at | string | Dernière connexion (datetime) |
| created_at | string | Date de création (datetime) |

### 5.2 Créer un utilisateur

```
POST /admin/user
```

- **Authentification** : JWT + RBAC

**Corps de la requête** :
```json
{
  "username": "newuser",
  "password": "123456",
  "real_name": "新用户",
  "phone": "13800138000",
  "email": "newuser@example.com",
  "status": 1
}
```

| Champ | Type | Obligatoire | Règle de validation | Description |
|------|------|------|---------|------|
| username | string | Oui | min:3, max:50 | Nom d'utilisateur (unique) |
| password | string | Oui | min:6, max:32 | Mot de passe (stocké avec bcrypt) |
| real_name | string | Oui | max:50 | Nom réel |
| phone | string | Non | | Numéro de téléphone (chiffré avec Encryptable) |
| email | string | Non | | E-mail (chiffré avec Encryptable) |
| status | int | Non | in:0,1 | Statut, défaut 1 (activé) |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "e5f6g7h8",
    "username": "newuser",
    "real_name": "新用户",
    "phone": "13800138000",
    "email": "newuser@example.com",
    "status": 1,
    "created_at": "2026-05-21 12:00:00"
  }
}
```

**Erreurs possibles** :
- 422 : Nom d'utilisateur déjà existant
- 422 : Échec de validation des paramètres (champs obligatoires manquants)

### 5.3 Détail d'un utilisateur

```
GET /admin/user/{id}
```

- **Authentification** : JWT + RBAC
- **Paramètre de chemin** : `{id}` est l'ID utilisateur chiffré par hashid

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "管理员",
    "phone": "13800138000",
    "email": "admin@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00",
    "last_login_ip": "192.168.1.1",
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-05-20 08:00:00"
  }
}
```

Dans le détail, `phone` et `email` sont renvoyés en clair (stockés chiffrés en base, déchiffrés automatiquement par le cast Encryptable) et ne sont pas masqués. `password` et `id_card` ne figurent jamais dans la réponse.

**Erreurs possibles** :
- 404 : Utilisateur introuvable

### 5.4 Mettre à jour un utilisateur

```
PUT /admin/user/{id}
```

- **Authentification** : JWT + RBAC
- **Paramètre de chemin** : `{id}` est l'ID utilisateur chiffré par hashid

**Corps de la requête** :
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| real_name | string | Non | Nom réel ; conserve la valeur d'origine si non fourni |
| password | string | Non | Nouveau mot de passe ; inchangé si chaîne vide ou omis |
| phone | string | Non | Numéro de téléphone |
| email | string | Non | E-mail |
| status | int | Non | 0=désactivé, 1=activé |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13900139000",
    "email": "new@example.com",
    "status": 1,
    "updated_at": "2026-05-21 12:30:00"
  }
}
```

**Erreurs possibles** :
- 404 : Utilisateur introuvable

### 5.5 Supprimer un utilisateur

```
DELETE /admin/user/{id}
```

- **Authentification** : JWT + RBAC
- **Paramètre de chemin** : `{id}` est l'ID utilisateur chiffré par hashid
- **Opération sensible** : confirmation par mot de passe requise

**Corps de la requête** :
```json
{
  "password": "admin_password"
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| password | string | Oui | Mot de passe de l'utilisateur connecté (confirmation) |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Effectue une suppression douce (Eloquent SoftDeletes) ; les données sont marquées deleted_at sans suppression physique.

**Erreurs possibles** :
- 404 : Utilisateur introuvable
- 422 : Les opérations sensibles exigent une confirmation par mot de passe (password vide)
- 422 : Échec de la vérification du mot de passe (mot de passe incorrect)

### 5.6 Suppression groupée d'utilisateurs

```
POST /admin/user/batch/destroy
```

- **Authentification** : JWT + RBAC
- **Opération sensible** : confirmation par mot de passe requise

**Corps de la requête** :
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| ids | array{string} | Oui | Tableau d'ID utilisateurs chiffrés par hashid |
| password | string | Oui | Mot de passe de l'utilisateur connecté (confirmation) |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

Effectue une suppression douce ; `data.count` est le nombre réellement supprimé.

**Erreurs possibles** :
- 422 : Veuillez sélectionner des utilisateurs à supprimer (ids vide)
- 422 : ID invalide (échec du décodage hashid)
- 422 : Échec de la vérification du mot de passe

### 5.7 Activation/désactivation groupée d'utilisateurs

```
POST /admin/user/batch/status
```

- **Authentification** : JWT + RBAC

**Corps de la requête** :
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| ids | array{string} | Oui | Tableau d'ID utilisateurs chiffrés par hashid |
| status | int | Oui | 0=désactivé, 1=activé |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

message change dynamiquement selon status : `"批量启用成功"` ou `"批量禁用成功"`.

**Erreurs possibles** :
- 422 : Veuillez sélectionner des utilisateurs (ids vide)
- 422 : Valeur de statut invalide (status n'est ni 0 ni 1)

## 6. Gestion des rôles

### 6.1 Liste des rôles

```
GET /admin/role
```

- **Authentification** : JWT + RBAC

**Paramètres de requête** :

| Paramètre | Type | Obligatoire | Défaut | Description |
|------|------|------|------|------|
| page | int | Non | 1 | Numéro de page |
| limit | int | Non | 15 | Éléments par page |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "r1r2r3r4",
        "name": "超级管理员",
        "slug": "super_admin",
        "description": "拥有所有权限",
        "status": 1,
        "users_count": 3,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 5,
    "page": 1,
    "limit": 15
  }
}
```

| Champ | Type | Description |
|------|------|------|
| id | string | ID de rôle chiffré par hashid |
| name | string | Nom du rôle |
| slug | string | Identifiant du rôle (unique, utilisé pour les vérifications de droits) |
| description | string | Description du rôle |
| status | int | 1=activé, 0=désactivé |
| users_count | int | Nombre d'utilisateurs possédant ce rôle |

### 6.2 Créer un rôle

```
POST /admin/role
```

- **Authentification** : JWT + RBAC

**Corps de la requête** :
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| Champ | Type | Obligatoire | Règle de validation | Description |
|------|------|------|---------|------|
| name | string | Oui | max:50 | Nom du rôle |
| slug | string | Oui | max:50 | Identifiant du rôle |
| description | string | Non | | Description du rôle, défaut chaîne vide |
| status | int | Non | | Statut, défaut 1 |
| permission_ids | array{int} | Non | | Tableau d'ID de permissions (ID INT bruts, pas des hashids) |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "编辑员",
    "slug": "editor",
    "description": "内容编辑角色",
    "status": 1
  }
}
```

### 6.3 Mettre à jour un rôle

```
PUT /admin/role/{id}
```

- **Authentification** : JWT + RBAC

**Corps de la requête** :
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| name | string | Non | Nom du rôle |
| description | string | Non | Description |
| status | int | Non | 0=désactivé, 1=activé |
| permission_ids | array{int} | Non | Tableau d'ID de permissions ; s'il est fourni, les droits du rôle sont synchronisés (écrasés) |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "内容编辑",
    "slug": "editor",
    "description": "更新后的描述",
    "status": 1
  }
}
```

### 6.4 Supprimer un rôle

```
DELETE /admin/role/{id}
```

- **Authentification** : JWT + RBAC
- **Opération sensible** : confirmation par mot de passe requise

**Corps de la requête** :
```json
{
  "password": "admin_password"
}
```

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

À la suppression, les associations du rôle avec toutes les permissions et utilisateurs sont automatiquement levées, puis l'enregistrement du rôle est physiquement supprimé.

## 7. Gestion des permissions

Les permissions utilisent une structure arborescente (auto-référence parent_id) et se divisent en trois types. Le point d'accès de liste renvoie l'arbre complet des permissions.

### 7.1 Arbre des permissions

```
GET /admin/permission
```

- **Authentification** : JWT + RBAC

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "id": "p1p2p3p4",
      "parent_id": "0",
      "name": "用户管理",
      "slug": "/admin/user",
      "type": 1,
      "icon": "people",
      "path": "/user",
      "sort": 1,
      "created_at": "2026-01-01 00:00:00",
      "children": [
        {
          "id": "p5p6p7p8",
          "parent_id": "p1p2p3p4",
          "name": "用户列表",
          "slug": "/admin/user/index",
          "type": 2,
          "icon": "",
          "path": "/user/index",
          "sort": 1
        }
      ]
    }
  ]
}
```

| Champ | Type | Description |
|------|------|------|
| id | string | Chiffré par hashid |
| parent_id | string | hashid de la permission parente ; "0" représente le nœud racine |
| name | string | Nom de la permission |
| slug | string | Identifiant de la permission (route/bouton) |
| type | int | 1=menu, 2=bouton, 3=API |
| icon | string | Icône de menu (nom d'icône Material) |
| path | string | Chemin de route du frontend |
| sort | int | Valeur de tri (croissant) |
| children | array? | Liste des permissions enfants (récursive) ; absente s'il n'y a pas de nœuds enfants |

### 7.2 Créer une permission

```
POST /admin/permission
```

- **Authentification** : JWT + RBAC

**Corps de la requête** :
```json
{
  "parent_id": 0,
  "name": "系统设置",
  "slug": "/admin/config",
  "type": 1,
  "icon": "settings",
  "path": "/config",
  "sort": 99
}
```

| Champ | Type | Obligatoire | Règle de validation | Description |
|------|------|------|---------|------|
| parent_id | int | Non | | ID de la permission parente (type INT brut), défaut 0 |
| name | string | Oui | max:50 | Nom de la permission |
| slug | string | Oui | max:100 | Identifiant de la permission |
| type | int | Oui | in:1,2,3 | 1=menu, 2=bouton, 3=API |
| icon | string | Non | | Icône de menu, défaut vide |
| path | string | Non | | Chemin de route du frontend, défaut vide |
| sort | int | Non | | Valeur de tri, défaut 0 |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "p9p0a1b2",
    "parent_id": "0",
    "name": "系统设置",
    "slug": "/admin/config",
    "type": 1,
    "icon": "settings",
    "path": "/config",
    "sort": 99
  }
}
```

### 7.3 Mettre à jour une permission

```
PUT /admin/permission/{id}
```

- **Authentification** : JWT + RBAC

**Corps de la requête** :
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| name | string | Non | Nom de la permission |
| icon | string | Non | Icône |
| path | string | Non | Chemin de route |
| sort | int | Non | Valeur de tri |

### 7.4 Supprimer une permission

```
DELETE /admin/permission/{id}
```

- **Authentification** : JWT + RBAC
- **Opération sensible** : confirmation par mot de passe requise

**Corps de la requête** :
```json
{
  "password": "admin_password"
}
```

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

À la suppression, toutes les permissions enfants sont supprimées en cascade (enregistrements dont `parent_id` vaut l'ID de la permission courante) et les associations avec tous les rôles sont levées.

## 8. Configuration système

Les configurations système sont uniques par la combinaison `group` + `key`.

### 8.1 Liste des configurations

```
GET /admin/config
```

- **Authentification** : JWT + RBAC

**Paramètres de requête** :

| Paramètre | Type | Obligatoire | Défaut | Description |
|------|------|------|------|------|
| page | int | Non | 1 | Numéro de page |
| limit | int | Non | 15 | Éléments par page |
| group | string | Non | | Filtrer par groupe de configuration |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "c1c2c3c4",
        "group": "system",
        "key": "site_name",
        "value": "开放管理后台",
        "type": "string",
        "description": "站点名称",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 20,
    "page": 1,
    "limit": 15
  }
}
```

| Champ | Type | Description |
|------|------|------|
| id | string | hashid |
| group | string | Groupe de configuration (par ex. `system`, `email`, `storage`) |
| key | string | Clé de configuration |
| value | string | Valeur de configuration |
| type | string | Indication du type de valeur (`string`, `integer`, `boolean`, `json`, etc.) |
| description | string | Description de la configuration |

### 8.2 Créer une configuration

```
POST /admin/config
```

- **Authentification** : JWT + RBAC

**Corps de la requête** :
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| Champ | Type | Obligatoire | Règle de validation | Description |
|------|------|------|---------|------|
| group | string | Oui | max:100 | Groupe de configuration |
| key | string | Oui | max:100 | Clé de configuration (unique dans le même groupe) |
| value | string | Oui | | Valeur de configuration |
| type | string | Non | | Type de valeur, défaut `string` |
| description | string | Non | | Description de la configuration, défaut vide |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "c5c6c7c8",
    "group": "email",
    "key": "smtp_host",
    "value": "smtp.example.com",
    "type": "string",
    "description": "SMTP 服务器地址"
  }
}
```

**Erreurs possibles** :
- 422 : L'élément de configuration existe déjà (même group + key)

### 8.3 Mettre à jour une configuration

```
PUT /admin/config/{id}
```

- **Authentification** : JWT + RBAC

**Corps de la requête** :
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| value | string | Non | Mettre à jour la valeur de configuration |
| type | string | Non | Mettre à jour le type de valeur |
| description | string | Non | Mettre à jour le texte de description |

### 8.4 Supprimer une configuration

```
DELETE /admin/config/{id}
```

- **Authentification** : JWT + RBAC
- **Opération sensible** : confirmation par mot de passe requise

**Corps de la requête** :
```json
{
  "password": "admin_password"
}
```

Supprime physiquement l'enregistrement de configuration.

## 9. Journaux d'opérations

Les journaux d'opérations sont en lecture seule ; le middleware `OperationLog` écrit automatiquement à chaque requête POST/PUT/DELETE. Les champs stockés incluent `user_id`, `action`, `method`, `path`, `ip`, `source`, `input`.

### 9.1 Liste des journaux d'opérations

```
GET /admin/log
```

- **Authentification** : JWT + RBAC

**Paramètres de requête** :

| Paramètre | Type | Obligatoire | Défaut | Description |
|------|------|------|------|------|
| page | int | Non | 1 | Numéro de page |
| limit | int | Non | 15 | Éléments par page |
| user_id | int | Non | | Filtre exact par ID utilisateur (type INT brut) |
| action | string | Non | | Filtre exact par action |
| path | string | Non | | Filtre flou par chemin de requête |
| start_date | string | Non | | Date de début (format Y-m-d) |
| end_date | string | Non | | Date de fin (format Y-m-d) |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "l1l2l3l4",
        "user_name": "admin",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "source": "web",
        "input": "{\"username\":\"admin\"}",
        "created_at": "2026-05-21 10:30:00"
      }
    ],
    "total": 500,
    "page": 1,
    "limit": 15
  }
}
```

| Champ | Type | Description |
|------|------|------|
| id | string | hashid |
| user_name | string | Nom d'utilisateur de l'action (via la relation user ; les opérations non authentifiées affichent "系统") |
| action | string | Description de l'action |
| method | string | Méthode HTTP (POST/PUT/DELETE) |
| path | string | Chemin de requête |
| ip | string | IP du client |
| source | string | Source de la requête |
| input | string | Paramètres de requête sous forme de chaîne JSON (sans fichiers) |
| created_at | string | Heure de l'opération (datetime) |

## 10. Profil personnel

Les points d'accès du profil ne nécessitent que l'authentification JWT (pas de vérification RBAC — le middleware `AdminPermission` doit les ajouter à la liste blanche).

### 10.1 Mettre à jour les informations personnelles

```
PUT /admin/profile
```

- **Authentification** : JWT

**Corps de la requête** :
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| real_name | string | Non | Nom réel |
| phone | string | Non | Numéro de téléphone (chiffré avec Encryptable) |
| email | string | Non | E-mail (chiffré avec Encryptable) |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13800138000",
    "email": "me@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00"
  }
}
```

Dans la réponse, `phone` et `email` sont renvoyés en clair ; `password` et `id_card` sont retirés.

### 10.2 Changer le mot de passe

```
PUT /admin/profile/password
```

- **Authentification** : JWT

**Corps de la requête** :
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| Champ | Type | Obligatoire | Règle de validation | Description |
|------|------|------|---------|------|
| old_password | string | Oui | | Mot de passe actuel |
| new_password | string | Oui | min:6, max:32 | Nouveau mot de passe |

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**Erreurs possibles** :
- 422 : Veuillez fournir l'ancien et le nouveau mot de passe
- 422 : Ancien mot de passe incorrect
- 422 : Le nouveau mot de passe doit faire 6 à 32 caractères

### 10.3 Déconnexion

```
POST /admin/profile/logout
```

- **Authentification** : JWT

**Corps de la requête** : aucun (pas de requestBody ; le jeton est lu depuis l'en-tête Authorization)

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

Logique de déconnexion : décoder le JWT pour obtenir la validité restante (exp - now), écrire le hash md5 du jeton dans la liste noire Redis `jwt_blacklist:{md5}` avec TTL = validité restante. Les jetons de la liste noire sont bloqués par le middleware `AdminAuth` avec retour 401.

Renvoie 401 en l'absence de jeton. Les jetons expirés/invalides (le décodage lève une exception) sont toujours considérés comme une déconnexion réussie.

## 11. Import et export

### 11.1 Exporter en Excel

```
POST /admin/export/excel
```

- **Authentification** : JWT + RBAC
- **Type de réponse** : téléchargement de fichier (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Corps de la requête** :
```json
{
  "table": "admin_user",
  "columns": ["username", "real_name", "phone", "status", "created_at"],
  "conditions": {
    "status": 1
  },
  "title": "用户列表导出"
}
```

| Champ | Type | Obligatoire | Défaut | Description |
|------|------|------|------|------|
| table | string | Non | `admin_user` | Table à exporter. Pris en charge : `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | Non | | Tableau des noms de colonnes à exporter ; vide exporte toutes les colonnes de la table |
| conditions | object | Non | `{}` | Conditions de filtrage, paires clé-valeur ; les valeurs non vides sont utilisées dans WHERE |
| title | string | Non | `数据导出` | Titre Excel (affiché comme nom de feuille) |

**Tables et colonnes prises en charge** :

| table | Colonnes disponibles |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

Les champs sensibles `phone`, `email`, `id_card` sont automatiquement masqués lors de l'export. Plafond de données : 10 000 lignes. La première ligne Excel est figée et le filtre automatique est activé.

### 11.2 Exporter en PDF

```
POST /admin/export/pdf
```

- **Authentification** : JWT + RBAC
- **Type de réponse** : téléchargement de fichier (`application/pdf`, A4 paysage)

**Corps de la requête** :
```json
{
  "type": "dashboard",
  "title": "管理仪表盘报告",
  "data": {
    "stats": [
      { "label": "用户总数", "value": "1280" }
    ]
  }
}
```

Ou mode tableau :
```json
{
  "type": "table",
  "title": "用户列表",
  "data": {
    "columns": ["用户名", "真实姓名", "状态"],
    "rows": [
      ["admin", "管理员", "启用"],
      ["editor", "编辑员", "启用"]
    ]
  }
}
```

| Champ | Type | Obligatoire | Défaut | Description |
|------|------|------|------|------|
| type | string | Non | `table` | Type d'export : `table` / `dashboard` |
| title | string | Non | `数据导出` | Titre du PDF |
| data | object | Non | `{}` | Données à exporter |

Avec `type=dashboard`, `data` doit contenir un tableau `stats` (rendu sous forme de cartes) ; avec `type=table`, `data` doit contenir les tableaux `columns` et `rows`.

Le modèle PDF inclut les informations de copyright et un horodatage d'export.

### 11.3 Importer des utilisateurs (Excel)

```
POST /admin/import/users
```

- **Authentification** : JWT + RBAC
- **Type de requête** : `multipart/form-data` (téléversement de fichier)

**Champs de formulaire** :

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| file | file | Oui | Format `.xlsx` ou `.xls` |

**Exigences sur les colonnes Excel** :

| Nom de colonne | Obligatoire | Description |
|------|------|------|
| username | Oui | Nom d'utilisateur (unique) |
| password | Oui | Mot de passe (stocké en hash bcrypt) |
| real_name | Oui | Nom réel |
| phone | Non | Numéro de téléphone |
| email | Non | E-mail |
| status | Non | Statut, défaut 1 |

La ligne 1 est l'en-tête de colonne (insensible à la casse) ; les données commencent à la ligne 2.

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "导入完成",
  "data": {
    "total": 10,
    "success": 8,
    "failed": 2,
    "errors": [
      { "row": 3, "reason": "用户名为空" },
      { "row": 7, "reason": "用户名 zhangsan 已存在" }
    ]
  }
}
```

| Champ | Type | Description |
|------|------|------|
| total | int | Nombre total de lignes (hors ligne d'en-tête) |
| success | int | Nombre importé avec succès |
| failed | int | Nombre d'échecs |
| errors | array | Détails des échecs : row (numéro de ligne Excel) et reason (cause) |

## 12. Téléversement de fichiers

```
POST /admin/upload
```

- **Authentification** : JWT + RBAC
- **Type de requête** : `multipart/form-data`

**Champs de formulaire** :

| Champ | Type | Obligatoire | Description |
|------|------|------|------|
| file | file | Oui | Le fichier à téléverser |

**Types de fichiers autorisés** : `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**Taille maximale du fichier** : 10 Mo

**Exemple de réponse** :
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

Les fichiers sont stockés dans des répertoires datés `public/upload/{Y-m-d}/` avec un nom de fichier `md5(uniqid) + extension d'origine`. `url` est un chemin relatif à la racine du site.

**Erreurs possibles** :
- 422 : Veuillez sélectionner un fichier (aucun téléversé)
- 422 : Type de fichier non pris en charge
- 422 : La taille du fichier ne peut pas dépasser 10 Mo
- 500 : Échec du téléversement (fichier invalide)

## 13. En-têtes de réponse

Tous les points d'accès (injectés au niveau du middleware global) incluent les en-têtes de réponse suivants :

| En-tête | Description |
|----|------|
| `X-RateLimit-Limit` | Plafond de débit (nombre) |
| `X-RateLimit-Remaining` | Nombre de requêtes restant |
| `X-RateLimit-Reset` | Horodatage de réinitialisation de la fenêtre de débit |
| `Retry-After` | Renvoyé uniquement en cas de limitation ; secondes d'attente recommandées |
| `X-Content-Type-Options` | `nosniff` (défaut webman, désactive le reniflage MIME) |
| `X-Frame-Options` | `DENY` (fourni par le middleware CORS/la configuration de base de webman) |

Détails de la limitation de débit :
- Limite globale par défaut : 60/min / IP+chemin
- Point d'accès de connexion `/api/auth/login` : 10/min
- Point d'accès d'inscription `/api/auth/register` : 5/min
- Utilise l'algorithme atomique de fenêtre glissante de Redis (Lua ZSET), évitant les courses TOCTOU
- En cas d'indisponibilité de Redis, fail open (laisser passer), les requêtes ne sont pas bloquées

## 14. Flux d'authentification

La séquence d'authentification complète :

```
1. Le client demande POST /api/captcha/generate
   (En-tête de requête : API-Version: v1)
    ↓
   Le serveur renvoie : key + type(click|slider|rotate) + image base64 + extra(données selon le type)
   
2. L'utilisateur effectue l'action du captcha (clic/glisser/tourner), le client collecte la réponse
   
3. Le client demande POST /api/captcha/verify
   (En-tête de requête : API-Version: v1, Content-Type: application/json)
   Corps de la requête : { key, type, clicks }
   - type=click :  clicks = [{x, y}, ...]        // tableau de coordonnées
   - type=slider : clicks = 120                   // décalage X
   - type=rotate : clicks = 315                   // angle de rotation
    ↓
   Serveur :
   a. Lire les données captcha:key depuis le stockage (TTL 300 s)
   b. Valider la réponse selon le type (click : distance euclidienne ≤18 px / slider : ±4 px / rotate : ±5°)
   c. Validation réussie → écrire Redis `captcha_verified:{key}` = 1 (TTL 300 s)
   d. Validation échouée → renvoyer 422, compteur +1, key invalidée après 3 essais
    ↓
   Le serveur renvoie : { valid: true/false }

4. Le client demande POST /api/auth/login
   (En-tête de requête : API-Version: v1, Content-Type: application/json)
   Corps de la requête : { username, password(chiffré), captcha_key }
    ↓
   Serveur :
   a. Validation des paramètres → 422
   b. Vérifier l'existence de captcha_verified:{key} → 422
   c. Supprimer captcha_verified:{key} (usage unique)
   d. Déchiffrer le mot de passe : EncryptionService::decrypt(password) → texte en clair
   e. Valider les identifiants (password_verify) → 401
   f. Vérifier le statut du compte → 403/429
   g. Émettre le JWT (access + refresh) → 200
   h. Mettre à jour last_login_at / last_login_ip
    ↓
   Le client enregistre : access_token, refresh_token, expires_in

5. Les requêtes suivantes portent le JWT
   En-tête de requête : Authorization: Bearer <access_token>
    ↓
   Middleware AdminAuth :
   a. Extraire le jeton Bearer
   b. Vérifier la liste noire (Redis jwt_blacklist:{md5}) → 401
   c. Décoder le JWT, vérifier l'expiration → 401
   d. Définir $request->adminId = champ sub
    ↓
   Middleware AdminPermission :
   a. Résoudre l'identifiant de permission pour la route de la ressource
   b. Interroger les rôles de l'utilisateur → droits des rôles et mettre en correspondance
   c. Pas de permission → 403
    ↓
   Controller traite la requête
    ↓
   Response + en-têtes X-RateLimit-*

6. Rafraîchir avant l'expiration de l'access token
   Le client demande POST /api/auth/refresh
   Corps de la requête : { refresh_token: "..." }
    ↓
   Le serveur décode refresh_token → émet de nouveaux access + refresh
    ↓
   Le client met à jour ses jetons locaux

7. Déconnexion
   Le client demande POST /admin/profile/logout
   En-tête de requête : Authorization: Bearer <access_token>
    ↓
   Serveur :
   a. Décoder le JWT pour obtenir le TTL restant
   b. Écrire dans la liste noire Redis : jwt_blacklist:{md5(token)} = 1, TTL = validité restante
   c. Renvoyer le succès
```

### Structure du JWT

- **access_token** : `{ sub: <user_id>, username: "<name>" }`, TTL par défaut 7200 secondes (contrôlé par la config JWT `default_expire`)
- **refresh_token** : `{ sub: <user_id>, token_type: "refresh" }`, TTL par défaut 1209600 secondes (contrôlé par la config JWT `refresh_expire`, soit 14 jours)

### Gestion de la sécurité

- Les mots de passe sont stockés en hash `PASSWORD_BCRYPT`
- Les mots de passe sont chiffrés en transit avec AES-256-CBC-HMAC (le client chiffre → le serveur déchiffre), avec repli en clair
- Les champs sensibles (phone, email, id_card) sont chiffrés/déchiffrés de façon transparente au niveau de la base avec `erikwang2013/encryptable`
- Les ID au niveau de l'API sont transmis chiffrés avec `erikwang2013/hashids`, évitant d'exposer la séquence brute des ID snowflake
- SecurityFilter analyse globalement les XSS, injections SQL, traversées de chemin et injections de commandes ; même IP 5 fois/60 s → liste noire temporaire de 15 minutes
- Les opérations sensibles (suppression d'utilisateurs, rôles, permissions, configurations) exigent une confirmation par mot de passe de l'utilisateur connecté
- Limite de sessions simultanées : au plus 3 jetons valides par utilisateur ; lors de la connexion d'un 4e appareil, le jeton le plus ancien est forcé sur la liste noire
- Verrouillage de compte : 5 échecs de connexion consécutifs déclenchent un verrouillage de 15 minutes, pendant lequel 429 est renvoyé

## 15. Déploiement et exploitation

### Docker Compose

La racine du projet fournit `docker-compose.yml` orchestrant 5 services (Nginx, webman app, MySQL, Redis, Elasticsearch). PHP est construit via le `Dockerfile` (basé sur `php:8.3-cli`, avec OPcache activé).

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` définit le pipeline d'intégration continue GitHub Actions :
- Vérification de syntaxe `php -l`
- Tests unitaires PHPUnit
- Analyse statique `flutter analyze`

### Sauvegarde de la base de données

Le répertoire `database/backup/` fournit des scripts de sauvegarde et de restauration :
- `backup.sh` — sauvegardes compressées mysqldump + gzip, nettoie automatiquement les sauvegardes de plus de 30 jours
- `restore.sh` — restauration interactive avec liste des sauvegardes disponibles

### Configuration de sécurité Nginx

Pour le déploiement en production, reportez-vous à `docs/nginx-security.conf` pour le renforcement de la sécurité du proxy inverse.
