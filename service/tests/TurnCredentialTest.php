<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

use app\controller\VoiceController;
use PHPUnit\Framework\TestCase;

final class TurnCredentialTest extends TestCase
{
    public function testCoturnRestCredentialVector(): void
    {
        $this->assertSame(
            'ZzfPuPkrzUOsnm5YISnTiKDnXWo=',
            VoiceController::turnCredential('secret', '1700000000:42')
        );
    }
}
