# M4 音声マイルストーン設計（Voice Design）

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- 日付：2026-08-17
- ステータス：確定済み
- 範囲：音声メッセージ＋1対1通話＋ボイスチャットルーム（3点セットをすべて実装）。API バージョン管理メカニズム（header 版）を先行導入
- 上位設計：`docs/superpowers/specs/2026-08-16-social-platform-design.md`（§8 音声アーキテクチャ）

## 1. 目標

M4 音声3点セットを提供する：音声メッセージ（IM メッセージタイプ拡張＋トランスコード）、1対1通話（WS シグナリングステートマシン＋P2P メディア面）、ボイスチャットルーム（ルームステートマシン＋mediasoup SFU）。同時に API header バージョン管理メカニズムを導入する。

## 2. API バージョン管理（header 版、タスク0を先行）

**現状**：すべてのエンドポイントは `/api/v1` プレフィックスグループ（`config/route.php`）に登録済み、コントローラ10個、`AuthMiddleware` がグループ内にマウントされている。

**メカニズム**：クライアントはバージョンなしパス `/api/xxx` ＋ `Header: X-Api-Version: v1` で送信。グローバルミドルウェア `ApiVersionMiddleware`（`config/middleware.php`）がパスを書き換えてルーターに引き渡す。

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- 不正なバージョン（`v1|v2|...` 以外）→ 400 ＋ `lang_key`
- ゼロマイグレーション：既存のコントローラ/ルート/E2E パスはすべてそのまま
- 将来の v2：`/api/v2` グループを登録 → `app\api\v2\*`、ミドルウェアの変更不要
- M4 の新規エンドポイントは `/api/v1/voice/*` に登録（バージョンプレフィックスは維持。クライアントはバージョンなしパス＋header で送信）

## 3. データモデル（m4.sql）

**`social_messages` ALTER**：

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**新規テーブル**：

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

## 4. 音声メッセージ

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- 履歴メッセージ REST が自動で `voice_url/voice_duration` を返す（モデルキャスト）
- トランスコードはリクエスト内で同期完了（1ファイル数秒）。量が増えたらキュー化（ponytail マーク）
- 環境前提：service 実行マシンに FFmpeg バイナリが必要（実装時に検証、無ければインストール）

## 5. 1対1通話シグナリング

**WS フレーム**（既存ゲートウェイを再利用、`call_*` プレフィックス）：

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

**ステートマシン**（Redis 単一 key）：

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- 空き排他：`SETNX im:callbusy:{uid}`（TTL 5分）。衝突時は `already_in_call` エラーフレームを返す
- 30秒無応答 → 未接、両端に `call_timeout` をプッシュ、DB 保存
- accept → `call_records` status=2 ＋ started_at
- hangup/終了 → status=5 ＋ ended_at、busy key を解放
- どちらかの WS 切断 → 相手に `call_hangup` をプッシュして終了（ponytail: 再接続リカバリはしない）
- メディア面は P2P 直結（offer/answer/ICE は中継のみ、メディアストリームはサーバーを経由しない）。TURN フォールバック（coturn はボイスチャットルームとともに提供）
- P2P ICE が15秒で未接続 → `call_failed` ＋終了（初版は SFU への自動切替なし、ponytail マーク）。status=5 を保存

**履歴**：`GET /api/v1/voice/calls?page=` でページング返却（caller/callee/status/時間）。

## 6. ボイスチャットルーム

**REST**：

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**WS フレーム**（`room_*` プレフィックス）：

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- マイク枠は上限8（房主1＋マイク枠7、定数。今後 admin で設定可）。満枠時はエラーフレームを返す
- join/leave/マイク変更は `voice_room_members` テーブル＋ Redis のルーム状態に保存。変更はルーム内の全オンラインメンバーにプッシュ
- 房主の退出 → ルーム閉鎖（全員に `room_closed` をプッシュ）

**SFU シグナリングパス**（設計ドキュメント：「シグナリングは一律 service WS ゲートウェイを再利用」）：

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service がフレームを SFU に中継（HTTP POST で mediasoup API に変換：rtpCapabilities、WebRtcTransport 作成/connect、produce/consume）。SFU 応答 → service → WS でクライアントにプッシュ
- ルームごとに mediasoup Router を1つ。空き5分で自動解放（ponytail マーク）

**デプロイ**：`media/sfu` は素の Node プロセス（開発）＋ `docker-compose.yml` は本番用に確保。`coturn` コンテナは同ブロックで提供。

## 7. テスト戦略

| 層 | カバー範囲 |
|---|---|
| 単体テスト | ApiVersionMiddleware（デフォルト/明示/不正/旧パス）、通話ステートマシン（invite/accept/reject/cancel/timeout/hangup/排他）、ボイスチャットルームステートマシン（join/マイク枠/閉鎖/満枠/キック）、音声アップロード検証（タイプ/サイズ/時間） |
| ブラックボックス E2E | 音声メッセージ：アップロード→フレーム送信→フレーム受信→履歴に duration を表示。1対1：invite→accept→offer/answer/ICE 中継の検証→hangup→call_records 保存。ボイスチャットルーム：join→up_mic→down_mic→leave→ルーム閉鎖 |
| ビルド | Android ビルドを実測。iOS/HarmonyOS はコミットに Linux ではビルド不可と明記（M3 既定パターン） |
| 実機手動テスト | SFU 実音声・映像、P2P 通話品質（WebRTC はブラックボックスで自動化不可） |

## 8. 実装順序（依存関係逆順パイプライン）

0. API バージョン管理ミドルウェア（先行、独立してデリバリー可能）
1. 音声メッセージ（アップロード＋トランスコード＋保存＋モデル＋メッセージタイプ）
2. 1対1通話シグナリングステートマシン（＋ call_records ＋ 履歴 REST）
3. ボイスチャットルーム（REST ＋ ルームステートマシン ＋ マイク枠）
4. media/sfu（mediasoup ＋ docker-compose）＋ coturn
5. 三端クライアント（音声録音/再生／通話 UI／ボイスチャットルーム UI）
6. E2E ＋ 全量リグレッション
