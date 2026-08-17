<?php
namespace app\common;

use Search\V1\SearchServiceClient;
use Search\V1\IndexRequest;
use Search\V1\SearchRequest;
use Workerman\Timer;

class SearchSync
{
    private static ?SearchServiceClient $client = null;

    private static function client(): ?SearchServiceClient
    {
        if (self::$client === null) {
            $host = config('plugin.erikwang2013.search.app.host') ?? '127.0.0.1:50051';
            self::$client = new SearchServiceClient($host, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
        }
        return self::$client;
    }

    public static function indexPost(int $postId, string $content): void
    {
        // ponytail: fire-and-forget，ES/grpc 不可用静默降级；上线后可换队列
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
