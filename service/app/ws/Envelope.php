<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\ws;

/** WS 信封：入帧 {type, seq?, data}，出帧 {type, seq?, data} */
final class Envelope
{
    public const T_SEND = 'send';
    public const T_ACK = 'ack';
    public const T_READ = 'read';
    public const T_TYPING = 'typing';
    public const T_RECALL = 'recall';
    public const T_PING = 'ping';
    public const T_MESSAGE = 'message';
    public const T_READY = 'ready';
    public const T_KICKED = 'kicked';
    public const T_PONG = 'pong';
    public const T_ERROR = 'error';
    public const T_CALL_INVITE = 'call_invite';
    public const T_CALL_ACCEPT = 'call_accept';
    public const T_CALL_REJECT = 'call_reject';
    public const T_CALL_CANCEL = 'call_cancel';
    public const T_CALL_TIMEOUT = 'call_timeout';
    public const T_CALL_OFFER = 'call_offer';
    public const T_CALL_ANSWER = 'call_answer';
    public const T_CALL_ICE = 'call_ice';
    public const T_CALL_HANGUP = 'call_hangup';
    public const T_CALL_FAILED = 'call_failed';
    public const T_ROOM_JOIN = 'room_join';
    public const T_ROOM_LEAVE = 'room_leave';
    public const T_ROOM_UP_MIC = 'room_up_mic';
    public const T_ROOM_DOWN_MIC = 'room_down_mic';
    public const T_ROOM_OFFER = 'room_offer';
    public const T_ROOM_ANSWER = 'room_answer';
    public const T_ROOM_ICE = 'room_ice';
    public const T_ROOM_KICK_MIC = 'room_kick_mic';
    public const T_ROOM_CLOSED = 'room_closed';
    public const T_LIVE_JOIN = 'live_join';
    public const T_LIVE_LEAVE = 'live_leave';
    public const T_DANMAKU_SEND = 'danmaku_send';
    public const T_DANMAKU = 'danmaku';
    public const T_LIVE_MIC_UP = 'live_mic_up';
    public const T_LIVE_MIC_DOWN = 'live_mic_down';
    public const T_LIVE_CLOSED = 'live_closed';
    public const T_LIVE_GIFT = 'live_gift';

    public static function decode(string $raw): ?array
    {
        $env = json_decode($raw, true);
        if (!is_array($env) || !is_string($env['type'] ?? null) || $env['type'] === '') {
            return null;
        }
        return $env;
    }

    public static function encode(string $type, array $data = [], ?int $seq = null): string
    {
        $env = ['type' => $type, 'data' => $data];
        if ($seq !== null) {
            $env['seq'] = $seq;
        }
        return json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
