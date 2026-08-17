<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\ws;

use app\model\ConversationMember;
use app\model\Message;

/**
 * 投递：本机在线直推；离线入队 im:offline:{uid}（cap 200）+ PushProvider。
 * ponytail: 未做跨节点 pub/sub —— workerman 5 已移除 Redis 协议（subscribe 需自定义 RESP 协议），
 * 当前单机部署语义等价（本地直推/离线队列均不依赖订阅）；多机扩展时在 pushToMember 离线分支前补 publish 即可。
 */
final class Deliverer
{
    /** @var callable|null (int fd, string frame)，测试注入闭包捕获直推，生产默认 WsServer::sendToFd */
    public static $sendToFd = null;

    public static string $pushProviderClass = NullPushProvider::class;
    public static ?PushProvider $pushProvider = null; // 缓存实例，测试可置空以切换实现

    public static function deliver(Message $msg, int $senderId): void
    {
        $uids = ConversationMember::where('conversation_id', $msg->conversation_id)
            ->where('status', 1)->pluck('user_id')->all();
        $payload = Envelope::encode(Envelope::T_MESSAGE, [
            'message_id' => (int) $msg->id,
            'conversation_id' => (int) $msg->conversation_id,
            'sender_id' => (int) $msg->sender_id,
            'client_msg_id' => $msg->client_msg_id,
            'type' => (int) $msg->type,
            'content' => $msg->content,
            'image_url' => $msg->image_url,
            'voice_url' => $msg->voice_url ?? '',
            'voice_duration' => (int) ($msg->voice_duration ?? 0),
            'recall_status' => (int) $msg->recall_status,
            'created_at' => (string) $msg->created_at,
        ]);
        foreach ($uids as $memberUid) {
            self::pushToMember((int) $memberUid, $payload, true);
        }
    }

    public static function notifyRead(int $cid, int $uid, int $lastReadId): void
    {
        self::broadcast($cid, Envelope::T_READ, [
            'conversation_id' => $cid,
            'user_id' => $uid,
            'last_read_id' => $lastReadId,
        ], false, 0);
    }

    public static function relayTyping(int $cid, int $uid): void
    {
        self::broadcast($cid, Envelope::T_TYPING, [
            'conversation_id' => $cid,
            'user_id' => $uid,
        ], false, $uid);
    }

    public static function notifyRecall(int $cid, int $mid, int $uid): void
    {
        // 撤回需离线送达（离线成员同样入队）
        self::broadcast($cid, Envelope::T_RECALL, [
            'conversation_id' => $cid,
            'message_id' => $mid,
            'user_id' => $uid,
        ], true, 0);
    }

    private static function broadcast(int $cid, string $type, array $data, bool $queueIfOffline, int $excludeUid): void
    {
        $payload = Envelope::encode($type, $data);
        $uids = ConversationMember::where('conversation_id', $cid)->where('status', 1)->pluck('user_id')->all();
        foreach ($uids as $memberUid) {
            $memberUid = (int) $memberUid;
            if ($memberUid === $excludeUid) {
                continue;
            }
            self::pushToMember($memberUid, $payload, $queueIfOffline);
        }
    }

    /** CallCenter 默认 sendFn 复用（1v1 通话帧本机直推，不排队） */
    public static function pushToMember(int $uid, string $payload, bool $queueIfOffline): void
    {
        $fd = ConnectionRegistry::localFd($uid);
        if ($fd !== null) {
            self::sendFrame($fd, $payload);
            return;
        }
        if ($queueIfOffline) {
            self::queueOffline($uid, $payload);
        }
    }

    private static function queueOffline(int $uid, string $payload): void
    {
        WsRedis::call(function ($r) use ($uid, $payload) {
            $r->rpush('im:offline:' . $uid, $payload);
            $r->ltrim('im:offline:' . $uid, -200, -1); // cap 200
            return true;
        });
        self::push()->send($uid, json_decode($payload, true));
    }

    private static function sendFrame(int $fd, string $frame): void
    {
        if (self::$sendToFd === null) {
            self::$sendToFd = [WsServer::class, 'sendToFd'];
        }
        (self::$sendToFd)($fd, $frame);
    }

    private static function push(): PushProvider
    {
        return self::$pushProvider ??= new self::$pushProviderClass();
    }
}
