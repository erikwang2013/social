<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use app\controller\LiveController;
use app\live\LiveCenter;
use app\model\LiveRoom;
use app\ws\WsRedis;
use PHPUnit\Framework\TestCase;
use support\Request;

class LiveControllerTest extends TestCase
{
    private function request(string $method, string $path, array $post = []): Request
    {
        $req = new Request("$method $path HTTP/1.1\r\nHost: localhost\r\n\r\n");
        if ($post !== []) {
            $req->setPost($post);
        }
        $req->uid = 1;
        return $req;
    }

    private function body(\Webman\Http\Response $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    protected function setUp(): void
    {
        try {
            WsRedis::call(fn($r) => $r->flushdb());
        } catch (\Throwable) {
        }
        LiveRoom::query()->delete();
    }

    public function testCreateTitleInvalid(): void
    {
        $res = (new LiveController())->create($this->request('POST', '/api/v1/live/rooms', ['title' => ' ']));
        $this->assertSame(400, $this->body($res)['code']);
        $res = (new LiveController())->create($this->request('POST', '/api/v1/live/rooms', ['title' => str_repeat('播', 101)]));
        $this->assertSame(400, $this->body($res)['code']);
    }

    public function testCreateSignsUrlsAndJoinsOwner(): void
    {
        $res = (new LiveController())->create($this->request('POST', '/api/v1/live/rooms', ['title' => '首播']));
        $data = $this->body($res);
        $this->assertSame(0, $data['code']);
        $roomId = $data['data']['room_id'];
        $this->assertStringContainsString('/live/' . $roomId, $data['data']['push_url']);
        $this->assertStringContainsString('/hls/' . $roomId . '.m3u8', $data['data']['play_url']);
        $this->assertSame(1, (new LiveCenter())->onlineCount($roomId));
    }

    public function testRoomsListsOnlyOpen(): void
    {
        $lc = new LiveCenter();
        $open = $lc->create(1, '开放直播');
        $closed = $lc->create(1, '已关直播');
        $lc->close($closed, 1);
        $res = (new LiveController())->rooms($this->request('GET', '/api/v1/live/rooms'));
        $data = $this->body($res);
        $titles = array_column($data['data']['list'], 'title');
        $this->assertContains('开放直播', $titles);
        $this->assertNotContains('已关直播', $titles);
        // 列表按 started_at 倒序，先创建者在前
        $this->assertSame($open, (int) $data['data']['list'][0]['id']);
        $this->assertSame(1, $data['data']['list'][0]['online_count']);
    }

    public function testDetailMissing(): void
    {
        $res = (new LiveController())->detail($this->request('GET', '/api/v1/live/rooms/999'), '999');
        $this->assertSame(404, $this->body($res)['code']);
    }

    public function testDetailPushUrlOnlyOwner(): void
    {
        $lc = new LiveCenter();
        $roomId = $lc->create(1, '详情直播');
        $req = $this->request('GET', '/api/v1/live/rooms/' . $roomId);
        $req->uid = 1;
        $data = $this->body((new LiveController())->detail($req, (string) $roomId))['data'];
        $this->assertStringContainsString('/live/', $data['push_url']);
        $req2 = $this->request('GET', '/api/v1/live/rooms/' . $roomId);
        $req2->uid = 2;
        $data2 = $this->body((new LiveController())->detail($req2, (string) $roomId))['data'];
        $this->assertSame('', $data2['push_url']);
    }

    public function testCloseForbidden(): void
    {
        $roomId = (new LiveCenter())->create(9, '别人的直播');
        $res = (new LiveController())->close($this->request('POST', '/api/v1/live/rooms/' . $roomId . '/close'), (string) $roomId);
        $this->assertSame(403, $this->body($res)['code']);
        $this->assertSame(1, (int) LiveRoom::query()->find($roomId)->status);
    }

    public function testCloseSuccess(): void
    {
        $roomId = (new LiveCenter())->create(1, '我的直播');
        $res = (new LiveController())->close($this->request('POST', '/api/v1/live/rooms/' . $roomId . '/close'), (string) $roomId);
        $this->assertSame(0, $this->body($res)['code']);
        $this->assertSame(0, (int) LiveRoom::query()->find($roomId)->status);
    }

    public function testMicFullRejected(): void
    {
        $lc = new LiveCenter();
        $roomId = $lc->create(1, '满麦直播');
        for ($i = 2; $i <= 9; $i++) {
            $lc->join($roomId, $i);
            $lc->micUp($roomId, $i);
        }
        $req = $this->request('POST', '/api/v1/live/rooms/' . $roomId . '/mic');
        $req->uid = 10;
        $res = (new LiveController())->micUp($req, (string) $roomId);
        $this->assertSame(422, $this->body($res)['code']);
    }

    public function testMicUpDownRoundTrip(): void
    {
        $lc = new LiveCenter();
        $roomId = $lc->create(1, '麦位直播');
        $res = (new LiveController())->micUp($this->request('POST', '/api/v1/live/rooms/' . $roomId . '/mic'), (string) $roomId);
        $this->assertSame(0, $this->body($res)['code']);
        $this->assertSame([1], $lc->micUsers($roomId));
        $res = (new LiveController())->micDown($this->request('DELETE', '/api/v1/live/rooms/' . $roomId . '/mic'), (string) $roomId);
        $this->assertSame(0, $this->body($res)['code']);
        $this->assertSame([], $lc->micUsers($roomId));
    }
}
