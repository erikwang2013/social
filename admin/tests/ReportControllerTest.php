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
use support\Request;
use app\admin\controller\ReportController;
use app\model\Payment;
use app\model\SocialPost;
use app\model\SocialUser;
use app\model\Withdrawal;

/**
 * M6d1 报表控制器单测
 * - 'social' 连接以 sqlite :memory: 隔离（独立 Capsule 覆盖 Eloquent resolver，不触真实 MySQL）
 * - 聚合正确性：日期过滤、金额求和、状态/平台分布、daily 补零
 * - 入参校验：Y-m-d 格式、end>=start、区间≤366 天、type 白名单
 */
class ReportControllerTest extends TestCase
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
        $schema->create('social_posts', function ($t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('user_id');
            $t->text('content');
            $t->timestamp('created_at')->nullable();
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
        SocialPost::query()->delete();
        Payment::query()->delete();
        Withdrawal::query()->delete();
    }

    private function get(string $uri): Request
    {
        return new Request("GET $uri HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    private function post(string $uri, array $data): Request
    {
        $body = http_build_query($data);
        return new Request(
            "POST $uri HTTP/1.1\r\nHost: localhost\r\n" .
            "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body"
        );
    }

    private function body(mixed $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    private function ctrl(): ReportController
    {
        return new ReportController();
    }

    private function user(int $id, string $email, string $createdAt, int $status = 1): void
    {
        SocialUser::forceCreate([
            'id' => $id, 'email' => $email, 'password' => 'x',
            'status' => $status, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function payment(int $id, int $userId, string $platform, int $amount, string $status, string $createdAt): void
    {
        Payment::forceCreate([
            'id' => $id, 'user_id' => $userId, 'platform' => $platform,
            'amount_cents' => $amount, 'status' => $status,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function withdrawal(int $id, int $userId, int $coins, string $status, string $createdAt): void
    {
        Withdrawal::forceCreate([
            'id' => $id, 'user_id' => $userId, 'platform' => 'wechat',
            'account' => '{"alipay":"u@x.com"}', 'coins' => $coins, 'status' => $status,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    // ─────────────────────────── 聚合 ───────────────────────────

    #[Test]
    public function test_users_aggregation_filters_dates_and_sums(): void
    {
        $this->user(1, 'a@x.com', '2026-08-10 10:00:00');
        $this->user(2, 'b@x.com', '2026-08-11 10:00:00');
        $this->user(3, 'c@x.com', '2026-07-01 10:00:00'); // 区间外
        $this->user(4, 'd@x.com', '2026-08-20 10:00:00', 0); // 禁用
        SocialPost::forceCreate(['id' => 1, 'user_id' => 2, 'content' => 'x', 'created_at' => '2026-08-11 09:00:00']);
        SocialPost::forceCreate(['id' => 2, 'user_id' => 2, 'content' => 'y', 'created_at' => '2026-08-11 18:00:00']); // 同日两帖去重

        $res = $this->body($this->ctrl()->users($this->get('/admin/report/users?start=2026-08-01&end=2026-08-31')));
        $this->assertSame(0, $res['code']);
        $stats = $res['data']['stats'];
        $this->assertSame(4, $stats['total']);
        $this->assertSame(3, $stats['new_in_range'], '仅统计区间内新增');
        $this->assertSame(0, $stats['active_today'], '今日无发帖应活跃为 0');

        $daily = $res['data']['daily'];
        $this->assertCount(31, $daily, '区间每天一条');
        $this->assertSame(1, $daily[9]['new'], '2026-08-10 新增 1');
        $this->assertSame(1, $daily[10]['active'], '2026-08-11 活跃发帖用户去重后为 1');
        $this->assertSame(0, $daily[0]['new'], '区间首日无数据补 0');

        $dist = array_column($res['data']['status_distribution'], 'value', 'name');
        $this->assertSame(3, $dist['正常']);
        $this->assertSame(1, $dist['禁用']);
    }

    #[Test]
    public function test_users_active_today_counts_distinct_posters(): void
    {
        $this->user(1, 'a@x.com', '2026-07-01 10:00:00');
        $today = date('Y-m-d');
        SocialPost::forceCreate(['id' => 1, 'user_id' => 1, 'content' => 'x', 'created_at' => $today . ' 09:00:00']);
        SocialPost::forceCreate(['id' => 2, 'user_id' => 1, 'content' => 'y', 'created_at' => $today . ' 18:00:00']);

        $res = $this->body($this->ctrl()->users($this->get('/admin/report/users')));
        $this->assertSame(1, $res['data']['stats']['active_today'], '同人同日多帖去重');
    }

    #[Test]
    public function test_payments_aggregation_sums_succeeded_amounts(): void
    {
        $this->payment(1, 1, 'wechat', 1000, 'succeeded', '2026-08-10 10:00:00');
        $this->payment(2, 1, 'wechat', 2500, 'succeeded', '2026-08-10 12:00:00');
        $this->payment(3, 2, 'alipay', 500, 'failed', '2026-08-11 10:00:00');
        $this->payment(4, 3, 'wechat', 9999, 'succeeded', '2026-07-01 10:00:00'); // 区间外

        $res = $this->body($this->ctrl()->payments($this->get('/admin/report/payments?start=2026-08-01&end=2026-08-31')));
        $this->assertSame(0, $res['code']);
        $this->assertSame(3, $res['data']['stats']['orders'], '区间内全部订单');
        $this->assertSame(3500, $res['data']['stats']['succeeded_amount_cents'], '仅成功订单金额求和');

        $daily = $res['data']['daily'];
        $this->assertSame(2, $daily[9]['orders']);
        $this->assertSame(3500, $daily[9]['amount_cents']);

        $platform = array_column($res['data']['platform_distribution'], 'value', 'name');
        $this->assertSame(2, $platform['wechat']);
        $this->assertSame(1, $platform['alipay']);

        $status = array_column($res['data']['status_distribution'], 'value', 'name');
        $this->assertSame(2, $status['succeeded']);
        $this->assertSame(1, $status['failed']);
    }

    #[Test]
    public function test_withdrawals_aggregation_uses_coins_as_amount(): void
    {
        $this->withdrawal(1, 1, 100, 'pending', '2026-08-10 10:00:00');
        $this->withdrawal(2, 2, 200, 'succeeded', '2026-08-11 10:00:00');
        $this->withdrawal(3, 3, 50, 'succeeded', '2026-07-01 10:00:00'); // 区间外

        $res = $this->body($this->ctrl()->withdrawals($this->get('/admin/report/withdrawals?start=2026-08-01&end=2026-08-31')));
        $this->assertSame(0, $res['code']);
        $this->assertSame(2, $res['data']['stats']['count']);
        $this->assertSame(200, $res['data']['stats']['amount_cents'], '金额仅统计 succeeded，pending 不计');

        $daily = $res['data']['daily'];
        $this->assertSame(200, $daily[10]['amount_cents']);
        $this->assertSame(0, $daily[9]['amount_cents'], 'pending 提现不进入金额');

        $status = array_column($res['data']['status_distribution'], 'value', 'name');
        $this->assertSame(1, $status['pending']);
        $this->assertSame(1, $status['succeeded']);
    }

    // ─────────────────────────── 入参校验 ───────────────────────────

    #[Test]
    public function test_rejects_bad_date_format(): void
    {
        foreach (['2026-8-1', '20260801', '2026-13-01', 'abc'] as $bad) {
            $res = $this->body($this->ctrl()->users($this->get("/admin/report/users?start={$bad}")));
            $this->assertSame(400, $res['code'], "非法日期 {$bad} 应拒绝");
        }
    }

    #[Test]
    public function test_rejects_end_before_start_and_overlong_range(): void
    {
        $res = $this->body($this->ctrl()->payments($this->get('/admin/report/payments?start=2026-08-31&end=2026-08-01')));
        $this->assertSame(400, $res['code'], 'end 早于 start 应拒绝');

        $res = $this->body($this->ctrl()->payments($this->get('/admin/report/payments?start=2025-01-01&end=2026-12-31')));
        $this->assertSame(400, $res['code'], '区间超过 366 天应拒绝');
    }

    #[Test]
    public function test_export_rejects_unknown_type(): void
    {
        $res = $this->body($this->ctrl()->export($this->post('/admin/report/export', ['type' => 'pdf'])));
        $this->assertSame(400, $res['code']);
    }

    #[Test]
    public function test_export_users_downloads_xlsx(): void
    {
        $this->user(1, 'a@x.com', '2026-08-10 10:00:00');
        $res = $this->ctrl()->export($this->post('/admin/report/export', [
            'type' => 'users', 'start' => '2026-08-01', 'end' => '2026-08-31',
        ]));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('report_users_2026-08-01_2026-08-31.xlsx', (string) $res->getHeader('Content-Disposition'), '文件名应含类型与区间');
        $this->assertStringStartsWith("PK\x03\x04", (string) $res->rawBody(), '应为 xlsx zip 二进制流');
        $this->assertFileDoesNotExist(runtime_path() . '/tmp/report_users_2026-08-01_2026-08-31.xlsx', '临时文件发送后已清理');
    }
}
