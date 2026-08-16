<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use support\Request;
use support\Response;

/**
 * 系统安装向导
 *
 * 首次部署时通过 Web 界面完成:
 *   1. 数据库连接配置
 *   2. 管理员账号密码设置
 *   3. 执行 database/migrations/open_admin.sql
 */
class InstallController
{
    public function __construct()
    {
        if (is_file(runtime_path('install.lock'))) {
            http_response_code(403);
            echo '<h1>系统已安装</h1><p>如需重新安装，请删除 runtime/install.lock 文件。</p>';
            exit;
        }
    }

    /**
     * 步骤 1: 数据库配置表单
     */
    public function index(Request $request): Response
    {
        return response($this->renderStep1());
    }

    /**
     * 步骤 2: 验证数据库 → 管理员设置
     */
    public function step2(Request $request): Response
    {
        $db = $request->post();
        $errors = $this->validateDb($db);
        if ($errors) {
            return response($this->renderStep1(implode('<br>', $errors)));
        }
        $connError = $this->testConnection($db);
        if ($connError) {
            return response($this->renderStep1($connError));
        }
        return response($this->renderStep2($db));
    }

    /**
     * 执行安装
     */
    public function install(Request $request): Response
    {
        $data = $request->post();
        $db = $data['db'] ?? [];
        $admin = $data['admin'] ?? [];

        $dbErrors = $this->validateDb($db);
        if ($dbErrors) {
            return response($this->renderStep1(implode('<br>', $dbErrors)));
        }
        $adminErrors = $this->validateAdmin($admin);
        if ($adminErrors) {
            return response($this->renderStep2($db, implode('<br>', $adminErrors)));
        }

        try {
            $pdo = $this->getPdo($db);
            $sqlFile = base_path() . '/database/migrations/open_admin.sql';
            $sql = file_get_contents($sqlFile);

            // 替换默认管理员凭证
            $hash = password_hash($admin['password'], PASSWORD_BCRYPT);
            $user = addslashes($admin['username']);
            $name = addslashes($admin['real_name'] ?? '系统管理员');
            $sql = str_replace(
                ["'__ADMIN_USER__'", "'__ADMIN_PASS__'", "'__ADMIN_NAME__'"],
                ["'{$user}'", "'{$hash}'", "'{$name}'"],
                $sql
            );

            $pdo->exec($sql);
            $this->writeEnv($db);
            file_put_contents(runtime_path('install.lock'), date('Y-m-d H:i:s'));

            return response($this->renderSuccess($admin));
        } catch (\Throwable $e) {
            return response($this->renderStep2($db, '安装失败: ' . $e->getMessage()));
        }
    }

    private function validateDb(array $db): array
    {
        $errors = [];
        foreach (['host' => '数据库主机', 'port' => '端口', 'database' => '数据库名', 'username' => '用户名'] as $k => $label) {
            if (empty($db[$k])) $errors[] = "{$label} 不能为空";
        }
        return $errors;
    }

    private function validateAdmin(array $admin): array
    {
        $errors = [];
        if (empty($admin['username']) || strlen($admin['username']) < 3) $errors[] = '用户名至少 3 个字符';
        if (empty($admin['password']) || strlen($admin['password']) < 6) $errors[] = '密码至少 6 个字符';
        if (($admin['password'] ?? '') !== ($admin['password_confirm'] ?? '')) $errors[] = '两次密码不一致';
        return $errors;
    }

    private function testConnection(array $db): string
    {
        try { $this->getPdo($db)->query('SELECT 1'); return ''; }
        catch (\Throwable $e) { return '数据库连接失败: ' . $e->getMessage(); }
    }

    private function getPdo(array $db): \PDO
    {
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4";
        return new \PDO($dsn, $db['username'], $db['password'] ?? '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private function writeEnv(array $db): void
    {
        $envFile = base_path() . '/.env';
        $env = is_file($envFile) ? file_get_contents($envFile) : '';
        foreach ([
            'DB_HOST'     => $db['host'],
            'DB_PORT'     => $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'] ?? '',
        ] as $key => $val) {
            $pattern = "/^{$key}=.*/m";
            if (preg_match($pattern, $env)) {
                $env = preg_replace($pattern, "{$key}={$val}", $env);
            } else {
                $env .= "\n{$key}={$val}";
            }
        }
        file_put_contents($envFile, $env);
    }

    // ============================================================
    // 视图
    // ============================================================

    private function renderStep1(string $error = ''): string
    {
        return $this->layout('步骤 1/3 · 数据库配置', <<<HTML
            {$this->alert($error, 'error')}
            <form method="post" action="/install/step2">
                <div class="field"><label>数据库主机</label><input name="host" value="127.0.0.1" required></div>
                <div class="field"><label>端口</label><input name="port" value="3306" required></div>
                <div class="field"><label>数据库名</label><input name="database" value="open_admin" required></div>
                <div class="field"><label>用户名</label><input name="username" value="root" required></div>
                <div class="field"><label>密码</label><div class="pwd-wrap"><input name="password" type="password"><span class="eye" onclick="togglePwd(this)">👁</span></div></div>
                <div class="actions"><button type="submit">测试连接 → 下一步</button></div>
            </form>
        HTML);
    }

    private function renderStep2(array $db, string $error = ''): string
    {
        $info = "{$db['username']}@{$db['host']}:{$db['port']}/{$db['database']}";
        $hidden = '';
        foreach ($db as $k => $v) {
            $hidden .= '<input type="hidden" name="db[' . htmlspecialchars($k) . ']" value="' . htmlspecialchars($v) . '">';
        }
        return $this->layout('步骤 2/3 · 管理员账号', <<<HTML
            <div class="info">数据库连接成功: <code>{$info}</code></div>
            {$this->alert($error, 'error')}
            <form method="post" action="/install/install">
                {$hidden}
                <div class="field"><label>管理员用户名</label><input name="admin[username]" value="admin" required></div>
                <div class="field"><label>管理员密码</label><div class="pwd-wrap"><input name="admin[password]" type="password" value="admin888" required><span class="eye" onclick="togglePwd(this)">👁</span></div></div>
                <div class="field"><label>确认密码</label><div class="pwd-wrap"><input name="admin[password_confirm]" type="password" value="admin888" required><span class="eye" onclick="togglePwd(this)">👁</span></div></div>
                <div class="field"><label>姓名</label><input name="admin[real_name]" value="系统管理员"></div>
                <div class="actions"><button type="submit">开始安装</button></div>
            </form>
        HTML);
    }

    private function renderSuccess(array $admin): string
    {
        return $this->layout('安装完成', <<<HTML
            <div style="text-align:center">
                <h2 style="color:#52c41a;margin-bottom:16px">安装成功</h2>
                <p>管理员账号: <strong>{$admin['username']}</strong></p>
                <p>管理员密码: <strong>{$admin['password']}</strong></p>
                <hr>
                <a href="/" style="display:inline-block;padding:10px 32px;background:#1890ff;color:#fff;border-radius:6px;text-decoration:none;font-size:15px">进入系统</a>
                <p style="margin-top:12px;font-size:12px;color:#999">安装完成，请删除 <code>runtime/install.lock</code> 以防意外重复安装。</p>
            </div>
        HTML);
    }

    private function alert(string $msg, string $type): string
    {
        if (empty($msg)) return '';
        $color = $type === 'error' ? '#cf1322' : '#0050b3';
        $bg   = $type === 'error' ? '#fff2f0' : '#e6f7ff';
        $border = $type === 'error' ? '#ffccc7' : '#91d5ff';
        return "<div style='background:{$bg};border:1px solid {$border};color:{$color};padding:12px;border-radius:6px;margin-bottom:16px;font-size:13px'>{$msg}</div>";
    }

    private function layout(string $title, string $body): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
            <title>{$title} — 开放管理后台</title>
            <style>
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f2f5;color:#333;min-height:100vh;display:flex;align-items:center;justify-content:center}
                .box{background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.08);width:460px;max-width:95vw;padding:36px 40px}
                h1{font-size:22px;text-align:center;margin-bottom:6px;color:#1a1a2e}
                .sub{text-align:center;color:#999;font-size:13px;margin-bottom:20px}
                .field{margin-bottom:16px}
                .field label{display:block;font-size:13px;font-weight:500;margin-bottom:4px;color:#555}
                .field input{width:100%;padding:10px 12px;border:1px solid #d9d9d9;border-radius:6px;font-size:14px;transition:border-color .2s}
                .field input:focus{outline:none;border-color:#1890ff;box-shadow:0 0 0 2px rgba(24,144,255,.1)}
                .actions{margin-top:24px;text-align:center}
                .actions button{padding:10px 36px;background:#1890ff;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer}
                .actions button:hover{background:#40a9ff}
                .info{background:#e6f7ff;border:1px solid #91d5ff;color:#0050b3;padding:12px;border-radius:6px;margin-bottom:16px;font-size:13px;word-break:break-all}
                hr{border:none;border-top:1px solid #f0f0f0;margin:16px 0}
                .pwd-wrap{position:relative}.pwd-wrap input{padding-right:40px}
                .eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:18px;user-select:none;opacity:.5;transition:opacity .2s}
                .eye:hover{opacity:1}.eye.show{opacity:1}
            </style>
        </head>
        <body>
            <div class="box">
                <h1>开放管理后台</h1>
                <div class="sub">安装向导 · {$title}</div>
                {$body}
            </div>
            <script>function togglePwd(el){var inp=el.parentNode.querySelector('input');if(inp.type==='password'){inp.type='text';el.classList.add('show')}else{inp.type='password';el.classList.remove('show')}}</script>
        </body>
        </html>
        HTML;
    }
}
