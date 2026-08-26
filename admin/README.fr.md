# Console d'administration ouverte (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Une console d'administration full-stack basée sur webman v2 + Flutter.

> [English version](README_EN.md) | [Diagrammes d'architecture](docs/ARCHITECTURE.md) | [Document de conception](docs/DESIGN.md) | [Architecture de sécurité](docs/SECURITY.md) | [Référence API](docs/API.md)

## Fonctionnalités

| Domaine | Fonction | Description |
|--------|------|------|
| 🔐 Authentification | Connexion / renouvellement du jeton / déconnexion | Captcha à clic + JWT + liste noire |
| | Verrouillage du compte | 5 échecs = verrouillage 15 minutes |
| | Limite de sessions simultanées | 3 jetons valides max par utilisateur |
| 📊 Tableau de bord | Statistiques temps réel / tendances / répartition / actions récentes | Cache Redis 5 minutes |
| 👥 Utilisateurs | CRUD + suppression groupée / activation-désactivation | Suppression douce + confirmation du mot de passe |
| | Import Excel groupé | Validation ligne par ligne + rapport d'erreurs |
| 🔒 Rôles & permissions | CRUD des rôles + arbre de permissions | Autorisation RBAC à la granularité method.path |
| ⚙ Configuration système | CRUD de paires clé-valeur | Gestion par groupes |
| 📋 Audit des opérations | Consultation des journaux + détection de la source | 8 plateformes détectées automatiquement |
| 📁 Fichiers | Upload / export Excel / export PDF | Masquage automatique des données sensibles |
| 🛡 Sécurité | Défense en profondeur sur 18 niveaux | XSS/injection SQL/traversée de chemin/injection de commande/CSRF/limitation de débit/CSP... |
| 🏥 Exploitation | Health check / metrics / documentation API / security.txt | Prometheus + OpenAPI 3.0 + documentation interactive hg/apidoc |
| 🌐 Internationalisation | Bascule chinois/anglais | En-tête Accept-Language / paramètre ?lang= |

## Stack technique

| Couche | Technologie | Description |
|---|------|------|
| Framework backend | webman v2 (workerman) | Framework PHP haute performance à processus résidents |
| Version PHP | 8.3+ | |
| Base de données | MySQL 8.0+ | Préfixe de table `erik_`, clés primaires BIGINT sans auto-incrément |
| Moteur de recherche | Elasticsearch | Synchronisation et recherche via `webman-scout` |
| Frontend admin | Flutter 3.x | Le Web rend en style console d'administration PC (`apps/flutter/`) |
| Mobile | HarmonyOS ArkTS | Client natif HarmonyOS (`apps/harmonyos/`), prend en charge téléphone/tablette/2en1 |

## Dépendances principales

| Paquet | Rôle |
|---|------|
| `erikwang2013/snowflake-php` | Génération de clés primaires BIGINT uniques via l'algorithme Snowflake |
| `erikwang2013/hashids` | Chiffrement des ID au niveau API, masque les ID réels de la base |
| `erikwang2013/jwt-webman` | Émission et vérification des jetons JWT |
| `erikwang2013/encryption` | Chiffrement des données sensibles au niveau transport |
| `erikwang2013/encryptable` | Chiffrement automatique des champs sensibles en base |
| `erikwang2013/webman-scout` | Synchronisation Elasticsearch et recherche plein texte |
| `erikwang2013/season` | Données de drapeaux de pays |
| `erikwang2013/poster-php` | Génération/vérification du captcha à clic + génération d'affiches |
| `phpoffice/phpspreadsheet` | Export Excel |
| `barryvdh/laravel-dompdf` | Export PDF (basé sur Dompdf) |

## Structure du projet

```
open-admin/
├── app/
│   ├── admin/controller/       # 管理端控制器
│   │   ├── DashboardController.php # 仪表盘（Redis缓存）
│   │   ├── UserController.php      # 用户 CRUD + 批量操作
│   │   ├── RoleController.php      # 角色 CRUD
│   │   ├── PermissionController.php# 权限 CRUD
│   │   ├── ConfigController.php    # 系统配置 CRUD
│   │   ├── LogController.php       # 操作日志查询
│   │   ├── ProfileController.php   # 个人中心 + 登出
│   │   ├── ExportController.php    # Excel/PDF 导出
│   │   ├── ImportController.php    # Excel 导入用户
│   │   ├── UploadController.php    # 文件上传
│   │   ├── HealthController.php    # 健康检查
│   │   ├── DocsController.php      # OpenAPI 文档
│   │   └── BaseController.php      # 基础控制器
│   ├── api/
│   │   └── v1/controller/          # API v1 控制器（版本由请求头 API-Version 控制）
│   │       ├── CaptchaController.php # 点击验证码
│   │       └── AuthController.php    # 登录/刷新令牌
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php  # ID 编解码
│   │   ├── SnowflakeService.php# Snowflake ID 生成
│   │   └── EncryptionService.php # 数据加解密 + 脱敏
│   ├── middleware/             # 中间件
│   │   ├── Cors.php            # 跨域
│   │   ├── SecurityFilter.php  # 攻击检测拦截（HTTP方法限制/XSS/SQL注入/路径遍历/命令注入/CSRF）
│   │   ├── RateLimit.php       # Redis 限流（滑动窗口 + 响应头）
│   │   ├── ApiVersion.php      # API 版本校验
│   │   ├── AdminAuth.php       # JWT 认证 + 黑名单
│   │   ├── AdminPermission.php # RBAC 权限校验
│   │   └── OperationLog.php    # 操作日志自动记录（含来源端检测）
│   └── model/                  # 数据模型
├── apps/
│   ├── flutter/                # Flutter Web 管理后台（PC 风格）
│   │   └── lib/app/
│   │       ├── pages/          # 5 个完整页面（仪表盘/用户/角色/配置/日志/个人中心）
│   │       ├── services/       # ApiService（JWT 拦截器）+ AuthService（Token 持久化）
│   │       └── layouts/        # 响应式管理后台布局（侧边栏+顶栏+内容区）
│   └── harmonyos/              # HarmonyOS 原生客户端（Token 无感刷新）
├── config/                     # 配置文件（含中文注释）
│   ├── route.php               # 路由 + API 版本策略
│   ├── middleware.php           # 全局中间件注册
│   └── ...                     # 各组件配置
├── database/migrations/        # SQL 迁移文件（含权限种子数据）
├── public/                     # 公共入口
├── runtime/                    # 运行时文件
└── vendor/                     # Composer 依赖
```

## Prérequis

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (uniquement pour le développement frontend)
- Elasticsearch >= 7.x (optionnel, requis pour la recherche)

## Démarrage rapide

### 1. Installer les dépendances

```bash
composer install
```

### 2. Configurer les variables d'environnement

Copiez et modifiez les variables d'environnement (facultatif ; à défaut, les valeurs par défaut de `config/*.php` sont utilisées) :

```bash
cp .env.example .env
```

Éléments de configuration clés :

| Variable | Description | Valeur par défaut |
|---------|------|--------|
| `JWT_SECRET` | Clé de signature JWT | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Sel Hashids | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | Clé de chiffrement API | Valeur par défaut de 32 octets |
| `SNOWFLAKE_DATACENTER_ID` | ID du centre de données (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | ID du nœud de travail (0-31) | `1` |
| `SCOUT_HOSTS` | Adresse ES | `http://localhost:9200` |

**En production, remplacez impérativement toutes les clés par des chaînes aléatoires.**

### 3. Installation en un clic

Après le démarrage du service, ouvrez l'assistant d'installation dans le navigateur pour initialiser la base et créer l'administrateur :

```bash
php start.php start
```

Écoute par défaut sur `http://0.0.0.0:8787` (le port se modifie dans `config/server.php`).

Ouvrez **`http://localhost:8787/install`** dans le navigateur et remplissez selon l'assistant :

| Étape | Contenu |
|------|------|
| ① Configuration base de données | Hôte, port, nom de la base, utilisateur, mot de passe |
| ② Administrateur | Nom d'utilisateur et mot de passe admin (défaut : admin / admin888) |

Après un clic sur « Démarrer l'installation », la création des tables, le seed des permissions, la création du compte admin et l'écriture de la configuration dans `.env` s'effectuent automatiquement.

> Après l'installation, un fichier de verrouillage `runtime/install.lock` est généré. Supprimez-le pour réinstaller.

### 4. Connexion

Rendez-vous sur `http://localhost:8787` et connectez-vous avec les identifiants admin définis lors de l'installation.

### 5. Démarrer le frontend (facultatif)

**Console d'administration Flutter (Web) :**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**Client HarmonyOS (mobile) :**

Ouvrez le répertoire `apps/harmonyos/` dans DevEco Studio et exécutez sur un appareil réel ou un émulateur.

### 6. Déploiement en un clic avec Docker Compose (recommandé en production)

Le projet fournit une orchestration Docker complète avec 5 services : Nginx, PHP (webman app), MySQL, Redis, Elasticsearch.

```bash
# 1. 配置 Docker 环境变量
cp .env.docker .env

# 2. 启动所有服务
docker-compose up -d

# 3. 浏览器访问安装向导完成初始化
# http://localhost:8787/install  (填入数据库和管理员信息)
# 或手动执行 SQL 迁移（进入 app 容器）:
# docker-compose exec app mysql -h mysql -u root -p < database/migrations/open_admin.sql

# 4. 访问
# http://localhost:8787  (webman)
# http://localhost:8080  (Nginx 反向代理)
```

- `Dockerfile` : PHP 8.3 + OPcache + Composer, basé sur `php:8.3-cli`
- `docker-compose.yml` : orchestration de 5 services, isolation réseau, volumes de données persistants
- `.env.docker` : variables d'environnement dédiées à Docker


## Conventions de base de données

- **Préfixe de table** : `erik_`
- **Clé primaire** : toutes les tables utilisent `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT désactivé**
- **Génération des ID** : les clés primaires sont générées au niveau applicatif par `SnowflakeService::generate()`, uniques en environnement distribué
- **Champs obligatoires** : chaque table doit contenir `id`, `created_at`, `updated_at`
- **Suppression douce** : les tables concernées ajoutent `deleted_at DATETIME DEFAULT NULL`
- **Champs sensibles** : téléphone, e-mail, numéro de carte d'identité, etc. sont chiffrés automatiquement par le plugin `encryptable` ; la colonne utilise `VARCHAR(500)` pour stocker le texte chiffré

## Référence API

La spécification API complète (format de réponse unifié, codes d'erreur métier, traitement des ID, versions d'API, limitation de débit, architecture middleware, flux d'authentification et de captcha) et la liste complète des endpoints figurent dans la **[référence API](docs/API.md)**.

## Notes frontend

### Console d'administration Flutter (style PC)

- **Mise en page** : barre latérale repliable (64px/240px) + barre supérieure + zone de contenu, trois points de rupture responsives (mobile/tablette/desktop)
- **Pages** : connexion, tableau de bord, gestion des utilisateurs, rôles et permissions, configuration système, journaux d'opérations, profil
- **Gestion d'état** : GetX (singleton `ApiService` + persistance du jeton `AuthService`)
- **Tableau de bord** : cartes de statistiques, courbe de tendance (fl_chart), diagramme circulaire, journaux des opérations récentes
- **Export** : export Excel/PDF ; les PDF contiennent des informations de copyright non supprimables
- **Opérations groupées** : suppression groupée multi-sélection, activation/désactivation groupée
- **Thème** : Material 3, thème clair/sombre

### Client mobile HarmonyOS

- **Pages** : connexion, tableau de bord, liste/détail utilisateur, profil
- **Authentification** : JWT Bearer + renouvellement silencieux du jeton sur 401, redirection automatique vers la connexion en cas d'échec
- **Stockage** : le jeton est géré via AppStorage

## Règles de développement

- Pas de `\` de tête pour les fonctions/classes globales — importer uniformément avec `use`
- Tous les fichiers PHP doivent contenir la mention de copyright en tête
- Tous les fichiers de configuration doivent contenir des commentaires en chinois
- Les clés primaires doivent être générées par snowflake au niveau applicatif ; l'auto-incrément est interdit
- Tous les ID des paramètres et réponses API doivent passer par le chiffrement hashids
- Le middleware AdminPermission met en cache les droits utilisateur dans Redis (TTL=60s), éliminant le goulot d'étranglement des requêtes N+1

## Déploiement

### Docker Compose (recommandé)

À la racine du projet, `docker-compose.yml` orchestre 5 services :

| Service | Image | Port |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | Build depuis le `Dockerfile` local | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

L'image PHP est construite via `Dockerfile`, image de base `php:8.3-cli`, OPcache activé.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

Pipeline d'intégration continue GitHub Actions : `.github/workflows/ci.yml`

- Vérification de syntaxe PHP (`php -l`)
- Tests unitaires PHPUnit
- Analyse statique Flutter (`flutter analyze`)

### Sauvegarde de la base de données

Répertoire `database/backup/` :

- `backup.sh` — sauvegarde mysqldump + gzip, purge automatique des sauvegardes de plus de 30 jours
- `restore.sh` — restauration interactive, liste les sauvegardes disponibles

### Configuration de sécurité Nginx

Pour la production, reportez-vous à `docs/nginx-security.conf` pour durcir le proxy inverse.

## L'open source est un long chemin — merci de votre soutien

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
