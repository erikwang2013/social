<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\model\Conversation;
use app\model\ConversationMember;
use app\model\Message;
use app\ws\ConnectionRegistry;
use app\ws\Deliverer;
use app\ws\NullPushProvider;
use app\ws\PushProvider;
use PHPUnit\Framework\TestCase;

// ponytail: 不 mock Redis —— Redis 不可用时离线入队静默跳过，仅断言内存直推帧与 PushProvider 调用计数
class DelivererTest extends TestCase
{
    protected function setUp(): void
    {
        ConnectionRegistry::reset();
        CountingPushProvider::$calls = 0;
        CountingPushProvider::$payloads = [];
        Deliverer::$pushProviderClass = CountingPushProvider::class;
        Deliverer::$pushProvider = null;
    }

    protected function tearDown(): void
    {
        Deliverer::$sendToFd = null;
        Deliverer::$pushProviderClass = NullPushProvider::class;
        Deliverer::$pushProvider = null;
        ConnectionRegistry::reset();
    }

    private function conversationWithMembers(int ...$uids): Conversation
    {
        $conv = Conversation::create(['type' => 1, 'name' => '', 'owner_id' => 0, 'status' => 1]);
        foreach ($uids as $uid) {
            ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $uid, 'role' => 0, 'status' => 1]);
        }
        return $conv;
    }

    private function message(int $convId, string $cmid): Message
    {
        return Message::create([
            'conversation_id' => $convId,
            'sender_id' => 1,
            'client_msg_id' => $cmid,
            'type' => 1,
            'content' => 'hello',
            'image_url' => '',
            'recall_status' => 0,
            'recall_at' => null,
        ]);
    }

    public function testDeliverDirectPushToOnlineMember(): void
    {
        $conv = $this->conversationWithMembers(1, 2, 3);
        $msg = $this->message($conv->id, 'c-1');
        ConnectionRegistry::attach(99, 2); // uid 2 在线
        $frames = [];
        Deliverer::$sendToFd = function (int $fd, string $frame) use (&$frames): void {
            $frames[] = [$fd, $frame];
        };

        Deliverer::deliver($msg, 1);

        $this->assertCount(1, $frames); // 仅在线成员 2 收到直推
        [$fd, $frame] = $frames[0];
        $this->assertSame(99, $fd);
        $env = json_decode($frame, true);
        $this->assertSame('message', $env['type']);
        $this->assertSame((int) $msg->id, $env['data']['message_id']);
        $this->assertSame('hello', $env['data']['content']);
        $this->assertSame(2, CountingPushProvider::$calls); // 离线成员 1(sender)、3 走离线队列
    }

    public function testDeliverOfflineMemberTriggersPushProvider(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        $msg = $this->message($conv->id, 'c-2');
        Deliverer::$sendToFd = function (int $fd, string $frame): void {
        };

        Deliverer::deliver($msg, 1);

        $this->assertSame(2, CountingPushProvider::$calls);
        [$uid, $payload] = CountingPushProvider::$payloads[0];
        $this->assertSame(1, $uid);
        $this->assertSame('message', $payload['type']);
        $this->assertSame('hello', $payload['data']['content']);
    }

    public function testNotifyRecallQueuesForOfflineMembers(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        Deliverer::$sendToFd = function (int $fd, string $frame): void {
        };

        Deliverer::notifyRecall($conv->id, 42, 1);

        $this->assertSame(2, CountingPushProvider::$calls); // 在线与否未知时全员走离线队列
        [$uid, $payload] = CountingPushProvider::$payloads[0];
        $this->assertSame(1, $uid);
        $this->assertSame('recall', $payload['type']);
        $this->assertSame(42, $payload['data']['message_id']);
    }
}

class CountingPushProvider implements PushProvider
{
    public static int $calls = 0;
    public static array $payloads = [];

    public function send(int $uid, array $payload): void
    {
        self::$calls++;
        self::$payloads[] = [$uid, $payload];
    }
}
