<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 环境配置测试
 *
 * 本仓库已不再入库 .env / .env.example（commit e5379fc 移除误入库的 env 备份），
 * 应用依赖 config/*.php 中的 getenv('X') ?: 默认值 兜底运行，默认值直接指向本地 MySQL/Redis。
 * 因此断言目标是：无 .env 时配置可加载、默认值类型正确、指向本地服务。
 */
class EnvConfigTest extends TestCase
{
    #[Test]
    public function getenv_fallback_pattern_works(): void
    {
        // 不存在的变量返回默认值
        $val2 = getenv('THIS_VAR_DOES_NOT_EXIST_XYZ') ?: 'FALLBACK_OK';
        $this->assertEquals('FALLBACK_OK', $val2);
    }

    #[Test]
    public function config_files_provide_defaults_for_all_env_keys(): void
    {
        // 每个 getenv 键都必须有 ?: 默认值兜底，保证无 .env 也能启动
        $configFiles = glob(__DIR__ . '/../config/*.php');
        $this->assertNotEmpty($configFiles, 'config/ 下应有配置文件');

        $missingDefaults = [];
        foreach ($configFiles as $file) {
            $content = file_get_contents($file);
            preg_match_all("/getenv\('([A-Z_][A-Z0-9_]*)'\)/", $content, $m);
            foreach ($m[1] as $key) {
                if (!preg_match("/getenv\('" . preg_quote($key, '/') . "'\)\s*\?:/", $content)) {
                    $missingDefaults[] = basename($file) . ": $key";
                }
            }
        }

        $this->assertEmpty($missingDefaults, '以下 env key 缺少默认值兜底: ' . implode(', ', $missingDefaults));
    }

    #[Test]
    public function default_config_points_to_local_services(): void
    {
        $this->assertEquals('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertEquals(3306, config('database.connections.mysql.port'));
        $this->assertEquals('open_admin', config('database.connections.mysql.database'));
        $this->assertNotEmpty(config('jwt.secret'), 'JWT_SECRET_KEY 应有默认值');
    }

    #[Test]
    public function critical_config_types(): void
    {
        $this->assertIsNumeric(config('jwt.ttl'), 'JWT_TTL 应为数字');
        $this->assertIsNumeric(config('database.connections.mysql.port'), 'DB_PORT 应为数字');
        $this->assertIsString(config('jwt.secret'), 'JWT_SECRET_KEY 应为字符串');
    }
}
