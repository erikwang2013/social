<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace tests;

use app\process\Http;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;
use PHPUnit\Framework\TestCase;
use Webman\Route;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;

class ApiVersionTest extends TestCase
{
    private static bool $routeReady = false;

    private function request(string $buffer): array
    {
        if (!self::$routeReady) {
            self::$routeReady = true;
            // 测试环境未加载 route 配置（bootstrap 排除），复刻 Route::load 的
            // simpleDispatcher 闭包机制，使测试路由进入编译后的 dispatcher。
            $ref = new \ReflectionProperty(Route::class, 'dispatcher');
            $ref->setValue(null, simpleDispatcher(function (RouteCollector $r) {
                Route::setCollector($r);
                Route::get('/api/v1/__ping', fn() => json(['code' => 0, 'data' => 'pong']));
            }));
        }
        [$sock] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        // App::unsafeUri/findFile 强类型 TcpConnection，需真实子类 mock
        $conn = new class(new Select(), $sock) extends TcpConnection {
            public $sent = null;
            public function send($sendBuffer, bool $raw = false): ?bool
            {
                $this->sent = $sendBuffer;
                return true;
            }
        };
        (new Http())->onMessage($conn, new \support\Request($buffer));
        return ['status' => $conn->sent->getStatusCode(), 'body' => (string) $conn->sent->rawBody()];
    }

    public function testVersionlessWithHeaderV1(): void
    {
        $out = $this->request("GET /api/__ping HTTP/1.1\r\nX-Api-Version: v1\r\n\r\n");
        $this->assertSame(200, $out['status']);
        $this->assertStringContainsString('pong', $out['body']);
    }

    public function testVersionlessDefaultV1(): void
    {
        $out = $this->request("GET /api/__ping HTTP/1.1\r\n\r\n");
        $this->assertSame(200, $out['status']);
        $this->assertStringContainsString('pong', $out['body']);
    }

    public function testLegacyVersionedPathUntouched(): void
    {
        $out = $this->request("GET /api/v1/__ping HTTP/1.1\r\nX-Api-Version: v2\r\n\r\n");
        $this->assertSame(200, $out['status']);
        $this->assertStringContainsString('pong', $out['body']);
    }

    public function testInvalidVersion400(): void
    {
        $out = $this->request("GET /api/__ping HTTP/1.1\r\nX-Api-Version: banana\r\n\r\n");
        $this->assertSame(400, $out['status']);
        $this->assertStringContainsString('api.version_invalid', $out['body']);
    }

    public function testNonApiPathUntouched(): void
    {
        $out = $this->request("GET / HTTP/1.1\r\n\r\n");
        $this->assertSame(404, $out['status']);
    }
}
