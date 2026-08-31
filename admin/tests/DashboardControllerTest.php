<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use app\admin\controller\DashboardController;
use app\model\Payment;
use app\model\SocialUser;
use app\model\Withdrawal;

/**
 * M6d2 仪表盘 platform_stats 单测
 * - 'social' 连接以 sqlite :memory: 隔离
 * - 6 卡片结构（label/value/icon/color）与金额分→元换算断言
 */
class DashboardControllerTest extends TestCase
{
    private static ?\Illuminate\Database\ConnectionResolverInterface $oldResolver = null;

    public static function setUpBeforeClass(): void
    {
        class_exists('support\Db');
        self::$oldResolver = Model::getConnectionResolver();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'social');
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'erik_'], 'mysql');
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');
        $capsule->bootEloquent();

        $schema = $capsule->getConnection('social')->getSchemaBuilder();
        $schema->create('social_users', function ($t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('email', 190)->unique();
            $t->string('password', 255);
            $t->tinyInteger('status')->default(1);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        $schema->create('social_payments', function ($t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('user_id');
            $t->string('platform', 16);
            $t->string('trade_no', 64)->nullable();
            $t->string('client_ref', 64)->nullable();
            $t->unsignedBigInteger('amount_cents');
            $t->string('currency', 3)->default('CNY');
            $t->unsignedBigInteger('coins')->default(0);
            $t->string('status', 16)->default('pending');
            $t->text('payload')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        $schema->create('social_withdrawals', function ($t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('user_id');
            $t->string('platform', 16);
            $t->text('account');
            $t->unsignedBigInteger('coins');
            $t->string('currency', 3)->default('CNY');
            $t->string('status', 16)->default('pending');
            $t->string('reason', 255)->nullable();
            $t->string('client_ref', 64)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Model::setConnectionResolver(self::$oldResolver);
    }

    protected function setUp(): void
    {
        SocialUser::query()->delete();
        Payment::query()->delete();
        Withdrawal::query()->delete();
    }

    private function platformStats(string $today): array
    {
        return (new \ReflectionMethod(DashboardController::class, 'getPlatformStats'))
            ->invoke(new DashboardController(), $today);
    }

    #[Test]
    public function test_platform_stats_six_cards_with_amounts_in_yuan(): void
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        SocialUser::forceCreate(['id' => 1, 'email' => 'a@x.com', 'password' => 'x', 'created_at' => $today . ' 09:00:00', 'updated_at' => $today . ' 09:00:00']);
        SocialUser::forceCreate(['id' => 2, 'email' => 'b@x.com', 'password' => 'x', 'created_at' => $yesterday . ' 09:00:00', 'updated_at' => $yesterday . ' 09:00:00']);
        Payment::forceCreate(['id' => 1, 'user_id' => 1, 'platform' => 'wechat', 'amount_cents' => 1234, 'status' => 'succeeded', 'created_at' => $today . ' 10:00:00', 'updated_at' => $today . ' 10:00:00']);
        Payment::forceCreate(['id' => 2, 'user_id' => 2, 'platform' => 'alipay', 'amount_cents' => 9999, 'status' => 'failed', 'created_at' => $today . ' 11:00:00', 'updated_at' => $today . ' 11:00:00']);
        Withdrawal::forceCreate(['id' => 1, 'user_id' => 1, 'platform' => 'wechat', 'account' => '{}', 'coins' => 500, 'status' => 'succeeded', 'created_at' => $today . ' 12:00:00', 'updated_at' => $today . ' 12:00:00']);
        Withdrawal::forceCreate(['id' => 2, 'user_id' => 2, 'platform' => 'alipay', 'account' => '{}', 'coins' => 999, 'status' => 'pending', 'created_at' => $today . ' 13:00:00', 'updated_at' => $today . ' 13:00:00']);

        $stats = $this->platformStats($today);

        $this->assertCount(6, $stats);
        $byLabel = array_column($stats, null, 'label');
        $this->assertSame('2', $byLabel['社交用户总数']['value']);
        $this->assertSame('1', $byLabel['今日新增用户']['value']);
        $this->assertSame('2', $byLabel['支付订单数']['value']);
        $this->assertSame('12.34', $byLabel['今日充值(元)']['value'], '成功订单 1234 分 = 12.34 元，失败单不计');
        $this->assertSame('2', $byLabel['提现笔数']['value']);
        $this->assertSame('5.00', $byLabel['今日提现(元)']['value'], '仅 succeeded 的 500 coins = 5.00 元，pending 不计');

        foreach ($stats as $card) {
            $this->assertArrayHasKey('label', $card);
            $this->assertArrayHasKey('value', $card);
            $this->assertArrayHasKey('icon', $card);
            $this->assertArrayHasKey('color', $card);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $card['color'], '颜色应为 #RRGGBB');
        }
    }

    #[Test]
    public function test_platform_stats_empty_day_zero_values(): void
    {
        $stats = $this->platformStats(date('Y-m-d'));
        $byLabel = array_column($stats, null, 'label');
        $this->assertSame('0', $byLabel['社交用户总数']['value']);
        $this->assertSame('0.00', $byLabel['今日充值(元)']['value']);
        $this->assertSame('0.00', $byLabel['今日提现(元)']['value']);
    }
}
