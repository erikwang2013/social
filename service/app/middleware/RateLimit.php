<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 按 IP+路径的固定窗口限流；Redis 故障时放行 */
class RateLimit implements MiddlewareInterface
{
    private const LIMITS = [
        '/api/v1/auth/register' => [5, 60],
        '/api/v1/auth/login' => [10, 60],
        '/api/v1/im/image' => [10, 60],
        '/api/v1/im/voice' => [10, 60],
    ];
    private const DEFAULT = [60, 60];

    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();
        // 支付回调由渠道签名保护，且高并发时不能被限流挡住
        if ($path === '/health' || str_starts_with($path, '/api/v1/payment/callback/')) {
            return $handler($request);
        }
        [$limit, $window] = self::LIMITS[$path] ?? self::DEFAULT;
        $safe = preg_replace('/[^a-zA-Z0-9_\/-]/', '_', $path);
        $key = 'rate:api:' . $request->getRealIp() . ':' . $safe;
        try {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0);
            $count = (int) $redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, $window);
            }
        } catch (\Throwable) {
            return $handler($request);
        }
        if ($count > $limit) {
            return json(['code' => 429, 'message' => '请求过于频繁', 'lang_key' => 'rate_limited'], 429)
                ->withHeader('Retry-After', (string) $window);
        }
        return $handler($request);
    }
}
