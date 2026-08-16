<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;
use app\controller\PostController;

class PostControllerTest extends TestCase
{
    private function registerUid(): int
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setPost(['email' => uniqid() . '@post.test', 'password' => 'secret123']);
        (new AuthController())->register($req);
        return \app\model\User::where('email', 'like', '%@post.test')->orderByDesc('id')->first()->id;
    }

    public function testCreatePost()
    {
        $uid = $this->registerUid();
        $req = new Request('POST', '/api/v1/posts');
        $req->setPost(['content' => '第一条动态']);
        $req->uid = $uid;
        $res = (new PostController())->create($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame('第一条动态', $data['data']['content']);
    }

    public function testCreatePostEmptyContent()
    {
        $uid = $this->registerUid();
        $req = new Request('POST', '/api/v1/posts');
        $req->setPost(['content' => '   ']);
        $req->uid = $uid;
        $res = (new PostController())->create($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(400, $data['code']);
    }

    public function testTimelineOrderedDesc()
    {
        $uid = $this->registerUid();
        foreach (['第一', '第二'] as $content) {
            $req = new Request('POST', '/api/v1/posts');
            $req->setPost(['content' => $content]);
            $req->uid = $uid;
            (new PostController())->create($req);
            usleep(1100000); // created_at 秒级精度，间隔 1.1s 保证排序稳定
        }

        $req = new Request("GET /api/v1/posts HTTP/1.1\r\n\r\n");
        $req->uid = $uid;
        $res = (new PostController())->timeline($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame('第二', $data['data']['list'][0]['content']);
        $this->assertSame('第一', $data['data']['list'][1]['content']);
    }

    public function testDetailNotFound()
    {
        $req = new Request('GET', '/api/v1/posts/99999');
        $req->uid = 1;
        $res = (new PostController())->detail($req, '99999');
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(404, $data['code']);
    }
}
