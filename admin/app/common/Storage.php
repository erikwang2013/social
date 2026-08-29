<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\common;

use Aws\MockHandler;
use Aws\S3\S3Client;
use app\model\StorageProvider;
use Throwable;

/**
 * CDN 存储薄封装（admin 侧）：活动服务商读取 + S3 验签 client + 激活后清 service 缓存。
 * 上传 put() 见 M6c2（与 service 侧 Storage 同构，provider 从 Eloquent 模型读）。
 */
class Storage
{
    private const CACHE_KEY = 'storage:active_provider';

    /** 测试注入：aws-sdk-php MockHandler（activate 验签单测用） */
    public static ?MockHandler $handler = null;

    public static function activeProvider(): StorageProvider
    {
        $p = StorageProvider::where('is_active', 1)->first();
        if (!$p) {
            throw new \RuntimeException('未配置活动的存储服务商');
        }
        return $p;
    }

    /** activate 时验签：列桶前 1 个对象，失败即抛（配错立即反馈，不回退） */
    public static function verify(StorageProvider $p): void
    {
        if ($p->driver !== 's3') {
            return;
        }
        self::client($p)->listObjectsV2(['Bucket' => $p->bucket, 'MaxKeys' => 1]);
    }

    /** 写文件 → 返回可访问 URL（local→相对路径，s3→CDN 绝对 URL）；put 失败抛错，不回退本地 */
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

    /** 激活切换后清 service 侧 60s 缓存（裸 \Redis 短连接，与 service 同键；故障静默） */
    public static function clearCache(): void
    {
        try {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0);
            $redis->del(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    public static function client(StorageProvider $p): S3Client
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
