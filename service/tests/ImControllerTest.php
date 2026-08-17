<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;
use app\controller\ImController;
use app\model\Conversation;
use app\model\ConversationMember;
use app\model\DeviceToken;
use app\model\Message;

class ImControllerTest extends TestCase
{
    private function registerUid(string $suffix): int
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setPost(['email' => uniqid() . $suffix, 'password' => 'secret123']);
        (new AuthController())->register($req);
        return \app\model\User::where('email', 'like', '%' . $suffix)->orderByDesc('id')->first()->id;
    }

    public function testCreateConversationAndListWithUnread()
    {
        $uidA = $this->registerUid('@im-a.test');
        $uidB = $this->registerUid('@im-b.test');

        $req = new Request('POST', '/api/v1/im/conversations');
        $req->setPost(['type' => 1, 'member_ids' => [(string) $uidB]]);
        $req->uid = $uidA;
        $res = (new ImController())->create($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $cid = $data['data']['id'];

        // 重复创建私聊复用同一会话
        $req2 = new Request('POST', '/api/v1/im/conversations');
        $req2->setPost(['type' => 1, 'member_ids' => [(string) $uidB]]);
        $req2->uid = $uidA;
        $res2 = (new ImController())->create($req2);
        $data2 = json_decode($res2->rawBody(), true);
        $this->assertSame($cid, $data2['data']['id']);

        Message::create(['conversation_id' => $cid, 'sender_id' => $uidA, 'client_msg_id' => 'm1', 'type' => 1, 'content' => '第一条']);
        Message::create(['conversation_id' => $cid, 'sender_id' => $uidB, 'client_msg_id' => 'm2', 'type' => 1, 'content' => '第二条']);

        $reqList = new Request("GET /api/v1/im/conversations HTTP/1.1\r\n\r\n");
        $reqList->uid = $uidA;
        $resList = (new ImController())->conversations($reqList);
        $dataList = json_decode($resList->rawBody(), true);
        $this->assertSame(0, $dataList['code']);
        $this->assertCount(1, $dataList['data']['list']);
        $conv = $dataList['data']['list'][0];
        $this->assertSame($cid, $conv['id']);
        $this->assertSame(2, $conv['unread']);
        $this->assertSame('第二条', $conv['last_message']['content']);
    }

    public function testMessageHistoryPagination()
    {
        $uidA = $this->registerUid('@im-pa.test');
        $uidB = $this->registerUid('@im-pb.test');

        $req = new Request('POST', '/api/v1/im/conversations');
        $req->setPost(['type' => 1, 'member_ids' => [(string) $uidB]]);
        $req->uid = $uidA;
        $res = (new ImController())->create($req);
        $cid = json_decode($res->rawBody(), true)['data']['id'];

        Message::create(['conversation_id' => $cid, 'sender_id' => $uidA, 'client_msg_id' => 'p1', 'type' => 1, 'content' => '一']);
        Message::create(['conversation_id' => $cid, 'sender_id' => $uidA, 'client_msg_id' => 'p2', 'type' => 1, 'content' => '二']);
        Message::create(['conversation_id' => $cid, 'sender_id' => $uidA, 'client_msg_id' => 'p3', 'type' => 1, 'content' => '三']);

        $reqPage1 = new Request("GET /api/v1/im/conversations/{$cid}/messages?page_size=2 HTTP/1.1\r\n\r\n");
        $reqPage1->uid = $uidB;
        $res1 = (new ImController())->messages($reqPage1, (string) $cid);
        $data1 = json_decode($res1->rawBody(), true);
        $this->assertSame(0, $data1['code']);
        $this->assertCount(2, $data1['data']['list']);
        $this->assertTrue($data1['data']['has_more']);
        $this->assertSame('一', $data1['data']['list'][0]['content']);
        $this->assertSame('二', $data1['data']['list'][1]['content']);
        $next = $data1['data']['next_cursor'];

        $reqPage2 = new Request("GET /api/v1/im/conversations/{$cid}/messages?cursor={$next} HTTP/1.1\r\n\r\n");
        $reqPage2->uid = $uidB;
        $res2 = (new ImController())->messages($reqPage2, (string) $cid);
        $data2 = json_decode($res2->rawBody(), true);
        $this->assertCount(1, $data2['data']['list']);
        $this->assertFalse($data2['data']['has_more']);
        $this->assertSame('三', $data2['data']['list'][0]['content']);
    }

    public function testMessagesMemberCheck404()
    {
        $uidA = $this->registerUid('@im-m404a.test');
        $uidB = $this->registerUid('@im-m404b.test');
        $uidC = $this->registerUid('@im-m404c.test');

        $req = new Request('POST', '/api/v1/im/conversations');
        $req->setPost(['type' => 1, 'member_ids' => [(string) $uidB]]);
        $req->uid = $uidA;
        $res = (new ImController())->create($req);
        $cid = json_decode($res->rawBody(), true)['data']['id'];

        $reqMsg = new Request("GET /api/v1/im/conversations/{$cid}/messages HTTP/1.1\r\n\r\n");
        $reqMsg->uid = $uidC;
        $resMsg = (new ImController())->messages($reqMsg, (string) $cid);
        $data = json_decode($resMsg->rawBody(), true);
        $this->assertSame(404, $data['code']);
    }

    public function testGroupCreateAndMemberLimit()
    {
        $uidA = $this->registerUid('@im-ga.test');
        $uidB = $this->registerUid('@im-gb.test');

        $req = new Request('POST', '/api/v1/im/conversations');
        $req->setPost(['type' => 2, 'name' => '测试群', 'member_ids' => [(string) $uidB]]);
        $req->uid = $uidA;
        $res = (new ImController())->create($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $cid = $data['data']['id'];
        $this->assertSame(2, ConversationMember::where('conversation_id', $cid)->count());
        $this->assertSame(1, ConversationMember::where('conversation_id', $cid)->where('user_id', $uidA)->value('role'));

        $reqOver = new Request('POST', '/api/v1/im/conversations');
        $reqOver->setPost(['type' => 2, 'name' => '超大群', 'member_ids' => range(100000, 100501)]);
        $reqOver->uid = $uidA;
        $resOver = (new ImController())->create($reqOver);
        $this->assertSame(400, json_decode($resOver->rawBody(), true)['code']);
    }

    public function testDeviceTokenUpsert()
    {
        $uid = $this->registerUid('@im-tok.test');

        $req = new Request('POST', '/api/v1/im/device-token');
        $req->setPost(['platform' => 'android', 'token' => 'tok-1']);
        $req->uid = $uid;
        $res = (new ImController())->deviceToken($req);
        $this->assertSame(0, json_decode($res->rawBody(), true)['code']);

        $req2 = new Request('POST', '/api/v1/im/device-token');
        $req2->setPost(['platform' => 'android', 'token' => 'tok-2']);
        $req2->uid = $uid;
        $res2 = (new ImController())->deviceToken($req2);
        $this->assertSame(0, json_decode($res2->rawBody(), true)['code']);

        $this->assertSame(1, DeviceToken::where('user_id', $uid)->where('platform', 'android')->count());
        $this->assertSame('tok-2', DeviceToken::where('user_id', $uid)->where('platform', 'android')->value('token'));

        $reqBad = new Request('POST', '/api/v1/im/device-token');
        $reqBad->setPost(['platform' => '', 'token' => '']);
        $reqBad->uid = $uid;
        $this->assertSame(400, json_decode((new ImController())->deviceToken($reqBad)->rawBody(), true)['code']);
    }
}
