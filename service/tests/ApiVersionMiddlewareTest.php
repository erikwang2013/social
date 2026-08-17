<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace tests;

use app\middleware\ApiVersionMiddleware;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;
use Webman\Http\Response;

class ApiVersionMiddlewareTest extends TestCase
{
    private function go(string $path, array $headers = []): array
    {
        $req = new Request("GET $path HTTP/1.1\r\n" . implode('', array_map(
            fn($k, $v) => "$k: $v\r\n", array_keys($headers), $headers
        )) . "\r\n");
        $captured = null;
        $mw = new ApiVersionMiddleware();
        $resp = $mw->process($req, function ($r) use (&$captured) {
            $captured = $r->path();
            return json(['ok' => true]);
        });
        return ['resp' => $resp, 'path' => $captured];
    }

    public function testHeaderVersionRewrite(): void
    {
        $out = $this->go('/api/auth/me', ['X-Api-Version' => 'v1']);
        $this->assertSame('/api/v1/auth/me', $out['path']);
    }

    public function testDefaultVersionV1(): void
    {
        $out = $this->go('/api/auth/me');
        $this->assertSame('/api/v1/auth/me', $out['path']);
    }

    public function testLegacyPathUntouched(): void
    {
        $out = $this->go('/api/v1/auth/me', ['X-Api-Version' => 'v2']);
        $this->assertSame('/api/v1/auth/me', $out['path']);
    }

    public function testInvalidVersion400(): void
    {
        $out = $this->go('/api/auth/me', ['X-Api-Version' => 'banana']);
        $this->assertSame(400, $out['resp']->getStatusCode());
        $this->assertStringContainsString('api.version_invalid', (string) $out['resp']->rawBody());
    }

    public function testNonApiPathUntouched(): void
    {
        $out = $this->go('/');
        $this->assertSame('/', $out['path']);
    }
}
