<?php
namespace app\common;

use Search\V1\SearchServiceClient;
use Search\V1\IndexRequest;
use Search\V1\SearchRequest;
use Grpc\Health\V1\HealthClient;
use Grpc\Health\V1\HealthCheckRequest;
use Grpc\Health\V1\HealthCheckResponse;
use Workerman\Timer;

class SearchSync
{
    private static ?SearchServiceClient $client = null;
    private static ?bool $healthy = null;
    private static bool $watchRegistered = false;

    private static function client(): ?SearchServiceClient
    {
        if (self::$client === null) {
            $host = config('plugin.erikwang2013.search.app.host') ?? '127.0.0.1:50051';
            self::$client = new SearchServiceClient($host, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
        }
        return self::$client;
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
        try {
            $req = new HealthCheckRequest();
            $req->setService('social.search.v1.SearchService');
            $host = config('plugin.erikwang2013.search.app.host') ?? '127.0.0.1:50051';
            $client = new HealthClient($host, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
            [$resp, $status] = $client->Check($req)->wait();
            self::$healthy = $status && $status->code === \Grpc\STATUS_OK
                && $resp && $resp->getStatus() === \Grpc\Health\V1\HealthCheckResponse\ServingStatus::SERVING;
        } catch (\Throwable $e) {
            self::$healthy = false;
        }
    }

    private static function ready(): bool
    {
        self::ensureHealthWatch();
        return self::$healthy === true;
    }

    public static function indexPost(int $postId, string $content): void
    {
        // ponytail: fire-and-forget，ES/grpc 不可用静默降级；上线后可换队列
        if (!self::ready()) {
            return;
        }
        try {
            $req = new IndexRequest();
            $req->setIndex('posts');
            $req->setId($postId);
            $req->setJson(json_encode(['id' => $postId, 'content' => $content], JSON_UNESCAPED_UNICODE));
            $call = self::client()->Index($req);
            $call->start();
            Timer::add(0.1, function () use ($call) { $call->wait(); }, [], false);
        } catch (\Throwable $e) {
        }
    }

    public static function searchPostIds(string $query, int $from, int $size): array
    {
        if (!self::ready()) {
            return [];
        }
        try {
            $req = new SearchRequest();
            $req->setIndex('posts');
            $req->setQuery($query);
            $req->setFrom($from);
            $req->setSize($size);
            [$resp, $status] = self::client()->Search($req)->wait();
            if (!$resp) {
                return [];
            }
            $ids = [];
            foreach ($resp->getHits() as $hit) {
                $ids[] = (int) $hit->getId();
            }
            return $ids;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
