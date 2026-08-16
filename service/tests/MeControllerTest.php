<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;
use app\controller\MeController;

class MeControllerTest extends TestCase
{
    private function registerAndGetUid(): int
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setPost(['email' => uniqid() . '@me.test', 'password' => 'secret123', 'nickname' => '初始名']);
        (new AuthController())->register($req);
        return \app\model\User::where('email', 'like', '%@me.test')->orderByDesc('id')->first()->id;
    }

    private function asRequest(string $method, string $path, array $input): Request
    {
        $req = new Request($method, $path);
        $req->setPost($input);
        return $req;
    }

    public function testUpdateNickname()
    {
        $uid = $this->registerAndGetUid();
        $req = $this->asRequest('PUT', '/api/v1/me', ['nickname' => '新昵称']);
        $req->uid = $uid;
        $res = (new MeController())->update($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame('新昵称', $data['data']['nickname']);
    }

    public function testUpdateInvalidNickname()
    {
        $uid = $this->registerAndGetUid();
        $req = $this->asRequest('PUT', '/api/v1/me', ['nickname' => '']);
        $req->uid = $uid;
        $res = (new MeController())->update($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(400, $data['code']);
    }

    public function testUpdateInvalidBirthday()
    {
        $uid = $this->registerAndGetUid();
        $req = $this->asRequest('PUT', '/api/v1/me', ['birthday' => '2026-13-99']);
        $req->uid = $uid;
        $res = (new MeController())->update($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(400, $data['code']);
    }
}
