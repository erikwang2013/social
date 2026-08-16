<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;

class AuthControllerTest extends TestCase
{
    private function register(string $email)
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setPost(['email' => $email, 'password' => 'secret123']);
        return (new AuthController())->register($req);
    }

    public function testRegisterSuccess()
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setPost(['email' => 'd' . uniqid() . '@test.com', 'password' => 'secret123', 'nickname' => '小明']);
        $res = (new AuthController())->register($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);
    }

    public function testRegisterDuplicateEmail()
    {
        $email = 'c' . uniqid() . '@test.com';
        $this->register($email);
        $res = $this->register($email);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(409, $data['code']);
    }

    public function testLoginWrongPassword()
    {
        $email = 'a' . uniqid() . '@test.com';
        $this->register($email);

        $req2 = new Request('POST', '/api/v1/auth/login');
        $req2->setPost(['email' => $email, 'password' => 'wrong']);
        $res = (new AuthController())->login($req2);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(401, $data['code']);
    }

    public function testLoginSuccess()
    {
        $email = 'b' . uniqid() . '@test.com';
        $this->register($email);

        $req2 = new Request('POST', '/api/v1/auth/login');
        $req2->setPost(['email' => $email, 'password' => 'secret123']);
        $res = (new AuthController())->login($req2);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
    }
}
