# Plateforme Sociale

**语言 / Languages:** [中文](../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Monorepo de plateforme sociale multilingue : communauté texte/image + messagerie instantanée + live/voix + économie virtuelle.

## Présentation du projet

- **Trois clients natifs** : Android (Kotlin + Compose), iOS (SwiftUI), HarmonyOS (ArkTS), plus une console d'administration Flutter
- **Services métier** : webman v2 (PHP 8.3) sert à la fois REST et WebSocket ; les machines à états live/salon vocal/appel 1v1 sont migrées vers Rust (infrastructure/bee-rust) ; les contrôleurs PHP se connectent directement en gRPC ; l'API est versionnée via `X-Api-Version` (v1 par défaut, compatible avec les anciens chemins `/api/vX`)
- **Couche média maison** : mediasoup SFU + coturn TURN pour le relais média des appels vocaux 1v1 et des salons vocaux (8 sièges)
- **Stratification des états** : MySQL comme source de vérité métier, Redis pour l'état temps réel des sessions / IM / appels / salons
- **Jalons** : M0–M5 livrés (messages vocaux, appels 1v1, salons vocaux, live streaming)  ; M6a livre l'économie virtuelle : portefeuille (solde/journal, MySQL comme source de vérité unique), pourboires-cadeaux avec part du streamer et rechargement IAP mobile (App Store / Google Play / Huawei) ; M6b livre les canaux de paiement : squelette de crédit de recharge (vérification de signature de callback WeChat/Alipay/Stripe, tarification côté serveur, crédit idempotent ; retrait et réconciliation livrés) ; M6c livre le stockage CDN : fournisseurs configurables depuis le panneau d'administration (compatible S3 : AWS S3 / Cloudflare R2 / Aliyun OSS / Tencent COS / Backblaze B2) ; images/voix/fichiers servis via stockage objet + CDN ; M6d livre les rapports d'administration et les statistiques du tableau de bord : module de rapports (utilisateurs/paiements/retraits — filtre par dates, totaux, tendances, répartitions, export Excel) et cartes de statistiques de la plateforme sur la page d'accueil

## Aperçu des fonctionnalités

![Aperçu des fonctionnalités](diagrams/features.fr.svg)

## Architecture

![Architecture](diagrams/architecture.fr.svg)

## Processus métier essentiels

![Processus métier essentiels](diagrams/core-flow.fr.svg)

## Cycle de vie

![Cycle de vie](diagrams/lifecycle.fr.svg)

## Conception des modules

![Conception des modules](diagrams/module-design.fr.svg)

## Structure du projet

| Répertoire | Description | Technologie |
|------|------|------|
| contracts/ | Contrats gRPC (proto, point d'entrée de génération buf) | protobuf / buf |
| service/ | Service métier côté utilisateur (REST :8788 + WS :8789) | webman v2 (PHP 8.3) |
| admin/ | Console d'administration (basée sur open-admin) | webman v2 + Flutter |
| infrastructure/ | Couche de calcul à haut débit (services gRPC live/voix) | bee-rust (tonic) |
| media/sfu/ | Couche média maison (mediasoup SFU :8790 + coturn :3478) | Node.js (activée en M4) |
| apps/ | Trois clients natifs | SwiftUI / Kotlin+Compose / ArkTS |

Structure interne de service :

```
service/
├── app/
│   ├── controller/   # Contrôleurs REST (auth/post/follow/im/voice/wallet/gift/...)
│   ├── common/        # WalletService (solde/journal/idempotent) · GiftService (cadeaux/part)
│   ├── ws/           # WsServer · protocole de trames Envelope · push Deliverer · ConnectionRegistry
│   ├── call/         # CallCenter : machine à états d'appel 1v1 (migré vers Rust en M6 ; côté PHP conservé pour la signalisation WS)
│   ├── room/         # RoomCenter : salons vocaux (migré vers Rust en M6 ; côté PHP conservé pour la signalisation WS)
│   ├── live/         # LiveCenter : salles en direct (migré vers Rust en M6 ; côté PHP conservé pour la signalisation WS)
│   ├── model/        # Modèles de données
│   ├── process/      # Processus personnalisés Http / WsServer
│   └── storage/      # Stockage des fichiers vocaux (m4a ; pris en charge par Rust VoiceStorage depuis M6)
├── config/           # route.php (groupe de routes /api/v1) · process.php (:8788/:8789)
└── tests/            # Tests unitaires phpunit + E2E boîte noire im_e2e.php / voice_e2e.php / live_e2e.php / wallet_e2e.php
```

## Installation en un clic

Prérequis : PHP ≥ 8.3 (composer), MySQL, Redis (Docker facultatif, pour la couche média).

```bash
./install.sh
```

Le script : exécute `composer install` une fois pour `service/` et une fois pour `admin/` ; crée la base de données à partir de `database/install.sql` (idempotent, CREATE IF NOT EXISTS) ; génère le `.env` des deux services (clés JWT / APP aléatoires, ne remplace jamais les fichiers existants) ; démarre éventuellement la couche média (`docker compose up -d` pour media/sfu et coturn, `--skip-media` pour ignorer) ; affiche enfin les commandes de démarrage de chaque service et les adresses d'accès.

## Installation manuelle

1. Installer les dépendances :

```bash
cd service && composer install
cd admin && composer install
```

2. Créer la base de données :

```bash
mysql -u root -p < database/install.sql
```

3. Configurer l'environnement : copier `service/.env.example` et `admin/.env.example` vers `.env`, renseigner les clés DB / Redis / JWT / APP (toujours des clés aléatoires en production).

4. Démarrer les services :

```bash
cd service && php start.php start -d   # HTTP :8788 · WS :8789
cd admin && php start.php start -d     # admin :8787
```

5. Démarrer la couche média (facultatif) :

```bash
cd media/sfu && docker compose up -d --build   # SFU :8790 · coturn :3478
```

## Utilisation

### Dépendances

- PHP ≥ 8.3 (composer)
- Redis (par défaut 127.0.0.1:6379)
- Node.js ≥ 18 (débogage local SFU)
- Docker (conteneurs SFU / coturn)

### Démarrer le service métier

```bash
cd service
composer install
php start.php start -d      # HTTP :8788 · WS :8789
```

Configurez `REDIS` et `SFU_URL` (par défaut 127.0.0.1:8790) dans `service/.env` selon vos besoins.

### Démarrer la couche média

```bash
cd media/sfu
docker compose up -d --build   # SFU :8790 (RTC UDP 10000-10200) · coturn :3478
```

### Clients

| Plateforme | Ouvrir / construire | Exigences de la plateforme |
|----|----------------|----------|
| Android | `cd apps/android && ./gradlew assembleDebug` | Compilable sous Linux / macOS |
| iOS | Ouvrir `apps/ios/SocialApp` dans Xcode | macOS requis |
| HarmonyOS | Ouvrir `apps/harmonyos` dans DevEco Studio | DevEco Studio requis |

### Tests

```bash
cd service
vendor/bin/phpunit                    # Tests unitaires (79 tests / 230 assertions)

php tests/im_e2e.php                  # E2E boîte noire IM (nécessite :8788/:8789 en cours d'exécution + Redis)
php tests/voice_e2e.php               # E2E voix : versionnage / messages vocaux / appels / salons vocaux
php tests/live_e2e.php                # E2E live : salles / danmaku / micros / fermeture (push RTMP, pull HLS)

cd media/sfu
npm run smoke                         # Smoke test du protocole SFU /signal (nécessite conteneur Docker ou node local)
```

## Votre soutien est le bienvenu

Si ce projet vous aide, scannez le code QR pour nous soutenir, merci !

**WeChat Pay**

<img src="weixinpay.png" width="160" height="175" alt="WeChat Pay">


**Alipay**

<img src="alipay.png" width="160" height="175" alt="Alipay">

**Virement international (virement bancaire)**




Si ce projet vous est utile, soutenez son développement par virement bancaire international.

**Informations sur le bénéficiaire**

| Champ | Contenu |
|------|------|
| Nom du bénéficiaire | WANG KEXUN |
| Numéro de compte du bénéficiaire | 881015918251 |

**Banque réceptrice — ZA Bank**

| Champ | Contenu |
|------|------|
| SWIFT Code | AABLHKHHXXX |
| Nom de la banque | ZA Bank Limited |
| Code bancaire | 387 |
| Adresse de la banque | Core F, Cyberport 3, 100 Cyberport Road, Hong Kong |

**Banque correspondante pour virements transfrontaliers (si nécessaire)**

> Les informations ci-dessous concernent la banque correspondante (banque intermédiaire) pour les virements transfrontaliers, et non la banque réceptrice. Renseignez-vous auprès de votre banque émettrice pour savoir si les informations de la banque correspondante sont requises.

La banque correspondante pour les virements en dollars de Hong Kong, en renminbi et en dollars américains est **Citibank** :

| Champ | Contenu |
|------|------|
| Nom de la banque | Citibank N.A. Hong Kong |
| SWIFT Code | CITIHKHXXXX |
| Code bancaire | 006 |
| Nom de l'agence | Hong Kong Branch |
| Code d'agence | 391 |
| Adresse de la banque | Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong |

Pour les virements dans d'autres devises, la banque correspondante est **BNY Mellon** :

| Champ | Contenu |
|------|------|
| Nom de la banque | THE BANK OF NEW YORK MELLON |
| SWIFT Code | IRVTUS3NXXX |
| Adresse de la banque | THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States |

### Don en cryptomonnaie (Crypto Donation)

Si ce projet vous est utile, scannez le code QR pour faire un don, merci !

| Réseau (Network) | Code QR (QR Code) | Adresse du portefeuille (Wallet Address) |
|---|---|---|
| BNB Smart Chain (BEP20) | [<img src="coin/1.jpg" width="150" alt="BNB Smart Chain (BEP20)">](coin/1.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Tron (TRC20) | [<img src="coin/2.jpg" width="150" alt="Tron (TRC20)">](coin/2.jpg) | `TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| Ethereum (ERC20) | [<img src="coin/3.jpg" width="150" alt="Ethereum (ERC20)">](coin/3.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Aptos | [<img src="coin/4.jpg" width="150" alt="Aptos">](coin/4.jpg) | `0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| Plasma | [<img src="coin/5.jpg" width="150" alt="Plasma">](coin/5.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Polygon POS | [<img src="coin/6.jpg" width="150" alt="Polygon POS">](coin/6.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Solana | [<img src="coin/7.jpg" width="150" alt="Solana">](coin/7.jpg) | `2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` |
| The Open Network (TON) | [<img src="coin/8.jpg" width="150" alt="The Open Network (TON)">](coin/8.jpg) | `UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| Arbitrum One | [<img src="coin/9.jpg" width="150" alt="Arbitrum One">](coin/9.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| AVAX C-Chain | [<img src="coin/10.jpg" width="150" alt="AVAX C-Chain">](coin/10.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |

## Documentation

- Conception générale : `superpowers/specs/2026-08-16-social-platform-design.md`
- Conception voix M4 : `superpowers/specs/2026-08-17-m4-voice-design.md`
- Plan d'implémentation : `superpowers/plans/2026-08-17-m4-voice.md`
