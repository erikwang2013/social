<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// PAYMENT E2E 冒烟：建单 → 回调验签入账 → 幂等 → 金额不符 → 未配置渠道 → 余额/流水（黑盒 HTTP + 直调 PaymentService，需要 php start.php start 运行中 + MySQL m6b 表已应用）
// 回调：微信回调验签无沙箱环境，脚本以直调 PaymentService::handleCallback 注入 mock 验签器模拟（同 PaymentServiceTest 的 fakeVerifier 形状）；
//       建单、余额、流水仍全部经 HTTP 断言
// 运行前提：dev 库已应用 install.sql（含 social_payments 表）；服务进程需带 PAY_PRICING_JSON 启动（config('payment.pricing') 启动时读取，
//       脚本内 putenv 仅对直调段生效）：
//   PAY_PRICING_JSON='{"CNY:100":10,"CNY:150":15}' php start.php start -d
// 测试用户在 dev 库留痕（uniqid 邮箱无碰撞，无删除用户接口）

function http(string $method, string $path, array $json = [], string $token = ''): array
{
    $sock = stream_socket_client('tcp://127.0.0.1:8788', $errno, $errstr, 5);
    if (!$sock) {
        throw new RuntimeException("http connect failed: $errstr");
    }
    stream_set_timeout($sock, 5);
    $body = $json === [] ? '' : json_encode($json, JSON_UNESCAPED_UNICODE);
    $hs = "Host: 127.0.0.1\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close";
    if ($token !== '') {
        $hs .= "\r\nAuthorization: Bearer $token";
    }
    fwrite($sock, "$method $path HTTP/1.1\r\n$hs\r\n\r\n$body");
    $resp = '';
    while (!feof($sock)) {
        $resp .= fread($sock, 8192);
    }
    fclose($sock);
    $parts = explode("\r\n\r\n", $resp, 2);
    return json_decode($parts[1] ?? '', true) ?? [];
}

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

// 直调 PaymentService 前加载 webman 配置（route 依赖运行中的路由表，排除），DB 走 config/database.php
require __DIR__ . '/../vendor/autoload.php';
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}
\Webman\Config::load(BASE_PATH . '/config', ['route']);

use app\common\PaymentService;
use app\model\Payment;

// 定价映射：CNY:100 → 10 币，CNY:150 → 15 币（直调段依赖；HTTP 段依赖服务进程启动时带同一环境变量）
putenv('PAY_PRICING_JSON={"CNY:100":10,"CNY:150":15}');

$pass = 'e2e-pass';
// 1. 注册观众（uniqid 邮箱防碰撞），register 响应直接带 access_token
$aRes = http('POST', '/api/auth/register', ['email' => uniqid() . '@e2e.pay', 'password' => $pass, 'nickname' => 'E2E-PAY']);
check($aRes['code'] === 0 && isset($aRes['data']['access_token']), '注册观众成功');
$aTok = $aRes['data']['access_token'];
$meA = http('GET', '/api/v1/auth/me', [], $aTok);
$aId = (int) ($meA['data']['user']['id'] ?? 0);
check($aId > 0, '经 /auth/me 取到 uid');

// 2. 建单：POST /api/v1/payment/order（定价 CNY:100 → 10 币），client_ref 需 8-64 位 [A-Za-z0-9_-]
$clientRef = 'e2e-pay-' . uniqid();
$order = http('POST', '/api/v1/payment/order', ['platform' => 'wechat', 'amount_cents' => 100, 'currency' => 'CNY', 'client_ref' => $clientRef], $aTok);
check($order['code'] === 0 && ($order['data']['status'] ?? '') === 'pending', '建单返回 pending');
check((int) ($order['data']['coins'] ?? 0) === 10, '定价映射 CNY:100 → 10 币');

// 3. 回调：直调 handleCallback 注入 mock 验签器（模拟微信回调验签通过）→ 入账 10 币
$tradeNo = 'CH-E2E-' . uniqid();
$verify = fn(string $platform, array $payload, array $headers = [], string $rawBody = '', string $path = '') => array_merge([
    'code' => 0, 'transaction_id' => $tradeNo, 'out_trade_no' => $clientRef,
    'amount_cents' => 100, 'currency' => 'CNY',
], []);
$cb = PaymentService::handleCallback('wechat', ['event' => 'test'], [], '{}', '/api/v1/payment/callback/wechat', $verify);
check($cb['code'] === 0 && (int) ($cb['data']['balance'] ?? 0) === 10, '回调入账成功 balance=10');

// 4. 同 trade_no 重复回调：幂等返回原结果，不重复加币
$again = PaymentService::handleCallback('wechat', ['event' => 'test'], [], '{}', '/api/v1/payment/callback/wechat', $verify);
check($again['code'] === 0 && (int) ($again['data']['balance'] ?? 0) === 10, '重复回调幂等（balance 仍 10）');

// 5. 金额不符回调：需新订单（已 succeeded 单重放直接幂等返回）；422 amount_mismatch 且订单落 failed
$ref2 = 'e2e-pay-' . uniqid();
$order2 = http('POST', '/api/v1/payment/order', ['platform' => 'wechat', 'amount_cents' => 100, 'currency' => 'CNY', 'client_ref' => $ref2], $aTok);
check($order2['code'] === 0, '第二单建单成功');
$verify2 = fn(string $platform, array $payload, array $headers = [], string $rawBody = '', string $path = '') => ['code' => 0, 'transaction_id' => 'CH-E2E-' . uniqid(), 'out_trade_no' => $ref2, 'amount_cents' => 200, 'currency' => 'CNY'];
$bad = PaymentService::handleCallback('wechat', ['event' => 'test'], [], '{}', '/api/v1/payment/callback/wechat', $verify2);
check($bad['code'] === 422 && $bad['lang_key'] === 'payment.amount_mismatch', '金额不符 → 422 amount_mismatch');
check(Payment::find((int) $order2['data']['order_id'])->status === 'failed', '金额不符订单落 failed');

// 6. 未配置渠道：不注入验签器，走默认真实验签器（config('payment.*') 为空）→ 503 channel_not_configured
$none = PaymentService::handleCallback('wechat', ['event' => 'test'], [], '{}', '/api/v1/payment/callback/wechat');
check($none['code'] === 503 && $none['lang_key'] === 'payment.channel_not_configured', '未配置渠道 → 503 channel_not_configured');

// 7. 余额与流水经 HTTP 可见：balance=10，流水含 ref_type=payment 入账
$bal = http('GET', '/api/v1/wallet/balance', [], $aTok);
check((int) ($bal['data']['coins'] ?? 0) === 10, 'GET /wallet/balance → 10');
$txs = http('GET', '/api/v1/wallet/transactions', [], $aTok);
$refTypes = array_column($txs['data']['list'] ?? [], 'ref_type');
check(in_array('payment', $refTypes, true), '流水含 ref_type=payment 记录');

// 8. 定价缺失金额（999 未映射）建单 → 422 pricing_not_found
$missing = http('POST', '/api/v1/payment/order', ['platform' => 'wechat', 'amount_cents' => 999, 'currency' => 'CNY', 'client_ref' => 'e2e-pay-' . uniqid()], $aTok);
check($missing['code'] === 422 && $missing['lang_key'] === 'payment.pricing_not_found', '未映射金额 → 422 pricing_not_found');

echo "PAYMENT E2E OK\n";
