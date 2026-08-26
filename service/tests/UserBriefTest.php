<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\UserBrief;
use app\model\User;
use app\model\UserProfile;
use PHPUnit\Framework\TestCase;

class UserBriefTest extends TestCase
{
    public function testUserWithoutProfileDefaults(): void
    {
        $user = User::create(['email' => 'nb' . uniqid() . '@t.com', 'password' => 'x']);
        $brief = UserBrief::of($user);
        $this->assertSame((int) $user->id, $brief['id']);
        $this->assertSame('', $brief['nickname']);
        $this->assertSame('', $brief['avatar']);
        $this->assertSame('', $brief['bio']);
        $this->assertSame(0, $brief['gender']);
    }

    public function testUserWithProfile(): void
    {
        $user = User::create(['email' => 'p' . uniqid() . '@t.com', 'password' => 'x']);
        UserProfile::create(['user_id' => $user->id, 'nickname' => '小雅', 'avatar' => 'a.png', 'bio' => 'hi', 'gender' => 2]);
        $brief = UserBrief::of($user->fresh());
        $this->assertSame('小雅', $brief['nickname']);
        $this->assertSame('a.png', $brief['avatar']);
        $this->assertSame('hi', $brief['bio']);
        $this->assertSame(2, $brief['gender']);
    }
}
