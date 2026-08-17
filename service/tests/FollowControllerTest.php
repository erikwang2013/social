<?php
require __DIR__ . '/bootstrap.php';

use app\model\User;
use app\model\Follow;
use app\model\Notification;
use app\controller\FollowController;
use PHPUnit\Framework\TestCase;
use support\Request;

class FollowControllerTest extends TestCase
{
    private function makeUser(): User
    {
        return User::create(['email' => uniqid() . '@t.com', 'password' => password_hash('x', PASSWORD_DEFAULT)]);
    }

    private function post(array $post, int $uid): Request
    {
        $req = new Request("POST / HTTP/1.1\r\n\r\n");
        $req->setPost($post);
        $req->uid = $uid;
        return $req;
    }

    public function testFollowCreatesNotificationAndIsIdempotent()
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $ctrl = new FollowController;

        $res = json_decode($ctrl->follow($this->post([], (int) $me->id), (string) $other->id)->rawBody(), true);
        $this->assertSame(0, $res['code']);
        $res2 = json_decode($ctrl->follow($this->post([], (int) $me->id), (string) $other->id)->rawBody(), true);
        $this->assertSame(0, $res2['code']);
        $this->assertSame(1, Follow::count());
        $this->assertSame(1, Notification::where('user_id', $other->id)->where('type', 'follow')->count());
    }

    public function testCannotFollowSelf()
    {
        $me = $this->makeUser();
        $ctrl = new FollowController;
        $res = json_decode($ctrl->follow($this->post([], (int) $me->id), (string) $me->id)->rawBody(), true);
        $this->assertSame(400, $res['code']);
    }

    public function testRelationAndLists()
    {
        $me = $this->makeUser();
        $a = $this->makeUser();
        $b = $this->makeUser();
        $ctrl = new FollowController;
        $ctrl->follow($this->post([], (int) $me->id), (string) $a->id);
        $ctrl->follow($this->post([], (int) $b->id), (string) $me->id);

        $rel = json_decode($ctrl->relation($this->post([], (int) $me->id), (string) $a->id)->rawBody(), true);
        $this->assertTrue($rel['data']['is_following']);
        $this->assertSame(1, $rel['data']['follower_count']);
        $this->assertSame(0, $rel['data']['following_count']);

        $following = json_decode($ctrl->following($this->post([], (int) $me->id), (string) $me->id)->rawBody(), true);
        $this->assertSame((int) $a->id, $following['data']['list'][0]['id']);
        $followers = json_decode($ctrl->followers($this->post([], (int) $me->id), (string) $me->id)->rawBody(), true);
        $this->assertSame((int) $b->id, $followers['data']['list'][0]['id']);
    }
}
