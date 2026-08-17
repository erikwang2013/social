<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\call;

final class CallState
{
    public const RINGING = 'RINGING';
    public const ACCEPTED = 'ACCEPTED';
    public const ENDED = 'ENDED';
    public const FAILED = 'FAILED';

    /** 转移表：RINGING→ACCEPTED|REJECTED|CANCELED|MISSED|FAILED；ACCEPTED→ENDED|FAILED */
    private const ALLOWED = [
        self::RINGING => [self::ACCEPTED, 'REJECTED', 'CANCELED', 'MISSED', self::FAILED],
        self::ACCEPTED => [self::ENDED, self::FAILED],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }
}
