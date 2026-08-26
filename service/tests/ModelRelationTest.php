<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\model\CallRecord;
use app\model\Conversation;
use app\model\ConversationMember;
use app\model\DeviceToken;
use app\model\Message;
use app\model\VoiceRoom;
use app\model\VoiceRoomMember;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

class ModelRelationTest extends TestCase
{
    public function testCallRecordCreateAndStatusCast(): void
    {
        $rec = CallRecord::create(['caller_id' => 1, 'callee_id' => 2, 'status' => 2]);
        $this->assertSame(2, (int) $rec->status);
        $this->assertIsInt($rec->status);
        $this->assertSame(1, (int) CallRecord::where('caller_id', 1)->first()->caller_id);
    }

    public function testDeviceTokenUniquePerUserPlatform(): void
    {
        DeviceToken::create(['user_id' => 1, 'platform' => 'ios', 'token' => 't1']);
        DeviceToken::create(['user_id' => 1, 'platform' => 'android', 'token' => 't2']);
        // 全进程共享 sqlite：其他文件（ImControllerTest）也可能写过 token → 按 user 计数
        $this->assertSame(2, DeviceToken::where('user_id', 1)->count());
        $this->expectException(QueryException::class);
        DeviceToken::create(['user_id' => 1, 'platform' => 'ios', 'token' => 't3']);
    }

    public function testVoiceRoomMemberUnique(): void
    {
        $room = VoiceRoom::create(['owner_id' => 1, 'name' => '房', 'status' => 1]);
        VoiceRoomMember::create(['room_id' => $room->id, 'user_id' => 2, 'role' => 0]);
        $this->expectException(QueryException::class);
        VoiceRoomMember::create(['room_id' => $room->id, 'user_id' => 2, 'role' => 1]);
    }

    public function testConversationMemberDefaults(): void
    {
        $conv = Conversation::create(['type' => 1, 'name' => '群', 'owner_id' => 1, 'status' => 1]);
        $member = ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => 3]);
        // DB 默认值（模型属性不含未赋值字段，需重取）
        $fresh = ConversationMember::find($member->id);
        $this->assertSame(0, (int) $fresh->role);
        $this->assertSame(1, (int) $fresh->status);
        // Deliverer 只推 status=1 的成员
        ConversationMember::where('conversation_id', $conv->id)->update(['status' => 0]);
        $this->assertSame([], ConversationMember::where('conversation_id', $conv->id)->where('status', 1)->pluck('user_id')->all());
    }

    public function testMessageCasts(): void
    {
        $msg = Message::create([
            'conversation_id' => 1, 'sender_id' => 1, 'client_msg_id' => 'cast-1',
            'type' => 3, 'content' => null, 'voice_url' => '/voice/' . str_repeat('cd', 16) . '.m4a',
            'voice_duration' => '15', 'recall_status' => 0, 'recall_at' => null,
        ]);
        $this->assertIsInt($msg->type);
        $this->assertIsInt($msg->voice_duration);
        $this->assertIsInt($msg->recall_status);
        $this->assertSame(15, $msg->voice_duration);
    }

    public function testVoiceRoomCastStatus(): void
    {
        $room = VoiceRoom::create(['owner_id' => 1, 'name' => 'x', 'status' => '1']);
        $this->assertIsInt($room->status);
        $this->assertSame(1, $room->status);
    }
}
