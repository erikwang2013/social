# Conception du jalon voix M4 (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- Date : 2026-08-17
- Statut : confirmé
- Périmètre : messages vocaux + appels 1v1 + salons vocaux (les trois composants) ; le mécanisme de versionnage d'API (par en-tête) est mis en place en premier
- Conception amont : `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 Architecture vocale)

## 1. Objectif

Livrer le trio vocal M4 : messages vocaux (extension du type de message IM + transcodage), appels 1v1 (machine à états de signalisation WS + plan média P2P), salons vocaux (machine à états de salon + SFU mediasoup). Mettre en place en parallèle le mécanisme de versionnage d'API par en-tête.

## 2. Versionnage d'API (par en-tête, tâche 0 en premier)

**État actuel** : tous les endpoints sont enregistrés dans le groupe de préfixe `/api/v1` (`config/route.php`), 10 contrôleurs, `AuthMiddleware` monté dans le groupe.

**Mécanisme** : le client envoie un chemin sans version `/api/xxx` + `Header: X-Api-Version: v1` ; le middleware global `ApiVersionMiddleware` (`config/middleware.php`) réécrit le chemin puis le transmet au routeur.

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- Version non valide (pas `v1|v2|...`) → 400 + `lang_key`
- Zéro migration : les contrôleurs/routes/parcours E2E existants restent inchangés
- V2 future : enregistrer le groupe `/api/v2` → `app\api\v2\*`, aucun changement de middleware
- Les nouveaux endpoints M4 sont enregistrés sous `/api/v1/voice/*` (préfixe de version conservé ; le client utilise un chemin sans version + en-tête)

## 3. Modèle de données (m4.sql)

**`social_messages` ALTER** :

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**Nouvelles tables** :

```sql
CREATE TABLE `social_call_records` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  caller_id BIGINT UNSIGNED NOT NULL,
  callee_id BIGINT UNSIGNED NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1呼叫中 2接通 3未接 4取消 5结束',
  started_at TIMESTAMP NULL COMMENT '接通时间',
  ended_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), KEY idx_callee(callee_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='1v1通话记录';

CREATE TABLE `social_voice_rooms` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  owner_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1开 0关',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), KEY idx_status(status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房';

CREATE TABLE `social_voice_room_members` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  room_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role TINYINT NOT NULL DEFAULT 0 COMMENT '0听众 1麦位',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), UNIQUE KEY uk_room_uid(room_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房成员';
```

## 4. Messages vocaux

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- La REST de l'historique inclut automatiquement `voice_url/voice_duration` (cast du modèle)
- Transcodage effectué de façon synchrone dans la requête (secondes par fichier) ; passer en file d'attente quand le volume augmente (note ponytail)
- Prérequis d'environnement : le binaire FFmpeg est requis sur l'hôte du service (vérifier lors de l'implémentation ; installer si absent)

## 5. Signalisation d'appel 1v1

**Frames WS** (réutilisation de la passerelle existante, préfixe `call_*`) :

```
call_invite   {to_user_id}            主叫发起
call_accept   {call_id}               被叫接听
call_reject   {call_id}               被叫拒绝
call_cancel   {call_id}               主叫取消
call_timeout  {call_id}               30s 无人接听（服务端推双方）
call_offer    {call_id, sdp}          主叫 offer（经服务端转发被叫）
call_answer   {call_id, sdp}          被叫 answer 回传
call_ice      {call_id, candidate}    ICE 候选双向转发
call_hangup   {call_id}               任一方挂断 → 推双方
call_failed   {call_id}               P2P 15s 未连通 → 推双方
```

**Machine à états** (une seule clé Redis) :

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- Mutex d'inactivité : `SETNX im:callbusy:{uid}` (TTL 5 min), en cas de conflit, renvoyer la frame d'erreur `already_in_call`
- Pas de réponse en 30 s → non répondu, envoyer `call_timeout` aux deux parties, persister
- accept → `call_records` status=2 + started_at
- hangup/fin → status=5 + ended_at, libérer la clé busy
- Déconnexion WS d'un côté → envoyer `call_hangup` à l'autre et terminer (ponytail : pas de récupération par reconnexion)
- Plan média en connexion P2P directe (offer/answer/ICE uniquement relayés, les flux média ne passent jamais par le serveur) ; repli TURN (coturn livré avec les salons vocaux)
- ICE P2P non établi en 15 s → `call_failed` + fin (la v1 ne bascule pas automatiquement sur SFU, note ponytail) ; persister status=5

**Historique** : `GET /api/v1/voice/calls?page=` réponse paginée (caller/callee/status/durée).

## 6. Salons vocaux

**REST** :

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**Frames WS** (préfixe `room_*`) :

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- Places micro plafonnées à 8 (1 propriétaire + 7 places, constante, configurable plus tard dans admin) ; renvoyer une frame d'erreur quand complet
- join/leave/changements de micro persistés dans la table `voice_room_members` + état du salon dans Redis ; pousser les changements à tous les membres en ligne du salon
- Le propriétaire quitte → fermer le salon (envoyer `room_closed` à tous)

**Chemin de signalisation SFU** (selon le document de conception : « toute la signalisation réutilise la passerelle WS du service ») :

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service relaie les frames → SFU (POST HTTP traduisant l'API mediasoup : rtpCapabilities, création/connect de WebRtcTransport, produce/consume) ; réponse SFU → service → push WS au client
- Un Router mediasoup par salon ; libération automatique après 5 min d'inactivité (note ponytail)

**Déploiement** : `media/sfu` processus Node nu (développement) + `docker-compose.yml` réservé à la production ; le conteneur `coturn` est livré dans le même bloc.

## 7. Stratégie de test

| Niveau | Couverture |
|---|---|
| Tests unitaires | ApiVersionMiddleware (défaut/explicite/invalide/ancien chemin), machine à états d'appel (invite/accept/reject/cancel/timeout/hangup/mutex), machine à états de salon vocal (join/places micro/fermeture/plein/kick), validation de l'upload vocal (type/taille/durée) |
| E2E boîte noire | Messages vocaux : upload → envoi de frame → réception de frame → historique avec duration ; 1v1 : invite→accept→assertion du relais offer/answer/ICE→hangup→call_records persisté ; salons vocaux : join→up_mic→down_mic→leave→fermeture du salon |
| Build | Build Android testé réellement ; les commits iOS/HarmonyOS indiquent que Linux ne peut pas builder (schéma établi en M3) |
| Manuel sur appareil réel | Audio/vidéo SFU réels, qualité d'appel P2P (la boîte noire ne peut pas automatiser WebRTC) |

## 8. Ordre d'implémentation (pipeline en ordre inverse de dépendance)

0. Middleware de versionnage d'API (en premier, livrable indépendant)
1. Messages vocaux (upload + transcodage + stockage + modèle + type de message)
2. Machine à états de signalisation d'appel 1v1 (+ call_records + REST d'historique)
3. Salons vocaux (REST + machine à états de salon + places micro)
4. media/sfu (mediasoup + docker-compose) + coturn
5. Clients des trois plateformes (enregistrement/lecture vocale / UI d'appel / UI de salon vocal)
6. E2E + régression complète
