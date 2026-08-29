<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\StorageProvider;
use Aws\MockHandler;
use Aws\S3\S3Client;

/**
 * M6c CDN 存储薄封装：活动服务商读取（Redis 60s 缓存 → 模型兜底）+
 * put() 写文件（local 落盘 / s3 传桶），失败抛错不回退本地。
 */
class Storage
{
    private const CACHE_KEY = 'storage:active_provider';
    private const CACHE_TTL = 60;

    /** 测试注入：aws-sdk-php MockHandler */
    public static ?MockHandler $handler = null;

    /** 写文件 → 返回可访问 URL（local→相对路径，s3→CDN 绝对 URL）；失败抛错 */
    public static function put(string $key, string $bytes): string
    {
        $p = self::activeProvider();
        if ($p->driver === 'local') {
            $path = public_path() . '/' . $key;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $bytes);
            return '/' . $key;
        }
        self::client($p)->putObject(['Bucket' => $p->bucket, 'Key' => $key, 'Body' => $bytes]);
        return rtrim((string) $p->cdn_url, '/') . '/' . $key;
    }

    /** 活动服务商：Redis 60s 缓存 → DB 兜底（admin 激活切换时清缓存） */
    private static function activeProvider(): StorageProvider
    {
        $p = self::cached();
        if ($p !== null) {
            return $p;
        }
        $p = StorageProvider::where('is_active', 1)->first();
        if (!$p) {
            throw new \RuntimeException('未配置活动的存储服务商');
        }
        self::cache($p);
        return $p;
    }

    private static function cached(): ?StorageProvider
    {
        try {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0);
            $raw = $redis->get(self::CACHE_KEY);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            return new StorageProvider((array) json_decode($raw, true));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cache(StorageProvider $p): void
    {
        try {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0);
            $redis->setex(self::CACHE_KEY, self::CACHE_TTL, json_encode($p->toArray()));
        } catch (\Throwable) {
            // Redis 不可用仅降级为直查 DB
        }
    }

    private static function client(StorageProvider $p): S3Client
    {
        $opts = [
            'version' => 'latest',
            'region' => $p->region ?: 'auto',
            'endpoint' => $p->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $p->key, 'secret' => $p->secret],
        ];
        if (self::$handler !== null) {
            $opts['handler'] = self::$handler;
        }
        return new S3Client($opts);
    }
}
