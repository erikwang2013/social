<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\JwtHelper;
use PHPUnit\Framework\TestCase;

class JwtHelperTest extends TestCase
{
    private static function redisOk(): bool
    {
        try {
            $r = new \Redis();
            $r->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0);
            $r->ping();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function testEncodeDecodeRoundtrip(): void
    {
        $token = JwtHelper::encode(42, 'access', 3600);
        $payload = JwtHelper::decode($token);
        $this->assertNotNull($payload);
        $this->assertSame(42, (int) $payload->sub);
        $this->assertSame('access', $payload->type);
        $this->assertSame('social-service', $payload->iss);
        $this->assertNotEmpty($payload->jti);
        $this->assertGreaterThan(time(), (int) $payload->exp);
    }

    public function testDecodeTamperedTokenReturnsNull(): void
    {
        $token = JwtHelper::encode(1, 'access', 3600);
        $this->assertNull(JwtHelper::decode($token . 'x'));
    }

    public function testDecodeGarbageReturnsNull(): void
    {
        $this->assertNull(JwtHelper::decode('not.a.jwt'));
    }

    public function testDecodeExpiredTokenReturnsNull(): void
    {
        $token = JwtHelper::encode(1, 'access', -10);
        $this->assertNull(JwtHelper::decode($token));
    }

    public function testRevokeAndIsRevoked(): void
    {
        if (!self::redisOk()) {
            $this->markTestSkipped('Redis 不可用');
        }
        $token = JwtHelper::encode(7, 'access', 3600);
        $jti = JwtHelper::decode($token)->jti;
        $this->assertFalse(JwtHelper::isRevoked($jti));
        JwtHelper::revoke($jti, 3600);
        $this->assertTrue(JwtHelper::isRevoked($jti));
        // decode 不校验黑名单（由 AuthMiddleware 层校验），revoke 后签名仍有效
        $this->assertNotNull(JwtHelper::decode($token));
    }
}
