<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace tests;

use app\call\CallCenter;
use app\ws\Envelope;
use PHPUnit\Framework\TestCase;

class CallCenterTest extends TestCase
{
    private static function redisOk(): bool
    {
        try {
            \app\ws\WsRedis::call(fn($r) => $r->ping());
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private array $sent = [];
    private ?array $recorded = null;
    private CallCenter $cc;

    protected function setUp(): void
    {
        if (!self::redisOk()) {
            $this->markTestSkipped('Redis 不可用');
        }
        \app\ws\WsRedis::call(fn($r) => $r->flushdb());
        $this->sent = [];
        $this->recorded = null;
        $this->cc = new CallCenter(
            sendFn: fn(int $uid, array $frame) => $this->sent[] = ['uid' => $uid, 'frame' => $frame],
            recordFn: fn(array $row) => $this->recorded = $row,
        );
    }

    public function testInviteAcceptHangupFlow(): void
    {
        $callId = $this->cc->start(1, 2);
        // start 先推被叫后推主叫（sent[0]=被叫2, sent[1]=主叫1）
        $this->assertSame(2, $this->sent[0]['uid']);
        $this->assertSame(Envelope::T_CALL_INVITE, $this->sent[0]['frame']['type']);
        $this->assertSame(1, $this->sent[1]['uid']);
        $this->assertSame(Envelope::T_CALL_INVITE, $this->sent[1]['frame']['type']);

        $this->cc->accept($callId, 2);
        $this->assertSame(Envelope::T_CALL_ACCEPT, $this->sent[2]['frame']['type']);
        $this->assertSame(2, $this->recorded['status']);
        $this->assertNotNull($this->recorded['started_at']);

        $this->cc->hangup($callId, 1);
        $last = end($this->sent);
        $this->assertSame(Envelope::T_CALL_HANGUP, $last['frame']['type']);
        $this->assertSame(5, $this->recorded['status']);
    }

    public function testBusyMutex(): void
    {
        $this->cc->start(1, 2);
        $err = null;
        try {
            $this->cc->start(1, 3);
        } catch (\RuntimeException $e) {
            $err = $e;
        }
        $this->assertNotNull($err);
        $this->assertStringContainsString('already_in_call', $err->getMessage());
    }

    public function testRejectFlow(): void
    {
        $callId = $this->cc->start(1, 2);
        $this->cc->reject($callId, 2);
        $frames = array_column($this->sent, 'frame');
        $this->assertSame(Envelope::T_CALL_REJECT, end($frames)['type']);
        $this->assertSame(3, $this->recorded['status']);
    }
}
