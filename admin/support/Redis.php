<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace support;

use Redis as RedisClient;
use RuntimeException;

/**
 * Redis 工具类 — 单例连接池
 */
class Redis
{
    private const DEFAULT_PREFIX = 'open-admin:';

    private static ?string $prefix = null;
    private static ?RedisClient $instance = null;

    private static function getPrefix(): string
    {
        if (self::$prefix === null) {
            $env = getenv('REDIS_PREFIX');
            self::$prefix = ($env !== false && $env !== '') ? $env : self::DEFAULT_PREFIX;
        }
        return self::$prefix;
    }

    private static function getInstance(): RedisClient
    {
        if (self::$instance !== null) {
            try {
                self::$instance->ping();
                return self::$instance;
            } catch (\Throwable) {
                self::$instance = null;
            }
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $pass = getenv('REDIS_PASSWORD') ?: null;
        $db   = (int)(getenv('REDIS_DB') ?: 0);

        $redis = new RedisClient();
        if (!$redis->connect($host, $port)) {
            throw new RuntimeException("Redis connect failed: {$host}:{$port}");
        }
        if ($pass !== null && $pass !== '') {
            $redis->auth($pass);
        }
        if ($db !== 0) {
            $redis->select($db);
        }

        self::$instance = $redis;
        return self::$instance;
    }

    private static function prefixKey(string $key): string
    {
        if (str_starts_with($key, self::getPrefix())) {
            return $key;
        }
        return self::getPrefix() . $key;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        if ($name === 'eval') {
            // eval(script, keyCount, key1, key2, ..., arg1, arg2, ...)
            // keys start at index 2, count = $arguments[1]
            $keyCount = (int)($arguments[1] ?? 0);
            for ($i = 0; $i < $keyCount; $i++) {
                $idx = 2 + $i;
                $arguments[$idx] = self::prefixKey((string)$arguments[$idx]);
            }
        } elseif (!empty($arguments) && is_string($arguments[0])) {
            $arguments[0] = self::prefixKey($arguments[0]);
        }

        return self::getInstance()->{$name}(...$arguments);
    }
}
