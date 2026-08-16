<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use support\Db;
use support\Redis;
use support\Request;
use support\Response;
use app\model\AdminUser;
use Throwable;

/**
 * @Apidoc\Title("Prometheus 指标")
 * @Apidoc\Group("运维管理")
 * @Apidoc\Method("GET")
 * @Apidoc\Url("/metrics")
 * @Apidoc\Desc("Prometheus 格式监控指标，含活跃用户/数据库状态/Redis状态")
 */
class MetricsController
{
    /**
     * @Apidoc\Title("Prometheus 指标")
     * @Apidoc\Group("运维管理")
     * @Apidoc\Method("GET")
     * @Apidoc\Url("/metrics")
     * @Apidoc\Desc("Prometheus 格式监控指标，含活跃用户/数据库状态/Redis状态")
     */
    public function index(Request $request): Response
    {
        $metrics = [];

        $metrics[] = '# HELP open_admin_active_users Active users today';
        $metrics[] = '# TYPE open_admin_active_users gauge';
        try {
            $activeUsers = AdminUser::whereDate('last_login_at', date('Y-m-d'))->count();
        } catch (Throwable) {
            $activeUsers = 0;
        }
        $metrics[] = "open_admin_active_users {$activeUsers}";

        $metrics[] = '# HELP open_admin_total_users Total registered users';
        $metrics[] = '# TYPE open_admin_total_users gauge';
        try {
            $totalUsers = AdminUser::count();
        } catch (Throwable) {
            $totalUsers = 0;
        }
        $metrics[] = "open_admin_total_users {$totalUsers}";

        $metrics[] = '# HELP open_admin_db_up Database connection status (1=up, 0=down)';
        $metrics[] = '# TYPE open_admin_db_up gauge';
        try {
            Db::select('SELECT 1');
            $dbStatus = 1;
        } catch (Throwable) {
            $dbStatus = 0;
        }
        $metrics[] = "open_admin_db_up {$dbStatus}";

        $metrics[] = '# HELP open_admin_redis_up Redis connection status (1=up, 0=down)';
        $metrics[] = '# TYPE open_admin_redis_up gauge';
        try {
            Redis::ping();
            $redisStatus = 1;
        } catch (Throwable) {
            $redisStatus = 0;
        }
        $metrics[] = "open_admin_redis_up {$redisStatus}";

        $metrics[] = '# HELP open_admin_info Application info';
        $metrics[] = '# TYPE open_admin_info gauge';
        $metrics[] = 'open_admin_info{version="1.0",php="' . PHP_VERSION . '"} 1';

        return response(implode("\n", $metrics) . "\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
