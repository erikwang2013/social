# Conception globale de la plateforme sociale (Social Platform Design)

**语言 / Languages:** [中文](2026-08-16-social-platform-design.md) · [English](2026-08-16-social-platform-design.en.md) · [한국어](2026-08-16-social-platform-design.ko.md) · [Русский](2026-08-16-social-platform-design.ru.md) · [Deutsch](2026-08-16-social-platform-design.de.md) · [Français](2026-08-16-social-platform-design.fr.md) · [Español](2026-08-16-social-platform-design.es.md) · [Português](2026-08-16-social-platform-design.pt.md) · [हिन्दी](2026-08-16-social-platform-design.hi.md) · [العربية](2026-08-16-social-platform-design.ar.md) · [বাংলা](2026-08-16-social-platform-design.bn.md) · [Bahasa Indonesia](2026-08-16-social-platform-design.id.md) · [日本語](2026-08-16-social-platform-design.ja.md)

- Date : 2026-08-16
- Statut : confirmé, en attente d'implémentation
- Périmètre : communauté de contenu court (image+texte) + messagerie instantanée + live/voix + économie virtuelle, multilingue, multi-régions mondiales

## 1. Objectifs et périmètre

Construire une plateforme sociale combinant contenu court image+texte et IM, avec du live (vidéo + danmaku + mise en relation audio), de la voix (messages / appels 1v1 / salons vocaux), et une économie virtuelle de dons-cadeaux. Prise en charge d'un UI multilingue, de la traduction de contenu et de la conformité multi-régions, avec un déploiement multi-régions mondial. Développement natif parallèle sur trois plateformes : iOS / Android / HarmonyOS.

## 2. Vue d'ensemble du système

```
                    ┌─────────────────────────────────────────────┐
                    │   iOS (SwiftUI) │ Android (Kotlin+Compose)  │
                    │            HarmonyOS (ArkTS)                │
                    └───────┬─────────────────────────┬───────────┘
                            │  HTTPS / WSS（多区域就近接入）
                  ┌─────────▼──────────┐   ┌──────────────────────┐
                  │  CDN + 区域接入层   │   │ 厂商推送 APNs/FCM/华为 │
                  └─────────┬──────────┘   └──────────────────────┘
              ┌─────────────▼─────────────┐
              │   service (webman v2)     │──gRPC──▶ infrastructure
              │  业务单体：认证/资料/动态/ │          (bee-rust)
              │  点赞/评论/关注/IM 网关/   │  gRPC      搜索/推荐/图/
              │  翻译调度/审核/直播/语音/  │  ▲        时序/热数据
              │  虚拟经济                 │  │ gRPC
              └─────────────┬─────────────┘  │
                  ┌─────────▼─────────┐      │
                  │ MySQL + Redis     │      │
                  │ S3 对象存储       │      │
                  └───────────────────┘      │
              ┌──────────────────────────────┴──┐
              │   admin (open-admin 改造, webman) │
              │  审核台/举报/GDPR/看板/词条/礼物库/ │
              │  直播配置/提现审核                 │
              └──────────────────────────────────┘

  media/（自建媒体层，信令走 service WS 网关）
  ├── sfu/    mediasoup：1v1 通话、语聊房
  ├── srs/    SRS：自建直播（RTMP → FFmpeg 转码 → FLV/HLS）
  └── coturn/ TURN 中继

  外部：第三方直播云（推流/转码/CDN/实时审核）、第三方 RTC（连麦）、
        第三方审核 API、商店 IAP（App Store / Google Play / 华为）
```

## 3. Responsabilités des sous-systèmes

### 3.1 contracts (contrats gRPC, nouveau répertoire de premier niveau)

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- Pipeline de génération : la CI génère avec buf trois types de stubs et les commit dans leurs sous-dépôts respectifs (les builds ne dépendent pas du réseau)
  - service/, admin/ → stubs PHP (grpc/grpc + google/protobuf)
  - infrastructure/ → stubs Rust (tonic)
- Règle de versioning : uniquement ajouter des champs, jamais modifier ou supprimer ; le nom de paquet porte la version majeure (`social.user.v1`)

### 3.2 service (webman v2) — monolithe métier côté utilisateur

- **Domaines d'API** : auth (JWT double jeton + liste noire), profile, posts, likes, comments, follows, IM (conversations/messages/passerelle WS), notifications, planification de traduction, signalisation des salons live/danmaku/mise en relation, signalisation des appels vocaux/salons vocaux, économie virtuelle (portefeuille/cadeaux/vérification IAP/partage des revenus), export/suppression GDPR
- **Système d'erreurs multilingue** : les erreurs renvoient `{code, lang_key, params}` ; les textes sont rendus côté client selon la locale
- **Files** (redis-queue) : déclencheurs de modération, planification de traduction, livraison des pushs, statistiques asynchrones, diffusion des effets de cadeaux
- **Tâches planifiées** (webman-crontab) : préchauffage des traductions, nettoyage des jetons/messages expirés, archivage des audits, règlement du partage des revenus
- **ID** : `erikwang2013/snowflake-php` (identique à admin)
- **Contrats** : export automatique OpenAPI 3.0 → génération de clients typés pour les trois plateformes

### 3.3 infrastructure (bee-rust) — couche de calcul à haut débit

Ne stocke pas les données primaires métier (MySQL est la seule source de vérité) ; elle porte les capacités lourdes en calcul/requêtes :

- `bee_search` : recherche plein texte sur les publications/utilisateurs (segmentation des mots chinois, indexation multilingue)
- `bee_graph` : graphe social → fil de recommandations
- `bee_tsdb` : statistiques temporelles : DAU, publications, interactions, visionnage live, durée des appels vocaux, etc.
- `bee_cache/bee_kv` : cache de timeline, compteurs (likes, vues, utilisateurs en ligne)
- Déployé par région, lectures nombreuses/écritures rares, données répliquées depuis le site central

### 3.4 admin (refonte d'open-admin)

**Réutilisé** : JWT/RBAC/audit/gestion de fichiers/health checks/infrastructure i18n zh-en

**Nouveau** :
- Atelier de modération de contenu : revue bilingue côte à côte des publications/commentaires/images, modèles multilingues de motifs de rejet, sanctions utilisateurs
- File de traitement des signalements
- Guichet des demandes GDPR (tickets d'export/suppression)
- Tableaux de bord de données adossés à bee_tsdb
- Gestion des termes i18n (CRUD des termes partagés par les quatre clients)
- Gestion du catalogue de cadeaux (SKU, prix, effets, noms multilingues)
- Configuration des providers live (stratégie de routage, ordre de bascule)
- Revue des demandes de retrait

### 3.5 media (couche média auto-hébergée, Node.js + services système)

- `sfu/` : mediasoup ; porte le plan média des appels 1v1 et des salons vocaux ; uniquement de la retransmission média, pas de logique métier
- `srs/` : SRS auto-hébergé pour le live ; ingestion RTMP → transcodage FFmpeg → diffusion HTTP-FLV/HLS
- `coturn/` : relais TURN, repli pour la traversée NAT
- Toute la signalisation est relayée par la passerelle WS de service

### 3.6 apps — développement natif parallèle sur trois plateformes

- Contrat OpenAPI partagé ; chaque plateforme génère son propre client typé
- Modules d'infrastructure unifiés : couche réseau (réessais/rafraîchissement d'authentification), client WS (signalisation IM/danmaku/appels), i18n (ressources locales + termes distants incrémentaux), enregistrement push, thèmes
- Notes HarmonyOS : Huawei Push Kit, adaptation au modèle de concurrence ArkTS

## 4. Communication backend (gRPC)

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| Appelant → Appelé | Contenu |
|------|------|
| service → infra | recherche plein texte, fil de recommandations, cache chaud de timeline, lecture/écriture de compteurs, écriture de statistiques temporelles |
| admin → infra | requêtes de statistiques pour tableaux de bord, recherche backend |
| admin → service | sanctions utilisateurs, suppression de contenu, diffusion des résultats de modération |
| service → admin | événements de signalement, mise en file des tâches de modération (async) |

Frontière : les apps des trois plateformes et le frontend d'administration (Flutter) utilisent HTTPS REST + WS et ne touchent jamais gRPC directement.

**Prérequis d'exploitation** : côté PHP, gRPC nécessite l'extension officielle `grpc` (extension C) + le paquet composer `grpc/grpc` ; le mode serveur suit la solution officielle walkor/grpc de workerman ; la doc de déploiement doit le préciser clairement.

## 5. Architecture multilingue (trois couches)

| Couche | Approche |
|----|------|
| **Couche UI** | ressources de locale par plateforme (départ zh/en ; le système supporte toute langue) ; le serveur n'envoie que des codes d'erreur + des clés de modèles |
| **Couche contenu** | à la publication, stocker l'original + détection automatique de langue écrite dans le champ `lang` ; à la lecture, reader.lang ≠ author.lang → service de traduction (abstraction LLM/MT provider), résultats cachés dans Redis (bee_cache, TTL), drapeau `is_translated` permettant de revenir à l'original ; préchauffage planifié des contenus populaires |
| **Couche conformité** | règles de modération appliquées par région (règles GDPR UE vs autres régions) ; UI bilingue signalement/modération |

Le danmaku est un texte court en temps réel : pas de traduction de contenu, seulement de l'i18n UI + un filtrage multilingue des mots sensibles.

## 6. Architecture IM

- **Passerelle** : passerelle WS webman, multi-instances avec relais inter-nœuds Redis pub/sub, déduplication idempotente via `client_msg_id`
- **Données** : conversations / conversation_members / messages / message_reads ; messages privés + groupes (limite de groupe 500)
- **Livraison** : en ligne → push WS direct ; hors ligne → push APNs/FCM/Huawei
- **Fonctionnalités** : accusés de lecture, indicateur de saisie, retrait limité dans le temps, messages image/voix (upload S3 + transcodage)
- Partage le système d'utilisateurs et de notifications avec le fil d'actualité

## 7. Architecture live (vidéo + danmaku + mise en relation, double voie)

### 7.1 Abstraction de provider (dans service)

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| Mécanisme | Conception |
|------|------|
| Stratégie de routage | provider par défaut choisi par région à la création du salon (surcharge configurable par admin) ; régions sans couverture tierce ou sensibles aux coûts → auto-hébergé |
| Bascule de secours | double ingestion SDK côté streamer (principal = tiers, secours = SRS auto-hébergé) ; les lecteurs résolvent l'URL par provider et basculent automatiquement sur le flux auto-hébergé en cas de panne du tiers |
| Danmaku/mise en relation | découplés du pipeline vidéo : le danmaku passe par le WS de service, la mise en relation par la RTC tierce |
| Conformité | la modération audio/vidéo en temps réel du pipeline auto-hébergé réutilise les API de modération tierces (on n'achète que la modération, pas le transport) |

### 7.2 Salons live

CRUD de salons, machine à états début/fin de diffusion, couverture, annonces (multilingues), compteurs de visionnage (bee_tsdb), canaux danmaku des salons (Redis pub/sub), gestion des rôles de mise en relation (hôte/sièges, service émet les jetons RTC tiers), statistiques en ligne/pic/durée → tableaux de bord admin.

## 8. Architecture vocale (trio)

| Forme | Implémentation |
|------|------|
| Messages vocaux | extension du type de message IM : stockage S3 + transcodage (m4a) + durée |
| Appels 1v1 | signalisation via la passerelle WS (offer/answer/ICE), machine à états sonnerie/réponse/raccrochage (Redis), plan média via mediasoup, enregistrements d'appels en base |
| Salons vocaux | la gestion des salons reprend le modèle des salons live ; états micro on/off/auditeurs gérés par service ; plan média via mediasoup |

## 9. Économie virtuelle (recharges + dons-cadeaux + retraits)

```
移动端 IAP（App Store/Google Play/华为）──┐
国内：微信支付 / 支付宝（APP/H5）          ├─▶ PaymentProvider ─▶ 钱包
国外：微信国际 / 支付宝国际 / Stripe / PayPal│    （按 region 选路）
                                          └─▶ payments 支付单（幂等+验签+对账）
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
主播钱包 ──▶ payouts 提现单 ──▶ 国内：商家转账 │ 国外：Stripe Connect/PayPal
```

### 9.1 Canaux de paiement (national vs international)

```
PaymentProvider 接口（admin 配置）
├── 国内（CNY）
│   ├── wechat_cn    微信支付（APP/H5）
│   ├── alipay_cn    支付宝（APP/WAP）
│   └── 提现：商家转账（零钱/银行卡）
├── 国外（USD/EUR/...）
│   ├── wechat_global  微信国际支付（境外商户）
│   ├── alipay_global  支付宝国际（Alipay+）
│   ├── stripe         卡 / Apple Pay / Google Pay / SEPA
│   ├── paypal
│   └── 提现：Stripe Connect / PayPal 批量打款
└── 移动端虚拟币充值：App Store / Google Play / 华为 IAP（商店政策强制，服务端凭证校验）
```

| Mécanisme | Conception |
|------|------|
| Routage des canaux | choix du canal selon la région de l'utilisateur + devise + règles marchand admin, ordre de repli configurable (séparation naturelle national/international) |
| Ordre de paiement | modèle payments unifié : utilisateur/canal/montant/devise/machine à états, idempotent sur tous les canaux |
| Callbacks | wrapper unifié de vérification de signature (RSA/HMAC), callbacks idempotents, tâche de rapprochement quotidienne (contrôle des relevés de canaux) |
| Retraits | ordres payouts : virement marchand au niveau national, Stripe Connect/PayPal à l'international ; mode répartition/émission choisi selon la capacité du canal |
| Tarification | tableaux de prix régionaux (admin) : monnaie virtuelle × prix en devise, taux de change gérés centralement |
| Risque | plafonds/limites de fréquence/alertes d'ordres anormaux, audit complet des flux (réutilise le système d'audit) |
| SKU cadeaux | catalogue de cadeaux (prix, identifiants d'effets, noms multilingues) géré par admin |

Conformité : les recharges de monnaie virtuelle sur mobile doivent passer par l'IAP des stores (commission Apple/Google/Huawei) ; WeChat/Alipay sont utilisés pour H5/Web et les scénarios régionaux spécifiques ; les retraits impliquent le règlement de fonds, la plateforme les traite donc via les interfaces de répartition/émission de canaux agréés ; la qualification contractuelle des canaux est à confirmer avant M6b ; les plafonds pour mineurs entrent en phase de conformité.

## 10. Modèles de données principaux

- Utilisateurs : users, user_profiles (champs multilingues)
- Social : follows, posts, post_translations, comments, comment_translations, likes, reports
- IM : conversations, conversation_members, messages, message_reads
- Live : live_rooms, live_streams (avec provider), danmaku_archive
- Voix : call_records, voice_rooms, voice_room_members
- Économie virtuelle : wallets, currency_transactions, gift_catalog, gifts_given, streamer_earnings, withdrawals, payments, payouts, price_plans (prix régionaux/taux de change), merchant_configs (configs marchand des canaux), products (SKU IAP)
- Plateforme : i18n_terms (termes partagés par les quatre clients), moderation_queue, provider_configs, audit_logs

## 11. Choix des bases de données et stockage

| Usage | Stockage | Composant |
|------|------|----------|
| Données primaires métier (utilisateurs/publications/IM/portefeuille/modération/signalements) | MySQL 8 (master central + réplicas lecture seule régionaux) | partagé entre service et admin ; seule source de vérité |
| Données chaudes/sessions/statuts en ligne/compteurs/canaux danmaku/machines à états d'appels | Redis 7 | bee_kv / bee_cache (fonctionnalité redis) |
| Recherche plein texte (publications/utilisateurs, recherche backend admin) | OpenSearch (départ mono-nœud) | bee_search (fonctionnalité opensearch) |
| Statistiques temporelles (DAU/tendances/visionnages live/durée d'appels/tableaux de bord) | QuestDB (départ binaire unique) | bee_tsdb (fonctionnalité questdb, remplaçable par influxdb) |
| Graphe social → fil de recommandations | Neo4j Community (départ mono-nœud) | bee_graph (fonctionnalité neo4j, remplaçable par nebulagraph) |
| Fichiers objets (images/vidéos/voix/paquets d'export) | S3 (MinIO ou cloud) | accès direct service + diffusion CDN |
| Journaux d'audit | MySQL audit_logs, archivage en stockage objet à expiration | réutilise le système d'audit admin |

Principes de sélection : les composants bee-rust sont des abstractions à feature flags — départ mono-nœud, remplacement par des backends distribués avec l'échelle, sans verrouillage ; MySQL reste toujours la seule source de vérité ; la couche de calcul (index/statistiques/graphe/cache) ne stocke que des données dérivées reconstructibles. Le frontend d'administration (Flutter) ne touche jamais la base directement ; tout passe par le backend admin.

## 12. Déploiement et exploitation (multi-régions mondial)

- **Architecture de départ** : deux grandes régions — Chine + international ; chaque région : cluster webman + cluster bee-rust + Redis local + media (SFU/SRS/TURN) ; master central MySQL + réplicas lecture seule par région ; CDN par région
- **Accès WS au plus proche**, messages inter-régions coordonnés centralement ; push via le fournisseur correspondant par région
- **Chemin d'évolution** : après croissance du trafic, sharding des bases par hash utilisateur
- **Monitoring** : métriques Prometheus (selon le modèle open-admin), journaux centralisés, alertes (taux d'erreur/latence/accumulation de files/santé des services média)

## 13. Sécurité et conformité

- service reproduit le modèle de défense en 18 couches d'open-admin (XSS/SQLi/CSRF/limitation de débit/CSP)
- Pipeline de modération : filtre multilingue de mots sensibles à la publication → modération image/audio-vidéo (API tierces) → modération humaine
- GDPR : export de données, droit à l'effacement/suppression, politique de conservation des journaux, seuil d'âge pour mineurs, règles différenciées par région

## 14. Jalons (full-stack solo, ~9–10 mois)

| Phase | Contenu | Durée |
|------|------|------|
| M0 Fondations | squelette monorepo, contracts(gRPC)+génération de stubs des trois plateformes+sondes de vie bout en bout, init des projets des trois plateformes, CI (build+test), squelette des services bee-rust | 1–2 semaines |
| M1 Boucle fermée | inscription/connexion/profil, publication/détail, timeline simplifiée, likes et commentaires | 3–4 semaines |
| M2 Social complet | système d'abonnements, fil complet, recherche plein texte (bee_search), notifications | 3–4 semaines |
| M3 IM | passerelle WS, conversations, messages, push hors ligne, lecture/retrait | 4–6 semaines |
| M4 Voix | composants media (mediasoup+coturn), messages vocaux, appels 1v1, salons vocaux | 4–5 semaines |
| M5a Live principal | pipeline tiers, salons live, danmaku, mise en relation | 3–4 semaines |
| M5b Live complément | intégration SRS auto-hébergé, bascule de secours double ingestion, config de routage | 2 semaines |
| M6a Monnaie virtuelle+cadeaux | IAP, portefeuille, cadeaux, partage des revenus | 2–3 semaines |
| M6b Canaux de paiement | WeChat/Alipay/WeChat Global/Alipay Global/Stripe/PayPal, retraits, rapprochement | 3–4 semaines |
| M7 Multilingue+conformité | i18n toutes plateformes, traduction de contenu, atelier de modération, GDPR, intégration modération audio/vidéo | 3–4 semaines |
| M8 Lancement | déploiement deux régions (dont TURN régional), monitoring/alertes, tests de charge, revue de sécurité | 2–3 semaines |

Chaque jalon est une tranche livrable indépendamment ; le projet peut s'arrêter à tout moment, le produit restant toujours pleinement utilisable.

## 15. Récapitulatif de la stack technique

| Sous-système | Technologie |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / extension grpc / snowflake-php |
| infrastructure | Rust / workspace bee-rust (search/graph/tsdb/kv/cache) / tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| Externe | cloud live tiers, RTC tierce, API de modération tierces, WeChat Pay/Alipay/WeChat Pay Global/Alipay Global/Stripe/PayPal, IAP App Store/Google Play/Huawei, push APNs/FCM/Huawei |

## 16. Planification d'équipe (effectif réel, rythme stable)

### 16.1 Structure organisationnelle

```
技术负责人 / PM（1人，兼任 contracts 契约 owner）
├── 后端组（2人）       webman service 主力 + admin 改造/支付专项
├── 平台组（2人）       Rust ×1（infrastructure）、音视频 ×1（media）
├── 客户端组（3人）     iOS、Android、HarmonyOS 各 1
├── 质量与运维（2人）   QA ×1、DevOps ×1
└── 支持（弹性）        UI/UX ×1（常驻）、支付/合规顾问（按需）、本地化（外包）
```

### 16.2 Détail des rôles

| Rôle | Personnes | Responsabilités | Compétences clés | Arrivée |
|------|---|------|----------|------|
| Tech lead/PM | 1 | owner des contracts(gRPC), coordination inter-sous-systèmes, avancement des jalons | PHP/architecture/gestion de projet | M0 |
| Backend PHP · service | 1 | auth/publications/passerelle WS IM/signalisation live-voix/planification de traduction/déclencheurs de modération/GDPR | webman/Redis/MySQL/WS | M0 |
| Backend PHP · admin+paiements | 1 | refonte des 8 modules open-admin, PaymentProvider tous canaux, rapprochement, retraits | PHP/expérience canaux de paiement | M0 (paiements M6) |
| Ingénieur iOS | 1 | client SwiftUI, APNs, WS, intégration WebRTC, i18n | Swift/SwiftUI | M0 |
| Ingénieur Android | 1 | Kotlin+Compose, FCM, WS, WebRTC, i18n | Kotlin/Compose | M0 |
| Ingénieur HarmonyOS | 1 | client ArkTS, Push Kit, i18n | ArkTS/écosystème HarmonyOS | M0 |
| Ingénieur Rust | 1 | servicisation bee-rust (search/graph/tsdb) + gRPC tonic | Rust/axum/tonic | fin M1 |
| Ingénieur audio/vidéo | 1 | composants media (mediasoup/SRS/FFmpeg/coturn), bascule double ingestion, déploiement TURN régional | Node.js/WebRTC/SRS/transcodage | fin M3 |
| Designer UI/UX | 1 | système de design des trois plateformes, visuels live/cadeaux/voix, règles de textes i18n | Figma/design multilingue | M0 |
| QA | 1 | régression trois plateformes+backend+média, tests de charge, validation modération/paiements | tests mobile/API | M1 |
| DevOps | 1 | CI/CD, déploiement deux régions, monitoring Prometheus, exploitation des services média, logs | Docker/K8s/Prometheus | M2 |
| Conseiller paiements/finances | flexible | qualification contractuelle des canaux, règles de rapprochement, plafonds de risque, règlement du partage | secteur paiements/finances | dès M6 |
| Conseiller conformité/juridique | flexible | GDPR, réglementations régionales, règles de modération, politiques des stores | conformité des données | dès M7 |
| Localisation | externalisée | traduction et relecture des termes, textes multilingues | traduction/relecture | dès M7 |

### 16.3 Rythme des jalons

| Phase | Équipe | Focus parallèle |
|------|------|----------|
| M0–M2 | lead+2 backend+3 mobile+design+QA | contrats d'abord ; trois plateformes en parallèle sur OpenAPI ; Rust arrive pour la recherche |
| M3–M4 | +audio/vidéo, DevOps | l'audio/vidéo construit media en parallèle avec IM/voix |
| M5 | équipe complète | live double voie ; le backend soutient media |
| M6 | +conseiller paiements | volet paiements+rapprochement |
| M7 | +conseiller conformité, localisation | i18n toutes plateformes+clôture conformité |
| M8 | équipe complète, garantie | lancement deux régions, tests de charge, revue de sécurité |

### 16.4 Priorités de recrutement

1. Backend PHP ×2 + tech lead (cœur de la période des fondations ; le backend est le plus gros volume de travail)
2. Mobile ×3 (le parallélisme des trois plateformes est la contrainte dure du calendrier total — le plus tôt est le mieux)
3. UI/UX, QA
4. Rust, DevOps (arrivée avant M1–M2)
5. Audio/vidéo (fin M3)
6. Conseillers paiements/conformité, localisation (à la demande en M6/M7)

### 16.5 Risques et replis

- L'audio/vidéo et les canaux de paiement sont les deux rôles les plus difficiles à recruter (experts rares) ; prévoir des plans de repli par externalisation/conseillers
- Si un ingénieur HarmonyOS est difficile à recruter, un ingénieur Android peut d'abord le remplacer (ArkTS partage les racines de TS et s'apprend vite) ; le rythme parallèle des trois plateformes n'est pas affecté
