<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use Grpc\Health\V1\HealthCheckRequest;
use Grpc\Health\V1\HealthCheckResponse;
use Grpc\Health\V1\HealthClient;
use Live\V1\LiveServiceClient;
use Live\V1\VoiceServiceClient;
use support\Response;
use Workerman\Timer;

/**
 * M6: PHP live/voice 控制器 → Rust social_grpc 的 gRPC 桥（对齐 SearchSync 模式）。
 * 两个服务（LiveService/VoiceService）任一不健康即整体降级 503。
 */
class LiveSync
{
    private static ?LiveServiceClient $live = null;
    private static ?VoiceServiceClient $voice = null;
    private static ?bool $healthy = null;
    private static bool $watchRegistered = false;

    private static function host(): string
    {
        return (string) (config('live.grpc_host') ?? '127.0.0.1:50051');
    }

    private static function live(): LiveServiceClient
    {
        if (self::$live === null) {
            self::$live = new LiveServiceClient(self::host(), ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
        }
        return self::$live;
    }

    private static function voice(): VoiceServiceClient
    {
        if (self::$voice === null) {
            self::$voice = new VoiceServiceClient(self::host(), ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
        }
        return self::$voice;
    }

    private static function ensureHealthWatch(): void
    {
        if (self::$watchRegistered) {
            return;
        }
        self::$watchRegistered = true;
        self::probe();
        try {
            Timer::add(30, fn() => self::probe());
        } catch (\Throwable $e) {
            // CLI/测试环境无 workerman 事件循环，跳过周期探活（首次 probe 已覆盖）
        }
    }

    private static function probe(): void
    {
        $host = self::host();
        $serving = function (string $service) use ($host): bool {
            try {
                $req = new HealthCheckRequest();
                $req->setService($service);
                $client = new HealthClient($host, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
                [$resp, $status] = $client->Check($req)->wait();
                return $status && $status->code === \Grpc\STATUS_OK
                    && $resp && $resp->getStatus() === HealthCheckResponse\ServingStatus::SERVING;
            } catch (\Throwable $e) {
                return false;
            }
        };
        self::$healthy = $serving('social.live.v1.LiveService') && $serving('social.live.v1.VoiceService');
    }

    private static function ready(): bool
    {
        self::ensureHealthWatch();
        return self::$healthy === true;
    }

    /** 执行 RPC → ['code','message','lang_key','data_json','bytes_data']；不可用/异常返回 null（调用方走 degraded） */
    private static function rpc(callable $fn): ?array
    {
        if (!self::ready()) {
            return null;
        }
        try {
            [$reply, $status] = $fn()->wait();
            if (!$reply || !$status || $status->code !== \Grpc\STATUS_OK) {
                return null;
            }
            return [
                'code' => $reply->getCode(),
                'message' => $reply->getMessage(),
                'lang_key' => $reply->getLangKey(),
                'data_json' => $reply->getDataJson(),
                'bytes_data' => $reply->getBytesData(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function liveRpc(callable $fn): ?array
    {
        return self::rpc(fn() => $fn(self::live()));
    }

    public static function voiceRpc(callable $fn): ?array
    {
        return self::rpc(fn() => $fn(self::voice()));
    }

    /** LiveReply → JSON Response；code=0 时 data 为解码后的 data_json（与原 PHP 控制器响应形状一致） */
    public static function respond(?array $r): Response
    {
        if ($r === null) {
            return self::degraded();
        }
        if ($r['code'] !== 0) {
            return json([
                'code' => $r['code'],
                'message' => $r['message'],
                'lang_key' => $r['lang_key'],
            ], $r['code']);
        }
        return json([
            'code' => 0,
            'message' => 'ok',
            'lang_key' => 'ok',
            'data' => json_decode($r['data_json'], true) ?? new \stdClass(),
        ]);
    }

    /** gRPC 服务不可用时的降级响应 */
    public static function degraded(): Response
    {
        return json(['code' => 503, 'message' => '服务繁忙，请稍后重试', 'lang_key' => 'degraded'], 503);
    }
}
