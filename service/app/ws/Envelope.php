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
