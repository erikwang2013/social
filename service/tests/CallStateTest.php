<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace tests;

use app\call\CallState;
use PHPUnit\Framework\TestCase;

class CallStateTest extends TestCase
{
    public function testLegalTransitions(): void
    {
        $this->assertTrue(CallState::can('RINGING', 'ACCEPTED'));
        $this->assertTrue(CallState::can('RINGING', 'REJECTED'));
        $this->assertTrue(CallState::can('RINGING', 'CANCELED'));
        $this->assertTrue(CallState::can('RINGING', 'MISSED'));
        $this->assertTrue(CallState::can('ACCEPTED', 'ENDED'));
    }

    public function testIllegalTransitions(): void
    {
        $this->assertFalse(CallState::can('RINGING', 'ENDED'));
        $this->assertFalse(CallState::can('ACCEPTED', 'MISSED'));
        $this->assertFalse(CallState::can('ENDED', 'ENDED'));
    }
}
