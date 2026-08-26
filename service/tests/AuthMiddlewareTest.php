<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\JwtHelper;
use app\middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use support\Request;

class AuthMiddlewareTest extends TestCase
{
    private function request(): Request
    {
        // header() 依赖可解析的 HTTP 请求行（rawHead），需完整 buffer
        return new Request("GET /api/v1/me HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    private function pass(): callable
    {
        return fn(Request $req) => json(['code' => 0, 'uid' => $req->uid ?? null]);
    }

    private function body(\Webman\Http\Response $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    public function testMissingHeaderReturns401(): void
    {
        $res = (new AuthMiddleware())->process($this->request(), $this->pass());
        $this->assertSame(401, $this->body($res)['code']);
    }

    public function testGarbageTokenReturns401(): void
    {
        $req = $this->request();
        $req->setHeader('authorization', 'Bearer garbage.token.here');
        $res = (new AuthMiddleware())->process($req, $this->pass());
        $this->assertSame(401, $this->body($res)['code']);
    }

    public function testValidTokenSetsUidAndJti(): void
    {
        $token = JwtHelper::encode(99, 'access', 3600);
        $req = $this->request();
        $req->setHeader('authorization', 'Bearer ' . $token);
        $res = (new AuthMiddleware())->process($req, $this->pass());
        $this->assertSame(0, $this->body($res)['code']);
        $this->assertSame(99, $req->uid);
        $this->assertNotEmpty($req->jti);
    }

    public function testRefreshTokenRejected(): void
    {
        $token = JwtHelper::encode(99, 'refresh', 3600);
        $req = $this->request();
        $req->setHeader('authorization', 'Bearer ' . $token);
        $res = (new AuthMiddleware())->process($req, $this->pass());
        $this->assertSame(401, $this->body($res)['code']);
    }

    public function testRevokedTokenRejected(): void
    {
        if (!@(new \Redis())->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0)) {
            $this->markTestSkipped('Redis 不可用');
        }
        $token = JwtHelper::encode(5, 'access', 3600);
        $jti = JwtHelper::decode($token)->jti;
        JwtHelper::revoke($jti, 3600);
        $req = $this->request();
        $req->setHeader('authorization', 'Bearer ' . $token);
        $res = (new AuthMiddleware())->process($req, $this->pass());
        $this->assertSame(401, $this->body($res)['code']);
    }
}
