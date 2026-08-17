<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\model\Conversation;
use app\model\ConversationMember;
use app\model\Message;
use app\model\MessageRead;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

class ImModelTest extends TestCase
{
    public function testConversationWithMembers()
    {
        $conv = Conversation::create(['type' => 1, 'name' => '', 'owner_id' => 0, 'status' => 1]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => 1, 'role' => 0, 'status' => 1]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => 2, 'role' => 0, 'status' => 1]);

        $this->assertCount(2, $conv->members);
        $this->assertSame(1, $conv->type);
        $this->assertSame(2, ConversationMember::where('conversation_id', $conv->id)->count());
    }

    public function testMessageClientMsgIdUniqueConstraint()
    {
        $conv = Conversation::create(['type' => 1, 'name' => '', 'owner_id' => 0, 'status' => 1]);
        $attrs = [
            'conversation_id' => $conv->id,
            'sender_id' => 1,
            'client_msg_id' => 'cmid-1',
            'type' => 1,
            'content' => 'hello',
            'image_url' => '',
            'recall_status' => 0,
            'recall_at' => null,
        ];
        Message::create($attrs);
        $this->expectException(QueryException::class);
        Message::create($attrs);
    }

    public function testMessageReadAdvanceKeepsMax()
    {
        $conv = Conversation::create(['type' => 1, 'name' => '', 'owner_id' => 0, 'status' => 1]);
        MessageRead::advance($conv->id, 1, 5);
        MessageRead::advance($conv->id, 1, 10);
        $this->assertSame(10, (int) MessageRead::where('conversation_id', $conv->id)->where('user_id', 1)->value('last_read_id'));

        // 更小的游标不回调（已读单调）
        MessageRead::advance($conv->id, 1, 7);
        $this->assertSame(10, (int) MessageRead::where('conversation_id', $conv->id)->where('user_id', 1)->value('last_read_id'));
    }
}
