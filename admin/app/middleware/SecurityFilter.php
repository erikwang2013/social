<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use Erikwang2013\Security\SecurityGuard;

/**
 * 安全攻击检测拦截中间件
 *
 * 基于 erikwang2013/security-php 提供 31 种攻击类型检测:
 * XSS / SQL注入 / 命令注入 / 路径遍历 / 恶意文件上传 / SSRF / XXE /
 * 响应头注入 / 反序列化 / LDAP注入 / 邮件头注入 / SSTI / NoSQL注入 /
 * 开放重定向 / JWT攻击 / Host头攻击 / 请求走私 / GraphQL注入 /
 * XPATH注入 / JNDI注入 / SSI注入 / CSV注入 / 敏感数据泄露 /
 * 原型污染 / WebSocket劫持 / CORS绕过 / DNS重绑定 /
 * HTTP方法校验(405) / 请求体大小限制(413) / Content-Type校验(415) / CSRF
 *
 * + IP白名单 / 攻击升级黑名单(5次/60s→封禁15分钟) / 日志轮转去重
 *
 * 全局执行，在 Cors 之后、RateLimit 之前
 */
class SecurityFilter implements MiddlewareInterface
{
    private static bool $initialized = false;

    public function process(Request $request, callable $handler): Response
    {
        self::lazyInit();

        // 收集所有输入数据
        $data = array_merge(
            $request->cookie() ?? [],
            $request->get() ?? [],
            $request->post() ?? [],
            // 传入关键 header 供检测器扫描
            [
                '_headers.Referer'        => $request->header('Referer', ''),
                '_headers.User-Agent'     => $request->header('User-Agent', ''),
                '_headers.Cookie'         => $request->header('Cookie', ''),
                '_headers.X-Forwarded-For'=> $request->header('X-Forwarded-For', ''),
                '_headers.Origin'         => $request->header('Origin', ''),
                '_headers.Host'           => $request->header('Host', ''),
            ]
        );

        // 文件上传数据
        foreach ($request->file() ?? [] as $key => $file) {
            if (is_array($file) && isset($file['tmp_name'], $file['name'])) {
                $data[$key] = [
                    'name'     => $file['name'],
                    'tmp_name' => $file['tmp_name'],
                ];
            }
        }

        $threats = SecurityGuard::guard($data, [
            'ip'     => $request->getRealIp() ?? '0.0.0.0',
            'method' => $request->method(),
            'uri'    => $request->path(),
        ]);

        if (!empty($threats) && SecurityGuard::shouldBlock($threats)) {
            return new Response(
                SecurityGuard::blockStatusCode($threats),
                ['Content-Type' => 'text/plain; charset=utf-8'],
                SecurityGuard::blockMessage()
            );
        }

        return $handler($request);
    }

    private static function lazyInit(): void
    {
        if (self::$initialized) {
            return;
        }

        $configPath = config_path() . '/security.php';
        if (is_file($configPath)) {
            SecurityGuard::init(require $configPath);
        }
        // 否则 SecurityGuard::guard() 会自动使用包内默认配置

        self::$initialized = true;
    }
}
