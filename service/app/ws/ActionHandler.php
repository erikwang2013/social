<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\ws;

use app\model\Message;
use app\model\MessageRead;
use Illuminate\Database\QueryException;

/** 入帧动作分发：ping/send/read/typing/recall，未知类型回 error 帧 */
final class ActionHandler
{
    public static function handle(int $fd, int $uid, array $env): void
    {
        $type = $env['type'];
        $data = is_array($env['data'] ?? null) ? $env['data'] : [];
        $seq = isset($env['seq']) ? (int) $env['seq'] : null;
        switch ($type) {
            case Envelope::T_PING:
                ConnectionRegistry::heartbeat($fd, $uid);
                self::send($fd, Envelope::T_PONG, [], $seq);
                break;
            case Envelope::T_SEND:
                self::doSend($fd, $uid, $data, $seq);
                break;
            case Envelope::T_READ:
                self::doRead($fd, $uid, $data, $seq);
                break;
            case Envelope::T_TYPING:
                self::doTyping($fd, $uid, $data);
                break;
            case Envelope::T_RECALL:
                self::doRecall($fd, $uid, $data, $seq);
                break;
            case Envelope::T_CALL_INVITE:
            case Envelope::T_CALL_ACCEPT:
            case Envelope::T_CALL_REJECT:
            case Envelope::T_CALL_CANCEL:
            case Envelope::T_CALL_HANGUP:
            case Envelope::T_CALL_FAILED:
            case Envelope::T_CALL_OFFER:
            case Envelope::T_CALL_ANSWER:
            case Envelope::T_CALL_ICE:
                self::handleCall($fd, $uid, $type, $data);
                break;
            case Envelope::T_ROOM_JOIN:
            case Envelope::T_ROOM_LEAVE:
            case Envelope::T_ROOM_UP_MIC:
            case Envelope::T_ROOM_DOWN_MIC:
            case Envelope::T_ROOM_KICK_MIC:
            case Envelope::T_ROOM_OFFER:
            case Envelope::T_ROOM_ANSWER:
            case Envelope::T_ROOM_ICE:
                self::handleRoom($fd, $uid, $type, $data);
                break;
            default:
                self::send($fd, Envelope::T_ERROR, ['msg' => 'unknown type'], $seq);
        }
    }

    private static function doSend(int $fd, int $uid, array $data, ?int $seq): void
    {
        $cid = (int) ($data['conversation_id'] ?? 0);
        $cmid = trim((string) ($data['client_msg_id'] ?? ''));
        $content = $data['content'] ?? null;
        $imageUrl = (string) ($data['image_url'] ?? '');
        $voiceUrl = (string) ($data['voice_url'] ?? '');
        $voiceDuration = (int) ($data['voice_duration'] ?? 0);
        if ($cid <= 0 || $cmid === '' || (($content === null || $content === '') && $imageUrl === '' && $voiceUrl === '')) {
            self::send($fd, Envelope::T_ERROR, ['msg' => 'invalid send payload'], $seq);
            return;
        }
        if ($voiceUrl !== '' && !preg_match('#^/voice/[a-f0-9]{32}\.m4a$#', $voiceUrl)) {
            self::send($fd, Envelope::T_ERROR, ['msg' => 'invalid voice url'], $seq);
            return;
        }
        // SETNX 幂等：重复 client_msg_id 直接回已存在消息的 ack（键 TTL 24h，DB 唯一索引兜底并发）
        $claimed = WsRedis::call(fn ($r) => $r->set('im:dedup:' . $uid . ':' . $cmid, '1', ['NX', 'EX' => 86400]));
        if ($claimed === false) {
            $existing = Message::where('client_msg_id', $cmid)->first();
            if ($existing !== null) {
                self::send($fd, Envelope::T_ACK, ['client_msg_id' => $cmid, 'message_id' => (int) $existing->id], $seq);
                return;
            }
        }
        try {
            $msg = Message::create([
                'conversation_id' => $cid,
                'sender_id' => $uid,
                'client_msg_id' => $cmid,
                'type' => $voiceUrl !== '' ? 3 : ($imageUrl !== '' ? 2 : 1),
                'content' => $content,
                'image_url' => $imageUrl,
                'voice_url' => $voiceUrl,
                'voice_duration' => $voiceDuration,
                'recall_status' => 0,
                'recall_at' => null,
            ]);
        } catch (QueryException $e) {
            $msg = Message::where('client_msg_id', $cmid)->first();
            if ($msg === null) {
                throw $e;
            }
        }
        Deliverer::deliver($msg, $uid);
        self::send($fd, Envelope::T_ACK, ['client_msg_id' => $cmid, 'message_id' => (int) $msg->id], $seq);
    }

    private static function doRead(int $fd, int $uid, array $data, ?int $seq): void
    {
        $cid = (int) ($data['conversation_id'] ?? 0);
        $lastReadId = (int) ($data['last_read_id'] ?? 0);
        if ($cid <= 0 || $lastReadId <= 0) {
            self::send($fd, Envelope::T_ERROR, ['msg' => 'invalid read payload'], $seq);
            return;
        }
        MessageRead::advance($cid, $uid, $lastReadId);
        Deliverer::notifyRead($cid, $uid, $lastReadId);
    }

    private static function doTyping(int $fd, int $uid, array $data): void
    {
        $cid = (int) ($data['conversation_id'] ?? 0);
        if ($cid <= 0) {
            self::send($fd, Envelope::T_ERROR, ['msg' => 'invalid typing payload']);
            return;
        }
        Deliverer::relayTyping($cid, $uid);
    }

    private static function doRecall(int $fd, int $uid, array $data, ?int $seq): void
    {
        $mid = (int) ($data['message_id'] ?? 0);
        if ($mid <= 0) {
            self::send($fd, Envelope::T_ERROR, ['msg' => 'invalid recall payload'], $seq);
            return;
        }
        $msg = Message::where('id', $mid)->where('sender_id', $uid)->first();
        if ($msg === null) {
            self::send($fd, Envelope::T_ERROR, ['msg' => 'message not found'], $seq);
            return;
        }
        if (strtotime((string) $msg->created_at) < time() - 120) {
            self::send($fd, Envelope::T_ERROR, ['msg' => 'recall expired'], $seq);
            return;
        }
        $msg->update(['recall_status' => 1, 'recall_at' => date('Y-m-d H:i:s')]);
        Deliverer::notifyRecall((int) $msg->conversation_id, $mid, $uid);
    }

    /** 1v1 通话信令：start 的 already_in_call 等异常回 error 帧，其余静默（无状态机拒绝即忽略） */
    private static function handleCall(int $fd, int $uid, string $type, array $data): void
    {
        static $cc = null;
        $cc ??= new \app\call\CallCenter();
        $callId = (int) ($data['call_id'] ?? 0);
        try {
            switch ($type) {
                case 'call_invite':
                    $cc->start($uid, (int) ($data['to_user_id'] ?? 0));
                    break;
                case 'call_accept':
                    $cc->accept($callId, $uid);
                    break;
                case 'call_reject':
                    $cc->reject($callId, $uid);
                    break;
                case 'call_cancel':
                    $cc->cancel($callId, $uid);
                    break;
                case 'call_hangup':
                    $cc->hangup($callId, $uid);
                    break;
                case 'call_failed':
                    $cc->failed($callId, $uid);
                    break;
                case 'call_offer':
                case 'call_answer':
                case 'call_ice':
                    $cc->relay($callId, $uid, $type, $data);
                    break;
            }
        } catch (\RuntimeException $e) {
            self::send($fd, Envelope::T_ERROR, ['msg' => $e->getMessage()]);
        }
    }

    /** 语聊房：room_not_found/room_mic_full/room_forbidden/sfu_unreachable 回 error 帧，其余静默 */
    private static function handleRoom(int $fd, int $uid, string $type, array $data): void
    {
        static $rc = null;
        $rc ??= new \app\room\RoomCenter();
        $roomId = (int) ($data['room_id'] ?? 0);
        try {
            switch ($type) {
                case 'room_join':
                    $rc->join($roomId, $uid);
                    break;
                case 'room_leave':
                    $rc->leave($roomId, $uid);
                    break;
                case 'room_up_mic':
                    $rc->upMic($roomId, $uid);
                    break;
                case 'room_down_mic':
                    $rc->downMic($roomId, $uid);
                    break;
                case 'room_kick_mic':
                    $rc->kickMic($roomId, $uid, (int) ($data['user_id'] ?? 0));
                    break;
                case 'room_offer':
                case 'room_answer':
                case 'room_ice':
                    $rc->sfuRelay($roomId, $uid, $type, $data);
                    break;
            }
        } catch (\RuntimeException $e) {
            self::send($fd, Envelope::T_ERROR, ['msg' => $e->getMessage()]);
        }
    }

    private static function send(int $fd, string $type, array $data = [], ?int $seq = null): void
    {
        WsServer::sendToFd($fd, Envelope::encode($type, $data, $seq));
    }
}
