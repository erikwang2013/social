<?php
require __DIR__ . '/bootstrap.php';

use app\model\User;
use app\model\UserProfile;
use app\controller\SearchController;
use PHPUnit\Framework\TestCase;
use support\Request;

class SearchControllerTest extends TestCase
{
    public function testSearchUsersByNickname()
    {
        $u = User::create(['email' => 'a@t.com', 'password' => password_hash('x', PASSWORD_DEFAULT)]);
        UserProfile::create(['user_id' => $u->id, 'nickname' => 'ruflo 测试号']);

        $req = new Request("GET /api/v1/search/users?q=ruflo HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $ctrl = new SearchController;
        $res = json_decode($ctrl->users($req)->rawBody(), true);
        $this->assertSame(0, $res['code']);
        $this->assertSame(1, $res['data']['total']);
    }

    public function testSearchPostsDegradesGracefully()
    {
        // ES 未部署 → SearchSync 静默失败返回 [] → 503
        $req = new Request("GET /api/v1/search/posts?q=anything HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $ctrl = new SearchController;
        $res = json_decode($ctrl->posts($req)->rawBody(), true);
        $this->assertContains($res['code'], [0, 400, 503]);
    }
}
