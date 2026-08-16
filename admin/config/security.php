<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * erikwang2013/security-php 安全攻击检测配置
 *
 * 31 种攻击类型检测，通过 SecurityMiddleware 全局生效。
 * 各检测器独立可配：enabled（启用/禁用）+ mode（block 拦截 / log 仅记录）
 *
 * 中间件执行位置：Cors → SecurityMiddleware → RateLimit → ...
 */

return [

    // 总开关，false 时关闭所有检测
    'enabled' => true,

    // 检测器配置（31个）
    'detectors' => [
        'xss'               => ['enabled' => true, 'mode' => 'block'],
        'sql_injection'     => ['enabled' => true, 'mode' => 'block'],
        'command_injection' => ['enabled' => true, 'mode' => 'block'],
        'path_traversal'    => ['enabled' => true, 'mode' => 'block'],
        'upload'            => ['enabled' => true, 'mode' => 'block'],
        'ssrf'              => ['enabled' => true, 'mode' => 'block'],
        'xxe'               => ['enabled' => true, 'mode' => 'block'],
        'header_injection'  => ['enabled' => true, 'mode' => 'log'],   // log: \r\n 匹配多段落文本
        'deserialization'   => ['enabled' => true, 'mode' => 'block'],
        'ldap_injection'    => ['enabled' => true, 'mode' => 'block'],
        'mail_header'       => ['enabled' => true, 'mode' => 'block'],
        'ssti'              => ['enabled' => true, 'mode' => 'log'],   // log: {{}} 匹配前端模板
        'nosql_injection'   => ['enabled' => true, 'mode' => 'log'],   // log: $ne 匹配 Shell 变量
        'open_redirect'     => ['enabled' => true, 'mode' => 'block'],
        'jwt_attack'        => ['enabled' => true, 'mode' => 'block'],
        'host_header'       => ['enabled' => true, 'mode' => 'block'],
        'request_smuggling' => ['enabled' => true, 'mode' => 'block'],
        'graphql_injection' => ['enabled' => true, 'mode' => 'block'],
        'xpath_injection'   => ['enabled' => true, 'mode' => 'block'],
        'jndi_injection'    => ['enabled' => true, 'mode' => 'block'],
        'ssi_injection'     => ['enabled' => true, 'mode' => 'block'],
        'csv_injection'     => ['enabled' => true, 'mode' => 'block'],
        'data_leak'         => ['enabled' => true, 'mode' => 'block'],
        'prototype_pollution' => ['enabled' => true, 'mode' => 'block'],
        'websocket'         => ['enabled' => true, 'mode' => 'block'],
        'cors'              => ['enabled' => true, 'mode' => 'block'],
        'dns_rebinding'     => ['enabled' => true, 'mode' => 'block'],

        // HTTP 协议层校验（返回专用状态码）
        'http_method' => [
            'enabled' => true,
            'mode' => 'block',
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'],
        ],
        'body_size' => [
            'enabled' => true,
            'mode' => 'block',
            'max_size' => 10485760,  // 10MB
        ],
        'content_type' => [
            'enabled' => true,
            'mode' => 'block',
            'allowed_types' => [
                'application/x-www-form-urlencoded',
                'multipart/form-data',
                'application/json',
                'text/plain',
                'application/xml',
                'text/xml',
            ],
        ],
        'csrf_origin' => [
            'enabled' => true,
            'mode' => 'block',
            'allowed_origins' => [],
        ],
    ],

    // IP 攻击升级黑名单（5次/60s → 封禁15分钟）
    'ip_blacklist' => [
        'enabled' => true,
        'max_attempts' => 5,
        'window_seconds' => 60,
        'ban_duration_seconds' => 900,
    ],

    // 存储 — Redis（利用已有基础设施）
    'storage' => [
        'type' => 'redis',
        'redis' => [
            'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
            'timeout'  => 2.0,
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
            'prefix'   => 'security:',
        ],
    ],

    // 拦截响应
    'block_status_code' => 403,
    'block_message' => 'Request blocked by security policy',

    // 日志
    'log' => [
        'enabled'       => true,
        'channel'       => 'file',
        'path'          => runtime_path('logs/security-attack.log'),
        'max_size'      => 10,
        'dedup_seconds' => 5,
    ],

    'whitelist_ips' => ['127.0.0.1'],

    'whitelist_fields' => ['_token', '_method', 'csrf_token'],
];
