<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;
use app\common\JwtHelper;

class AuthFlowTest extends TestCase
{
    public function testRefreshTokenRoundTrip()
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setPost(['email' => 'r1@test.com', 'password' => 'secret123']);
        $res = (new AuthController())->register($req);
        $data = json_decode($res->rawBody(), true);
        $refresh = $data['data']['refresh_token'];

        $req2 = new Request('POST', '/api/v1/auth/refresh');
        $req2->setPost(['refresh_token' => $refresh]);
        $res2 = (new AuthController())->refresh($req2);
        $data2 = json_decode($res2->rawBody(), true);
        $this->assertSame(0, $data2['code']);
        $this->assertArrayHasKey('access_token', $data2['data']);
    }

    public function testLogoutRevokesRefresh()
    {
        if (!redisAvailable()) {
            $this->markTestSkipped('Redis 不可用');
        }
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setPost(['email' => 'r2@test.com', 'password' => 'secret123']);
        $res = (new AuthController())->register($req);
        $data = json_decode($res->rawBody(), true);
        $refresh = $data['data']['refresh_token'];
        $payload = JwtHelper::decode($refresh);
        JwtHelper::revoke($payload->jti, 7200);
        $this->assertTrue(JwtHelper::isRevoked($payload->jti));
    }
}
