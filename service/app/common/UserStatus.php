<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\User;

/** 账号状态短缓存：封禁最迟 30s 生效，避免每帧 WS 消息都查库 */
final class UserStatus
{
    private const TTL = 30;
    private const MAX_ENTRIES = 10000; // shortcut: 每进程最多缓存 1 万用户，超限整体清空

    /** @var array<int, array{0: bool, 1: int}> uid => [active, expires_at] */
    private static array $cache = [];

    public static function isActive(int $uid): bool
    {
        $now = time();
        $hit = self::$cache[$uid] ?? null;
        if ($hit !== null && $hit[1] > $now) {
            return $hit[0];
        }
        if (count(self::$cache) >= self::MAX_ENTRIES) {
            self::$cache = [];
        }
        $active = User::where('id', $uid)->where('status', 1)->exists();
        self::$cache[$uid] = [$active, $now + self::TTL];
        return $active;
    }
}
