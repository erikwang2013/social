<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// 用法: php scripts/recon.php [YYYY-MM-DD]（缺省昨日）; exit 0=正常执行 1=参数错误（cron 可用）
require __DIR__ . '/../vendor/autoload.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}
\Webman\Config::load(BASE_PATH . '/config', ['route']);
\support\Db::connection(); // 初始化默认 DB 连接

$date = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));
$result = \app\common\ReconService::run($date);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
exit($result['code'] === 0 ? 0 : 1);
