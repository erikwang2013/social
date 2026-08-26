<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\model\Conversation;
use app\model\ConversationMember;
use app\model\Message;
use app\model\MessageRead;
use app\ws\ActionHandler;
use app\ws\ConnectionRegistry;
use app\ws\Envelope;
use app\ws\WsServer;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Worker;

/** WS 入帧处理器：ping/send/read/typing/recall/room/call 分发与错误帧 */
class ActionHandlerTest extends TestCase
{
    private int $fd = 0;
    private $conn = null;
    private array $workersSnapshot = [];

    protected function setUp(): void
    {
        ConnectionRegistry::reset();
        // 快照 Worker 静态注册表：new Worker() 会污染全局（令后续测试的
        // Worker::getAllWorkers() 非空 → CallCenter 会走 pcntl_alarm 定时路径导致挂起）
        $this->workersSnapshot = Worker::getAllWorkers();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->conn = new class(new Select(), $pair[0]) extends TcpConnection {
            public array $sent = [];
            public function send(mixed $sendBuffer, bool $raw = false): ?bool
            {
                $this->sent[] = $sendBuffer;
                return true;
            }
        };
        $worker = new Worker();
        $worker->connections[$this->conn->id] = $this->conn;
        (new ReflectionProperty(WsServer::class, 'worker'))->setValue(null, $worker);
        ConnectionRegistry::attach($this->conn->id, 1);
        $this->fd = $this->conn->id;
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(WsServer::class, 'worker'))->setValue(null, null);
        (new ReflectionProperty(Worker::class, 'workers'))->setValue(null, $this->workersSnapshot);
        (new ReflectionProperty(Worker::class, 'pidMap'))->setValue(null, []);
        ConnectionRegistry::reset();
    }

    private function handle(array $env): void
    {
        ActionHandler::handle($this->fd, 1, $env);
    }

    private function lastFrame(): array
    {
        return json_decode(end($this->conn->sent), true);
    }

    private function conversationWithMembers(int ...$uids): Conversation
    {
        $conv = Conversation::create(['type' => 1, 'name' => '', 'owner_id' => 0, 'status' => 1]);
        foreach ($uids as $uid) {
            ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $uid, 'role' => 0, 'status' => 1]);
        }
        return $conv;
    }

    public function testPingReturnsPongWithSeq(): void
    {
        $this->handle(['type' => 'ping', 'seq' => 7]);
        $frame = $this->lastFrame();
        $this->assertSame('pong', $frame['type']);
        $this->assertSame(7, $frame['seq']);
    }

    public function testUnknownTypeReturnsError(): void
    {
        $this->handle(['type' => 'hack']);
        $this->assertSame('error', $this->lastFrame()['type']);
    }

    public function testSendInvalidPayload(): void
    {
        $this->handle(['type' => 'send', 'data' => ['conversation_id' => 1]]);
        $this->assertSame('invalid send payload', $this->lastFrame()['data']['msg']);
    }

    public function testSendRejectsBadVoiceUrl(): void
    {
        $this->handle(['type' => 'send', 'data' => [
            'conversation_id' => 1, 'client_msg_id' => 'c1', 'voice_url' => '/etc/passwd',
        ]]);
        $this->assertSame('invalid voice url', $this->lastFrame()['data']['msg']);
        $this->assertSame(0, Message::where('client_msg_id', 'c1')->count());
    }

    public function testSendTextAckAndDeliver(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        $this->handle(['type' => 'send', 'seq' => 3, 'data' => [
            'conversation_id' => $conv->id, 'client_msg_id' => 'c-msg-1', 'content' => '你好',
        ]]);
        $ack = $this->lastFrame();
        $this->assertSame('ack', $ack['type']);
        $this->assertSame(3, $ack['seq']);
        $this->assertSame('c-msg-1', $ack['data']['client_msg_id']);
        $msg = Message::find($ack['data']['message_id']);
        $this->assertSame(1, (int) $msg->type);
        $this->assertSame('你好', $msg->content);
        $this->assertSame(1, (int) $msg->sender_id);
    }

    public function testSendVoiceType(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        $this->handle(['type' => 'send', 'data' => [
            'conversation_id' => $conv->id, 'client_msg_id' => 'c-voice',
            'voice_url' => '/voice/' . str_repeat('ab', 16) . '.m4a', 'voice_duration' => 12,
        ]]);
        $ack = $this->lastFrame();
        $this->assertSame(3, (int) Message::find($ack['data']['message_id'])->type);
        $this->assertSame(12, (int) Message::find($ack['data']['message_id'])->voice_duration);
    }

    public function testSendDuplicateClientMsgIdIdempotent(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        $payload = ['type' => 'send', 'data' => [
            'conversation_id' => $conv->id, 'client_msg_id' => 'dup-1', 'content' => 'x',
        ]];
        $this->handle($payload);
        $first = $this->lastFrame();
        $this->handle($payload);
        $second = $this->lastFrame();
        $this->assertSame($first['data']['message_id'], $second['data']['message_id']);
        $this->assertSame(1, Message::where('client_msg_id', 'dup-1')->count());
    }

    public function testReadAdvancesCursor(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        $this->handle(['type' => 'read', 'data' => ['conversation_id' => $conv->id, 'last_read_id' => 9]]);
        $this->assertSame(9, (int) MessageRead::where('conversation_id', $conv->id)->where('user_id', 1)->value('last_read_id'));
    }

    public function testRecallNotSender(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        $msg = Message::create(['conversation_id' => $conv->id, 'sender_id' => 2, 'client_msg_id' => 'r1', 'type' => 1, 'content' => 'hi']);
        $this->handle(['type' => 'recall', 'data' => ['message_id' => $msg->id]]);
        $this->assertSame('message not found', $this->lastFrame()['data']['msg']);
    }

    public function testRecallExpired(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        $msg = Message::create(['conversation_id' => $conv->id, 'sender_id' => 1, 'client_msg_id' => 'r2', 'type' => 1, 'content' => 'hi']);
        // created_at 非 fillable，模型 update 会静默丢弃 → 用原生查询回拨时间
        \Illuminate\Database\Capsule\Manager::table('messages')->where('id', $msg->id)
            ->update(['created_at' => date('Y-m-d H:i:s', time() - 300)]);
        $this->handle(['type' => 'recall', 'data' => ['message_id' => $msg->id]]);
        $this->assertSame('recall expired', $this->lastFrame()['data']['msg']);
    }

    public function testRecallSuccess(): void
    {
        $conv = $this->conversationWithMembers(1, 2);
        $msg = Message::create(['conversation_id' => $conv->id, 'sender_id' => 1, 'client_msg_id' => 'r3', 'type' => 1, 'content' => 'hi']);
        $this->handle(['type' => 'recall', 'data' => ['message_id' => $msg->id]]);
        $this->assertSame(1, (int) Message::find($msg->id)->recall_status);
        $this->assertSame('recall', $this->lastFrame()['type']);
    }

    public function testRoomJoinUnknownRoomReturnsError(): void
    {
        $this->handle(['type' => 'room_join', 'data' => ['room_id' => 999]]);
        $this->assertSame('room_not_found', $this->lastFrame()['data']['msg']);
    }

    public function testCallInvalidCalleeReturnsError(): void
    {
        $this->handle(['type' => 'call_invite', 'data' => ['to_user_id' => 0]]);
        $this->assertSame('invalid_callee', $this->lastFrame()['data']['msg']);
    }

    public function testTypingInvalidPayloadReturnsError(): void
    {
        $this->handle(['type' => 'typing', 'data' => []]);
        $this->assertSame('invalid typing payload', $this->lastFrame()['data']['msg']);
    }
}
