<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\live;

use app\model\LiveRoom;
use app\ws\ConnectionRegistry;
use app\ws\Deliverer;
use app\ws\Envelope;
use app\ws\WsRedis;

/**
 * 直播状态机。DB（live_rooms.status）为事实；
 * Redis 承载在线集合 / 弹幕 / 麦位实时态，关播即销毁。
 */
class LiveCenter
{
    /** @var callable(int $uid, array $frame): void 测试注入；null 时走生产投递路径 */
    private $sendFn;

    public function __construct(?callable $sendFn = null)
    {
        $this->sendFn = $sendFn;
    }

    public function create(int $owner, string $title): int
    {
        $room = LiveRoom::query()->create([
            'owner_id' => $owner,
            'title' => $title,
            'status' => 1,
            'push_url' => LiveStreamService::signPushUrl(0),
            'play_url' => LiveStreamService::signPlayUrl(0),
            'started_at' => date('Y-m-d H:i:s'),
        ]);
        // 先落库拿自增 id，再签真实地址回写
        $room->update(['push_url' => LiveStreamService::signPushUrl((int) $room->id), 'play_url' => LiveStreamService::signPlayUrl((int) $room->id)]);
        // 开播瞬间房主 WS 未连（REST 触发），join 不广播 —— 否则 live_join 入跨进程队列，
        // 房主连上后被延迟投递成幽灵帧；房主 WS 进房时才会真正广播
        $this->join((int) $room->id, $owner, false);
        return (int) $room->id;
    }

    public function join(int $roomId, int $uid, bool $broadcast = true): void
    {
        $room = LiveRoom::query()->find($roomId);
        if ($room === null || (int) $room->status !== 1) {
            throw new \RuntimeException('live_room_not_found');
        }
        WsRedis::call(fn($r) => $r->sadd('live:room:' . $roomId . ':online', $uid));
        WsRedis::call(fn($r) => $r->sadd('live:roomuser:' . $uid, $roomId));
        // TOCTOU：sadd 后复核——close 并发在复核前删键则回滚，避免在线键复活
        $room = LiveRoom::query()->find($roomId);
        if ($room === null || (int) $room->status !== 1) {
            WsRedis::call(fn($r) => $r->srem('live:room:' . $roomId . ':online', $uid));
            WsRedis::call(fn($r) => $r->srem('live:roomuser:' . $uid, $roomId));
            throw new \RuntimeException('live_room_not_found');
        }
        if ($broadcast) {
            $this->broadcast($roomId, ['type' => Envelope::T_LIVE_JOIN, 'data' => ['room_id' => $roomId, 'user_id' => $uid]]);
        }
    }

    public function leave(int $roomId, int $uid): void
    {
        WsRedis::call(fn($r) => $r->srem('live:room:' . $roomId . ':online', $uid));
        WsRedis::call(fn($r) => $r->srem('live:roomuser:' . $uid, $roomId));
        WsRedis::call(fn($r) => $r->srem('live:room:' . $roomId . ':mic', $uid));
        $this->broadcast($roomId, ['type' => Envelope::T_LIVE_LEAVE, 'data' => ['room_id' => $roomId, 'user_id' => $uid]]);
    }

    public function close(int $roomId, int $ownerUid): void
    {
        $room = LiveRoom::query()->find($roomId);
        if ($room === null || (int) $room->owner_id !== $ownerUid) {
            throw new \RuntimeException('live_room_forbidden');
        }
        if ((int) $room->status !== 1) {
            throw new \RuntimeException('live_room_not_found');
        }
        $room->update(['status' => 0, 'ended_at' => date('Y-m-d H:i:s')]);
        // 先广播后删键：broadcast 依赖在线集合拿接收者
        $this->broadcast($roomId, ['type' => Envelope::T_LIVE_CLOSED, 'data' => ['room_id' => $roomId]]);
        $online = WsRedis::call(fn($r) => $r->smembers('live:room:' . $roomId . ':online')) ?? [];
        foreach ($online as $uid) {
            WsRedis::call(fn($r) => $r->srem('live:roomuser:' . $uid, $roomId));
        }
        WsRedis::call(fn($r) => $r->del('live:room:' . $roomId . ':online'));
        WsRedis::call(fn($r) => $r->del('live:room:' . $roomId . ':danmaku'));
        WsRedis::call(fn($r) => $r->del('live:room:' . $roomId . ':mic'));
    }

    public function sendDanmaku(int $roomId, int $uid, string $content): array
    {
        $room = LiveRoom::query()->find($roomId);
        if ($room === null || (int) $room->status !== 1) {
            throw new \RuntimeException('live_room_not_found');
        }
        $msg = ['room_id' => $roomId, 'user_id' => $uid, 'nickname' => '', 'content' => $content];
        $keep = (int) config('live.danmaku_keep', 200);
        WsRedis::call(fn($r) => $r->multi()
            ->lPush('live:room:' . $roomId . ':danmaku', json_encode($msg, JSON_UNESCAPED_UNICODE))
            ->lTrim('live:room:' . $roomId . ':danmaku', 0, $keep - 1)
            ->exec());
        $this->broadcast($roomId, ['type' => Envelope::T_DANMAKU, 'data' => $msg]);
        return $msg;
    }

    public function micUp(int $roomId, int $uid): void
    {
        $room = LiveRoom::query()->find($roomId);
        if ($room === null || (int) $room->status !== 1) {
            throw new \RuntimeException('live_room_not_found');
        }
        $limit = (int) config('live.mic_limit', 8);
        if ($this->micCount($roomId) >= $limit) {
            throw new \RuntimeException('live_mic_full');
        }
        WsRedis::call(fn($r) => $r->sadd('live:room:' . $roomId . ':mic', $uid));
        $this->broadcast($roomId, ['type' => Envelope::T_LIVE_MIC_UP, 'data' => ['room_id' => $roomId, 'user_id' => $uid]]);
    }

    public function micDown(int $roomId, int $uid): void
    {
        WsRedis::call(fn($r) => $r->srem('live:room:' . $roomId . ':mic', $uid));
        $this->broadcast($roomId, ['type' => Envelope::T_LIVE_MIC_DOWN, 'data' => ['room_id' => $roomId, 'user_id' => $uid]]);
    }

    public function micCount(int $roomId): int
    {
        return (int) (WsRedis::call(fn($r) => $r->scard('live:room:' . $roomId . ':mic')) ?? 0);
    }

    public function onlineCount(int $roomId): int
    {
        return (int) (WsRedis::call(fn($r) => $r->scard('live:room:' . $roomId . ':online')) ?? 0);
    }

    public function recentDanmaku(int $roomId, int $limit = 50): array
    {
        $items = WsRedis::call(fn($r) => $r->lRange('live:room:' . $roomId . ':danmaku', 0, $limit - 1)) ?? [];
        // List 后进在左，倒序还原时间序
        return array_map(fn($s) => json_decode((string) $s, true) ?? [], array_reverse($items));
    }

    public function micUsers(int $roomId): array
    {
        return array_map('intval', WsRedis::call(fn($r) => $r->smembers('live:room:' . $roomId . ':mic')) ?? []);
    }

    /** 断线/登出清理：连接断开路径统一由 WsServer 调用 */
    public function onDisconnect(int $uid): void
    {
        $rooms = WsRedis::call(fn($r) => $r->smembers('live:roomuser:' . $uid)) ?? [];
        foreach ($rooms as $roomId) {
            $this->leave((int) $roomId, $uid);
        }
        WsRedis::call(fn($r) => $r->del('live:roomuser:' . $uid));
    }

    private function broadcast(int $roomId, array $frame): void
    {
        $uids = WsRedis::call(fn($r) => $r->smembers('live:room:' . $roomId . ':online')) ?? [];
        if ($this->sendFn !== null) {
            foreach ($uids as $uid) {
                ($this->sendFn)((int) $uid, $frame);
            }
            return;
        }
        // 生产路径：本进程连接直推；其余入队 social:live:broadcast 由 ws worker 定时消费直推
        // （HTTP worker 触发 close 等广播时 ConnectionRegistry 无连接，必须跨进程桥接）
        $payload = Envelope::encode($frame['type'] ?? '', $frame['data'] ?? []);
        $remote = false;
        foreach ($uids as $uid) {
            if (ConnectionRegistry::localFd((int) $uid) !== null) {
                Deliverer::pushToMember((int) $uid, $payload, false);
            } else {
                $remote = true;
            }
        }
        if ($remote) {
            WsRedis::call(fn($r) => $r->rpush('social:live:broadcast', $payload));
        }
    }
}
