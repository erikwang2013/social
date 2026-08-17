<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\ws\Envelope;
use PHPUnit\Framework\TestCase;

class EnvelopeTest extends TestCase
{
    public function testEncodeDecodeRoundtrip(): void
    {
        $raw = Envelope::encode(Envelope::T_SEND, ['conversation_id' => 1, 'content' => '你好'], 7);
        $env = Envelope::decode($raw);
        $this->assertSame('send', $env['type']);
        $this->assertSame(7, $env['seq']);
        $this->assertSame('你好', $env['data']['content']);
    }

    public function testEncodeWithoutSeq(): void
    {
        $env = Envelope::decode(Envelope::encode('pong', []));
        $this->assertSame('pong', $env['type']);
        $this->assertArrayNotHasKey('seq', $env);
        $this->assertSame([], $env['data']);
    }

    public function testBadJsonReturnsNull(): void
    {
        $this->assertNull(Envelope::decode('{not json'));
    }

    public function testMissingOrEmptyTypeReturnsNull(): void
    {
        $this->assertNull(Envelope::decode('{"data":{}}'));
        $this->assertNull(Envelope::decode('{"type":""}'));
        $this->assertNull(Envelope::decode('42'));
    }
}
