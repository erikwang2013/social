<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\ws;

/**
 * 本机连接表 + Redis 注册（单连接策略：同 uid 新连接踢旧连接）。
 * Redis 注册失败静默（键 EXPIRE 60s 兜底），连接生命周期不因 Redis 故障中断。
 */
final class ConnectionRegistry
{
    private static array $fdToUid = []; // fd => uid
    private static array $uidToFd = []; // uid => fd
    private static array $fdToSid = []; // fd => sid（登出踢连接时避免误踢新会话）
    private static string $nodeId = '';

    public static function setNodeId(string $nodeId): void
    {
        self::$nodeId = $nodeId;
    }

    public static function nodeId(): string
    {
        return self::$nodeId !== '' ? self::$nodeId : gethostname();
    }

    /** @return int|null 被替换的旧 fd（供踢出），无则 null */
    public static function attach(int $fd, int $uid, string $sid = ''): ?int
    {
        $oldFd = self::$uidToFd[$uid] ?? null;
        if ($oldFd !== null && $oldFd !== $fd) {
            unset(self::$fdToUid[$oldFd], self::$fdToSid[$oldFd]);
        }
        self::$fdToUid[$fd] = $uid;
        self::$uidToFd[$uid] = $fd;
        self::$fdToSid[$fd] = $sid;
        self::registerRedis($fd, $uid);
        return $oldFd !== null && $oldFd !== $fd ? $oldFd : null;
    }

    public static function detach(int $fd): void
    {
        $uid = self::$fdToUid[$fd] ?? null;
        unset(self::$fdToUid[$fd], self::$fdToSid[$fd]);
        if ($uid !== null && (self::$uidToFd[$uid] ?? null) === $fd) {
            unset(self::$uidToFd[$uid]);
        }
        WsRedis::call(fn ($r) => $r->del('ws:conn:' . $fd));
        if ($uid !== null) {
            WsRedis::call(function ($r) use ($uid) {
                if ($r->get('ws:user:' . $uid) === self::nodeId()) {
                    $r->del('ws:user:' . $uid);
                }
                return true;
            });
        }
    }

    public static function localFd(int $uid): ?int
    {
        return self::$uidToFd[$uid] ?? null;
    }

    public static function uidFor(int $fd): ?int
    {
        return self::$fdToUid[$fd] ?? null;
    }

    /** 当前本机会话的 sid；用于登出踢连接时避免误踢新会话 */
    public static function sidForUid(int $uid): ?string
    {
        $fd = self::$uidToFd[$uid] ?? null;
        return $fd === null ? null : (self::$fdToSid[$fd] ?? '');
    }

    public static function heartbeat(int $fd, int $uid): void
    {
        if ((self::$fdToUid[$fd] ?? null) === $uid) {
            self::registerRedis($fd, $uid);
        }
    }

    /** 测试隔离用 */
    public static function reset(): void
    {
        self::$fdToUid = [];
        self::$uidToFd = [];
        self::$fdToSid = [];
    }

    private static function registerRedis(int $fd, int $uid): void
    {
        WsRedis::call(function ($r) use ($fd, $uid) {
            $r->setex('ws:conn:' . $fd, 60, (string) $uid);
            $r->setex('ws:user:' . $uid, 60, self::nodeId());
            return true;
        });
    }
}
