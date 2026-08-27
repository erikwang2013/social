<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 验证码单测（对齐当前 poster-php 契约）
 *
 * 说明: 点击验证码响应 extra 仅暴露 texts（text+order），目标坐标只存于存储层
 * （Redis，前缀 poster:captcha:），故"正确点击通过"用例从 Redis 读取存储目标验证。
 */
class CaptchaTest extends TestCase
{
    /** 从 Redis 存储读取验证码目标（坐标），存储不可用时跳过 */
    private function storedTargets(string $key): ?array
    {
        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $raw = $redis->get('poster:captcha:' . $key);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis 不可用，无法读取验证码存储: ' . $e->getMessage());
        }

        $data = json_decode($raw ?: '', true);
        return is_array($data) ? ($data['data']['targets'] ?? null) : null;
    }

    #[Test]
    public function captcha_generate_returns_valid_structure(): void
    {
        $result = captcha_create('click', ['difficulty' => 'medium']);

        $this->assertArrayHasKey('key', $result, '应包含 key');
        $this->assertArrayHasKey('type', $result, '应包含 type');
        $this->assertArrayHasKey('image', $result, '应包含 image');
        $this->assertArrayHasKey('texts', $result['extra'], 'extra 应包含 texts');

        $this->assertNotEmpty($result['key']);
        $this->assertNotEmpty($result['image']);
        $this->assertCount(3, $result['extra']['texts'], 'medium 难度应有 3 个目标');
    }

    #[Test]
    public function captcha_targets_have_required_fields(): void
    {
        $result = captcha_create('click', ['difficulty' => 'easy']);

        foreach ($result['extra']['texts'] as $target) {
            $this->assertArrayHasKey('text', $target);
            $this->assertArrayHasKey('order', $target);
            $this->assertIsString($target['text']);
            $this->assertIsInt($target['order']);
        }
    }

    #[Test]
    public function captcha_difficulty_controls_target_count(): void
    {
        $easy = captcha_create('click', ['difficulty' => 'easy']);
        $medium = captcha_create('click', ['difficulty' => 'medium']);
        $hard = captcha_create('click', ['difficulty' => 'hard']);

        $this->assertCount(2, $easy['extra']['texts'], 'easy 应为 2 个目标');
        $this->assertCount(3, $medium['extra']['texts'], 'medium 应为 3 个目标');
        $this->assertCount(4, $hard['extra']['texts'], 'hard 应为 4 个目标');
    }

    #[Test]
    public function captcha_verify_correct_clicks_passes(): void
    {
        $result = captcha_create('click', ['difficulty' => 'easy']);
        $targets = $this->storedTargets($result['key']);

        // 使用正确的目标坐标（当前契约: [x, y] 数字对）
        $clicks = array_map(fn($t) => [$t['x'], $t['y']], $targets);
        $valid = captcha_verify($result['key'], 'click', $clicks);

        $this->assertTrue($valid, '点击正确坐标应验证通过');
    }

    #[Test]
    public function captcha_verify_wrong_clicks_fails(): void
    {
        $result = captcha_create('click', ['difficulty' => 'easy']);

        // 使用完全错误的坐标
        $clicks = [['x' => 0, 'y' => 0], ['x' => 999, 'y' => 999]];
        $valid = captcha_verify($result['key'], 'click', $clicks);

        $this->assertFalse($valid, '错误坐标应验证失败');
    }

    #[Test]
    public function captcha_key_has_limited_attempts(): void
    {
        $result = captcha_create('click', ['difficulty' => 'easy']);
        $wrong = [['x' => 0, 'y' => 0], ['x' => 999, 'y' => 999]];

        // max_attempts=3: 前 3 次错误验证返回 false 并累计尝试次数
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse(captcha_verify($result['key'], 'click', $wrong));
        }
        // 第 4 次: 尝试超限，key 被消费并从存储删除
        $this->assertFalse(captcha_verify($result['key'], 'click', $wrong));
        $this->assertNull($this->storedTargets($result['key']), '超过尝试上限后验证码 key 应失效');
    }

    #[Test]
    public function captcha_generates_unique_keys(): void
    {
        $r1 = captcha_create('click');
        $r2 = captcha_create('click');

        $this->assertNotEquals($r1['key'], $r2['key'], '每次生成的 key 应不同');
    }
}
