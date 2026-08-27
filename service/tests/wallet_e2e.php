<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// WALLET E2E 冒烟：钱包余额 → 充值 → 送礼 → 余额变化 → 主播分成（黑盒 HTTP + 直调 WalletService，需要 php start.php start 运行中 + MySQL m6a 表已应用）
// 充值：M6a 阶段3 的 IAP 凭证校验无 HTTP 路由（verifyReceipt 走服务端直调），脚本以直调 WalletService::credit 模拟入账成功；
//       入账幂等（ref_type+ref_id）与余额校验仍全部经 HTTP 断言
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

// 直调 WalletService 前加载 webman 配置（route 依赖运行中的路由表，排除），DB 走 config/database.php
require __DIR__ . '/../vendor/autoload.php';
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}
\Webman\Config::load(BASE_PATH . '/config', ['route']);

use app\common\WalletService;
use app\model\GiftCatalog;

$pass = 'e2e-pass';
// 1. 注册观众 A 与主播 B
$aRes = http('POST', '/api/auth/register', ['email' => uniqid() . '@e2e.a', 'password' => $pass, 'nickname' => 'E2E-WA']);
check($aRes['code'] === 0 && isset($aRes['data']['access_token']), 'A 注册成功');
$aTok = $aRes['data']['access_token'];
$bRes = http('POST', '/api/auth/register', ['email' => uniqid() . '@e2e.b', 'password' => $pass, 'nickname' => 'E2E-WB']);
check($bRes['code'] === 0, 'B 注册成功');
$bTok = $bRes['data']['access_token'];

// 2. B 开播（送礼目标房间，房主即主播）
$room = http('POST', '/api/v1/live/rooms', ['title' => 'E2E 钱包 ' . uniqid()], $bTok);
check($room['code'] === 0, 'B 开播成功');
$roomId = (int) $room['data']['room_id'];

// 3. 初始余额为 0
$balA = http('GET', '/api/v1/wallet/balance', [], $aTok);
check((int) ($balA['data']['coins'] ?? -1) === 0, 'A 初始余额 0');

// 4. 充值：直调 WalletService 模拟 IAP verifyReceipt 成功入账 1000 币（ref_type=recharge 幂等）
$meA = http('GET', '/api/v1/auth/me', [], $aTok);
$aId = (int) ($meA['data']['user']['id'] ?? 0);
check($aId > 0, 'A 经 /auth/me 取到 uid');
$refId = 'e2e-r-' . uniqid();
$top = WalletService::credit($aId, 1000, 'recharge', $refId, 'E2E 充值');
check($top['code'] === 0 && (int) $top['data']['balance'] === 1000, '直调充值入账 +1000（balance=1000）');
$retry = WalletService::credit($aId, 1000, 'recharge', $refId, 'E2E 充值');
check($retry['code'] === 0 && (int) $retry['data']['balance'] === 1000, '同 ref 重复入账幂等（余额不变）');

// 5. 余额经 HTTP 可见
$balA = http('GET', '/api/v1/wallet/balance', [], $aTok);
check((int) ($balA['data']['coins'] ?? 0) === 1000, 'GET /wallet/balance → 1000');

// 6. 礼物目录：HTTP 拉取；空目录时直插一枚测试礼物（阶段2 admin 上架无 HTTP 路由，脚本自给自足）
$cat = http('GET', '/api/v1/gifts', [], $aTok);
$gifts = $cat['data']['list'] ?? [];
if ($gifts === []) {
    GiftCatalog::firstOrCreate(['name' => 'E2E玫瑰'], ['coins_price' => 100, 'effect_key' => 'e2e_rose', 'status' => 1, 'sort' => 1]);
    $cat = http('GET', '/api/v1/gifts', [], $aTok);
    $gifts = $cat['data']['list'] ?? [];
}
check(($gifts[0]['id'] ?? 0) > 0, '礼物目录可拉取');
$giftId = (int) $gifts[0]['id'];
$price = (int) $gifts[0]['coins_price'];

// 7. 送礼：qty=2 → 扣款 2×price，余额下降，返回 gift_given_id
$clientRef = 'e2e-g-' . uniqid();
$send = http('POST', '/api/v1/live/rooms/' . $roomId . '/gift', ['gift_id' => $giftId, 'quantity' => 2, 'client_ref' => $clientRef], $aTok);
$givenId = (int) ($send['data']['gift_given_id'] ?? 0);
check($send['code'] === 0 && $givenId > 0 && (int) ($send['data']['balance'] ?? 0) === 1000 - $price * 2, '送礼成功：余额 = 1000 - ' . ($price * 2));

// 8. 同 client_ref 重试幂等：同一 gift_given_id，余额不变
$again = http('POST', '/api/v1/live/rooms/' . $roomId . '/gift', ['gift_id' => $giftId, 'quantity' => 2, 'client_ref' => $clientRef], $aTok);
check((int) ($again['data']['gift_given_id'] ?? 0) === $givenId && (int) ($again['data']['balance'] ?? 0) === 1000 - $price * 2, '同 client_ref 重试幂等（gift_given_id 不变）');

// 9. 流水：含 recharge +1000 与 gift_sent -2×price
$txs = http('GET', '/api/v1/wallet/transactions', [], $aTok);
$types = array_column($txs['data']['list'] ?? [], 'type');
check(in_array('recharge', $types, true) && in_array('gift_sent', $types, true), '流水含 recharge 与 gift_sent');

// 10. 主播分成：B 余额 = floor(2×price × 分成比例/100)
$ratio = (int) (config('live.split_streamer_percent', 70) ?: 70);
$expected = intdiv($price * 2 * $ratio, 100);
$balB = http('GET', '/api/v1/wallet/balance', [], $bTok);
check((int) ($balB['data']['coins'] ?? -1) === $expected, "主播分成入账 B={$expected}（ratio {$ratio}%）");

// 11. 余额不足 422：剩余 1000-2×price，qty=100 超支则 422（目录无超支价时跳过）
$remaining = 1000 - $price * 2;
if ($price * 100 > $remaining) {
    $poor = http('POST', '/api/v1/live/rooms/' . $roomId . '/gift', ['gift_id' => $giftId, 'quantity' => 100, 'client_ref' => 'e2e-p-' . uniqid()], $aTok);
    check($poor['code'] === 422 && $poor['lang_key'] === 'wallet.insufficient', '余额不足 → 422 wallet.insufficient');
} else {
    echo "SKIP: 目录无超支礼物（price={$price}，qty=100 未超剩余 {$remaining}）\n";
}

echo "WALLET E2E OK\n";
