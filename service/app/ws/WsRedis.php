<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\ws;

/**
 * Redis 短连接封装（对照 JwtHelper 的裸 \Redis 惯例）。
 * ponytail: 每次调用新建连接，Redis 故障时静默返回 null —— 网关降级为本机直推，不因 Redis 挂掉而断线。
 */
final class WsRedis
{
    public static function call(callable $fn)
    {
        try {
            return $fn(self::conn());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function conn(): \Redis
    {
        $redis = new \Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0);
        return $redis;
    }
}
