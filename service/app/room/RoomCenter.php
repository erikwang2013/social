<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\room;

use app\model\VoiceRoom;
use app\model\VoiceRoomMember;
use app\ws\Envelope;
use app\ws\WsRedis;

/**
 * 语聊房状态机。DB 即状态（rooms.status + room_members.role）；
 * Redis 仅缓存在线成员 uid 集合 im:room:{id}:online，用于推帧。
 */
class RoomCenter
{
    public const MIC_LIMIT = 8;   // 1 房主 + 7 麦位（ponytail: 常量，后续 admin 可配）

    /** @var callable(int $uid, array $frame): void */
    private $sendFn;
    /** @var callable(int $roomId, string $method, array $body): array SFU HTTP 转译 */
    private $sfuCall;

    public function __construct(?callable $sendFn = null, ?callable $sfuCall = null)
    {
        $this->sendFn = $sendFn ?? fn(int $uid, array $frame) => \app\ws\Deliverer::pushToMember($uid, \app\ws\Envelope::encode($frame['type'] ?? '', $frame['data'] ?? []), false);
        $this->sfuCall = $sfuCall ?? fn(int $roomId, string $method, array $body) => $this->sfuHttp($roomId, $method, $body);
    }

    public function create(int $owner, string $name): int
    {
        $room = VoiceRoom::query()->create(['owner_id' => $owner, 'name' => $name, 'status' => 1]);
        $this->join($room->id, $owner);
        return (int) $room->id;
    }

    public function join(int $roomId, int $uid): void
    {
        $room = VoiceRoom::query()->find($roomId);
        if ($room === null || (int) $room->status !== 1) {
            throw new \RuntimeException('room_not_found');
        }
        VoiceRoomMember::query()->firstOrCreate(
            ['room_id' => $roomId, 'user_id' => $uid],
            ['role' => (int) $room->owner_id === $uid ? 1 : 0],
        );
        // TOCTOU：校验后关房可能已并发（close 删 member 行 + 在线集合），重查避免复活孤儿数据
        $room = VoiceRoom::query()->find($roomId);
        if ($room === null || (int) $room->status !== 1) {
            VoiceRoomMember::query()->where('room_id', $roomId)->where('user_id', $uid)->delete();
            throw new \RuntimeException('room_not_found');
        }
        WsRedis::call(fn($r) => $r->sadd('im:room:' . $roomId . ':online', $uid));
        WsRedis::call(fn($r) => $r->sadd('im:roomuser:' . $uid, $roomId));
        $this->broadcast($roomId, ['type' => Envelope::T_ROOM_JOIN, 'data' => ['room_id' => $roomId, 'user_id' => $uid]]);
    }

    public function leave(int $roomId, int $uid): void
    {
        if (!$this->member($roomId, $uid)) {
            return;
        }
        VoiceRoomMember::query()->where('room_id', $roomId)->where('user_id', $uid)->delete();
        WsRedis::call(fn($r) => $r->srem('im:room:' . $roomId . ':online', $uid));
        WsRedis::call(fn($r) => $r->srem('im:roomuser:' . $uid, $roomId));
        $room = VoiceRoom::query()->find($roomId);
        if ($room !== null && (int) $room->owner_id === $uid) {
            $this->close($roomId, $uid);
            return;
        }
        $this->broadcast($roomId, ['type' => Envelope::T_ROOM_LEAVE, 'data' => ['room_id' => $roomId, 'user_id' => $uid]]);
    }

    public function close(int $roomId, int $ownerUid): void
    {
        $room = VoiceRoom::query()->find($roomId);
        if ($room === null || (int) $room->owner_id !== $ownerUid) {
            throw new \RuntimeException('room_forbidden');
        }
        $room->update(['status' => 0]);
        $uids = VoiceRoomMember::query()->where('room_id', $roomId)->pluck('user_id')->all();
        foreach ($uids as $uid) {
            WsRedis::call(fn($r) => $r->srem('im:roomuser:' . $uid, $roomId));
        }
        VoiceRoomMember::query()->where('room_id', $roomId)->delete();
        // 先广播后删在线集合：broadcast 依赖该集合拿到接收者（房主离房触发的 close 时房主已 srem，不影响成员）
        $this->broadcast($roomId, ['type' => Envelope::T_ROOM_CLOSED, 'data' => ['room_id' => $roomId]]);
        WsRedis::call(fn($r) => $r->del('im:room:' . $roomId . ':online'));
    }

    public function upMic(int $roomId, int $uid): void
    {
        if (!$this->member($roomId, $uid)) {
            return;
        }
        $mic = $this->micCount($roomId);
        if ($mic >= self::MIC_LIMIT) {
            throw new \RuntimeException('room_mic_full');
        }
        VoiceRoomMember::query()->where('room_id', $roomId)->where('user_id', $uid)->update(['role' => 1]);
        $this->broadcast($roomId, ['type' => Envelope::T_ROOM_UP_MIC, 'data' => ['room_id' => $roomId, 'user_id' => $uid]]);
    }

    public function downMic(int $roomId, int $uid): void
    {
        if (!$this->member($roomId, $uid)) {
            return;
        }
        VoiceRoomMember::query()->where('room_id', $roomId)->where('user_id', $uid)->update(['role' => 0]);
        $this->broadcast($roomId, ['type' => Envelope::T_ROOM_DOWN_MIC, 'data' => ['room_id' => $roomId, 'user_id' => $uid]]);
    }

    public function kickMic(int $roomId, int $ownerUid, int $targetUid): void
    {
        $room = VoiceRoom::query()->find($roomId);
        if ($room === null || (int) $room->owner_id !== $ownerUid) {
            throw new \RuntimeException('room_forbidden');
        }
        VoiceRoomMember::query()->where('room_id', $roomId)->where('user_id', $targetUid)->update(['role' => 0]);
        ($this->sendFn)($targetUid, ['type' => Envelope::T_ROOM_KICK_MIC, 'data' => ['room_id' => $roomId, 'user_id' => $targetUid]]);
        $this->broadcast($roomId, ['type' => Envelope::T_ROOM_DOWN_MIC, 'data' => ['room_id' => $roomId, 'user_id' => $targetUid]]);
    }

    /** SFU 信令转发：room_offer/answer/ice → media/sfu HTTP → 结果推回发送者 */
    public function sfuRelay(int $roomId, int $uid, string $frameType, array $data): void
    {
        $method = match ($frameType) {
            Envelope::T_ROOM_OFFER => 'produce',
            Envelope::T_ROOM_ANSWER => 'connect',
            default => 'transport',
        };
        $resp = ($this->sfuCall)($roomId, $method, $data);
        ($this->sendFn)($uid, ['type' => $frameType, 'data' => ['room_id' => $roomId] + $resp]);
    }

    private function broadcast(int $roomId, array $frame): void
    {
        $members = WsRedis::call(fn($r) => $r->smembers('im:room:' . $roomId . ':online')) ?? [];
        foreach ($members as $uid) {
            ($this->sendFn)((int) $uid, $frame);
        }
    }

    public function micCount(int $roomId): int
    {
        return VoiceRoomMember::query()->where('room_id', $roomId)->where('role', 1)->count();
    }

    public function status(int $roomId): int
    {
        return (int) VoiceRoom::query()->find($roomId)?->status;
    }

    private function member(int $roomId, int $uid): bool
    {
        return VoiceRoomMember::query()->where('room_id', $roomId)->where('user_id', $uid)->exists();
    }

    /** WS 掉线 → 离开其所在全部房间（房主掉线即关房） */
    public function onDisconnect(int $uid): void
    {
        $rooms = WsRedis::call(fn($r) => $r->smembers('im:roomuser:' . $uid)) ?? [];
        foreach ($rooms as $roomId) {
            $this->leave((int) $roomId, $uid);
        }
    }

    /** 裸 HTTP POST → media/sfu（env SFU_URL，默认 127.0.0.1:8790） */
    private function sfuHttp(int $roomId, string $method, array $body): array
    {
        $sock = @stream_socket_client('tcp://' . env('SFU_URL', '127.0.0.1:8790'), $errno, $errstr, 2);
        if ($sock === false) {
            throw new \RuntimeException('sfu_unreachable');
        }
        stream_set_timeout($sock, 3); // 读超时 3s，防 SFU 不关连接时阻塞 WS worker
        $payload = json_encode(['room_id' => $roomId, 'method' => $method] + $body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('sfu_unreachable');
        }
        $req = "POST /signal HTTP/1.1\r\nHost: sfu\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload;
        fwrite($sock, $req);
        $resp = '';
        while (!feof($sock)) {
            $resp .= fread($sock, 8192);
            $meta = stream_get_meta_data($sock);
            if ($meta['timed_out'] || strlen($resp) > 1_000_000) {
                break;
            }
        }
        fclose($sock);
        $parts = explode("\r\n\r\n", $resp, 2);
        $dec = json_decode($parts[1] ?? '', true);
        return is_array($dec) ? $dec : [];
    }
}
