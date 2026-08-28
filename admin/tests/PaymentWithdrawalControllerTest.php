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
use app\admin\controller\PaymentOrderController;
use app\admin\controller\WithdrawalController;
use app\model\Payment;
use app\model\SocialUser;
use app\model\Withdrawal;

/**
 * M6b4 管理端支付订单/提现单控制器单测
 * - social 连接以 sqlite :memory: 隔离（独立 Capsule 覆盖 Eloquent resolver，不触真实 MySQL）
 * - PaymentOrderController list 筛选/分页/email 注入 + detail
 * - WithdrawalController list/detail + status 状态机（pending→succeeded/failed、终态 422、failed 必填 reason）
 */
class PaymentWithdrawalControllerTest extends TestCase
{
    private static ?\Illuminate\Database\ConnectionResolverInterface $oldResolver = null;

    public static function setUpBeforeClass(): void
    {
        // 触发 support\Db autoload → Webman\Database\Initializer::init() 建立容器 config
        class_exists('support\Db');
        $container = \Illuminate\Container\Container::getInstance();
        $mysql = $container['config']['database.connections']['mysql'];

        self::$oldResolver = Model::getConnectionResolver();
        $capsule = new Capsule();
        $capsule->addConnection($mysql, 'mysql');
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'social');
        $capsule->bootEloquent();

        $schema = $capsule->getConnection('social')->getSchemaBuilder();
        $schema->create('social_payments', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('user_id');
            $t->string('platform', 16);
            $t->string('trade_no', 64)->nullable();
            $t->string('client_ref', 64)->nullable();
            $t->unsignedBigInteger('amount_cents');
            $t->string('currency', 3)->default('CNY');
            $t->unsignedBigInteger('coins')->default(0);
            $t->string('status', 16)->default('pending');
            $t->text('payload')->nullable();
            $t->timestamps();
        });
        $schema->create('social_withdrawals', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('user_id');
            $t->string('platform', 16);
            $t->text('account');
            $t->unsignedBigInteger('coins');
            $t->string('currency', 3)->default('CNY');
            $t->string('status', 16)->default('pending');
            $t->string('reason', 255)->nullable();
            $t->string('client_ref', 64)->nullable();
            $t->timestamps();
        });
        $schema->create('social_users', function ($t) {
            $t->increments('id');
            $t->string('email', 190);
        });
    }

    public static function tearDownAfterClass(): void
    {
        Model::setConnectionResolver(self::$oldResolver);
    }

    protected function setUp(): void
    {
        Payment::query()->delete();
        Withdrawal::query()->delete();
        SocialUser::query()->delete();
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

    private function createPayment(array $attrs): Payment
    {
        $p = new Payment();
        $p->fill(array_merge([
            'user_id' => 1, 'platform' => 'wechat', 'amount_cents' => 100,
            'currency' => 'CNY', 'coins' => 10, 'status' => 'pending',
        ], $attrs))->save();
        return $p;
    }

    private function createWithdrawal(array $attrs): Withdrawal
    {
        $w = new Withdrawal();
        $w->fill(array_merge([
            'user_id' => 1, 'platform' => 'wechat', 'account' => 'wx_abc',
            'coins' => 100, 'currency' => 'CNY', 'status' => 'pending',
        ], $attrs))->save();
        return $w;
    }

    // ─────────────────────── PaymentOrderController ───────────────────────

    #[Test]
    public function test_payment_list_empty(): void
    {
        $res = $this->body((new PaymentOrderController())->list($this->get('/admin/payment-order')));
        $this->assertSame(0, $res['code']);
        $this->assertSame(0, $res['data']['total']);
        $this->assertSame([], $res['data']['list']);
    }

    #[Test]
    public function test_payment_list_injects_user_email_and_sorts_desc(): void
    {
        $this->createPayment(['user_id' => 1, 'platform' => 'wechat', 'payload' => '{"event":"pay"}']);
        $this->createPayment(['user_id' => 2, 'platform' => 'alipay', 'status' => 'succeeded']);
        SocialUser::insert(['id' => 1, 'email' => 'a@x.com']);

        $res = $this->body((new PaymentOrderController())->list($this->get('/admin/payment-order')));
        $this->assertSame(0, $res['code']);
        $this->assertSame(2, $res['data']['total']);
        $list = $res['data']['list'];
        $this->assertSame(2, (int) $list[0]['id'], '应按 id 倒序');
        $this->assertSame('', $list[0]['user_email'], '无对应用户时 email 为空串');
        $this->assertSame('a@x.com', $list[1]['user_email'], '用户 email 应批量注入');
        $this->assertArrayNotHasKey('payload', $list[0], '列表不应回传回调原文 payload（makeHidden）');
    }

    #[Test]
    public function test_payment_list_filters(): void
    {
        $this->createPayment(['platform' => 'wechat', 'status' => 'pending']);
        $this->createPayment(['platform' => 'alipay', 'status' => 'succeeded']);
        $this->createPayment(['user_id' => 2, 'platform' => 'alipay', 'status' => 'pending']);

        $ctrl = new PaymentOrderController();
        $byPlatform = $this->body($ctrl->list($this->get('/admin/payment-order?platform=alipay')));
        $this->assertSame(2, $byPlatform['data']['total']);
        $byStatus = $this->body($ctrl->list($this->get('/admin/payment-order?status=pending')));
        $this->assertSame(2, $byStatus['data']['total']);
        $byUser = $this->body($ctrl->list($this->get('/admin/payment-order?user_id=2')));
        $this->assertSame(1, $byUser['data']['total']);
        $this->assertSame(2, (int) $byUser['data']['list'][0]['user_id']);
        $combined = $this->body($ctrl->list($this->get('/admin/payment-order?platform=alipay&status=pending')));
        $this->assertSame(1, $combined['data']['total']);
    }

    #[Test]
    public function test_payment_list_pagination_and_page_size_bounds(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->createPayment(['user_id' => $i]);
        }
        $ctrl = new PaymentOrderController();
        $page1 = $this->body($ctrl->list($this->get('/admin/payment-order?page=1&page_size=2')));
        $page2 = $this->body($ctrl->list($this->get('/admin/payment-order?page=2&page_size=2')));
        $this->assertSame(3, $page2['data']['total']);
        $this->assertCount(2, $page1['data']['list']);
        $this->assertCount(1, $page2['data']['list']);
        $this->assertLessThan((int) $page1['data']['list'][1]['id'], (int) $page2['data']['list'][0]['id'], '第 2 页应为更早的记录');

        $zero = $this->body($ctrl->list($this->get('/admin/payment-order?page_size=0')));
        $this->assertCount(1, $zero['data']['list'], 'page_size=0 应被钳制为 1');
        $huge = $this->body($ctrl->list($this->get('/admin/payment-order?page_size=999')));
        $this->assertCount(3, $huge['data']['list'], 'page_size 上限 100');
    }

    #[Test]
    public function test_payment_detail(): void
    {
        $p = $this->createPayment(['payload' => '{"event":"pay"}']);
        $ctrl = new PaymentOrderController();

        $ok = $this->body($ctrl->detail($this->get('/admin/payment-order/1'), (string) $p->id));
        $this->assertSame(0, $ok['code']);
        $this->assertSame(100, $ok['data']['amount_cents']);
        $this->assertSame('{"event":"pay"}', $ok['data']['payload'], '详情应包含 payload');

        $miss = $this->body($ctrl->detail($this->get('/admin/payment-order/99999'), '99999'));
        $this->assertSame(404, $miss['code']);
    }

    // ─────────────────────── WithdrawalController ───────────────────────

    #[Test]
    public function test_withdrawal_list_and_detail(): void
    {
        $w = $this->createWithdrawal(['account' => '{"alipay":"alipay_001"}']);
        SocialUser::insert(['id' => 1, 'email' => 'a@x.com']);

        $ctrl = new WithdrawalController();
        $list = $this->body($ctrl->list($this->get('/admin/withdrawal')));
        $this->assertSame(0, $list['code']);
        $this->assertSame(1, $list['data']['total']);
        $this->assertSame('{"alipay":"***_001"}', $list['data']['list'][0]['account'], '列表 account 应脱敏（仅保留尾 4 位）');
        $this->assertSame('a@x.com', $list['data']['list'][0]['user_email']);

        $detail = $this->body($ctrl->detail($this->get('/admin/withdrawal/' . $w->id), (string) $w->id));
        $this->assertSame(0, $detail['code']);
        $this->assertSame('{"alipay":"alipay_001"}', $detail['data']['account'], '详情应保留完整 account');

        $miss = $this->body($ctrl->detail($this->get('/admin/withdrawal/99999'), '99999'));
        $this->assertSame(404, $miss['code']);
    }

    #[Test]
    public function test_withdrawal_list_status_filter(): void
    {
        $this->createWithdrawal(['status' => 'pending']);
        $this->createWithdrawal(['status' => 'succeeded']);
        $this->createWithdrawal(['status' => 'failed', 'reason' => 'bank reject']);

        $res = $this->body((new WithdrawalController())->list($this->get('/admin/withdrawal?status=failed')));
        $this->assertSame(1, $res['data']['total']);
        $this->assertSame('bank reject', $res['data']['list'][0]['reason']);
    }

    #[Test]
    public function test_withdrawal_status_pending_to_succeeded(): void
    {
        $w = $this->createWithdrawal([]);
        $res = $this->body((new WithdrawalController())->status($this->post('/admin/withdrawal/1/status', ['status' => 'succeeded']), (string) $w->id));
        $this->assertSame(0, $res['code']);
        $this->assertSame('succeeded', $res['data']['status']);
        $this->assertSame('succeeded', Withdrawal::find($w->id)->status);
    }

    #[Test]
    public function test_withdrawal_status_failed_requires_reason(): void
    {
        $w = $this->createWithdrawal([]);
        $ctrl = new WithdrawalController();
        $noReason = $this->body($ctrl->status($this->post('/admin/withdrawal/1/status', ['status' => 'failed']), (string) $w->id));
        $this->assertSame(400, $noReason['code']);

        $withReason = $this->body($ctrl->status($this->post('/admin/withdrawal/1/status', ['status' => 'failed', 'reason' => '账户冻结']), (string) $w->id));
        $this->assertSame(0, $withReason['code']);
        $this->assertSame('failed', Withdrawal::find($w->id)->status);
        $this->assertSame('账户冻结', Withdrawal::find($w->id)->reason, 'reason 应持久化');
    }

    #[Test]
    public function test_withdrawal_status_rejects_terminal_state(): void
    {
        $w = $this->createWithdrawal(['status' => 'succeeded']);
        $res = $this->body((new WithdrawalController())->status($this->post('/admin/withdrawal/1/status', ['status' => 'failed', 'reason' => 'x']), (string) $w->id));
        $this->assertSame(422, $res['code'], '终态单不可再变更');
        $this->assertSame('succeeded', Withdrawal::find($w->id)->status);
    }

    #[Test]
    public function test_withdrawal_status_atomic_race_user_cancel_wins(): void
    {
        $w = $this->createWithdrawal([]);
        // 模拟并发用户取消：先于管理员操作落库，管理员原子更新应互斥失败
        Withdrawal::where('id', $w->id)->update(['status' => 'cancelled']);
        $res = $this->body((new WithdrawalController())->status($this->post('/admin/withdrawal/1/status', ['status' => 'succeeded']), (string) $w->id));
        $this->assertSame(422, $res['code'], '已被用户取消的单不可再打款');
        $this->assertSame('cancelled', Withdrawal::find($w->id)->status, 'DB 状态应保持 cancelled，杜绝退币+打款双付');
    }

    #[Test]
    public function test_withdrawal_status_invalid_value(): void
    {
        $w = $this->createWithdrawal([]);
        $res = $this->body((new WithdrawalController())->status($this->post('/admin/withdrawal/1/status', ['status' => 'cancelled']), (string) $w->id));
        $this->assertSame(400, $res['code']);
        $this->assertSame('pending', Withdrawal::find($w->id)->status);
    }

    #[Test]
    public function test_withdrawal_status_404(): void
    {
        $res = $this->body((new WithdrawalController())->status($this->post('/admin/withdrawal/99999/status', ['status' => 'succeeded']), '99999'));
        $this->assertSame(404, $res['code']);
    }

    #[Test]
    public function test_routes_admin_auth_only(): void
    {
        // 设计约束：两组路由仅 AdminAuth，不进 RBAC 权限表（AdminPermission 组内路由才校验 method.path）
        $routes = file_get_contents(__DIR__ . '/../config/route.php');
        foreach (['/admin/payment-order', '/admin/withdrawal'] as $prefix) {
            $matched = preg_match("/Route::group\('" . preg_quote($prefix, '/') . "'.*?middleware\(\[(.*?)\]\)/s", $routes, $m);
            $this->assertSame(1, $matched, "$prefix 应注册为路由组");
            $this->assertStringContainsString('AdminAuth', $m[1], "$prefix 应挂 AdminAuth");
            $this->assertStringNotContainsString('AdminPermission', $m[1], "$prefix 不应进权限校验");
        }
    }
}
