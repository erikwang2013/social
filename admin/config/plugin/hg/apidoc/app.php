<?php
return [
    'enable'  => true,
    'apidoc' => [
        'title'              => '开放管理后台 API',
        'desc'               => '基于 webman v2 的全栈管理后台系统 — API 接口文档',
        'apps'           => [
            // ============================================================
            // 公开服务接口
            // ============================================================
            [
                'title' => '公开服务 (Service)',
                'path'  => 'app\api\v1\controller',
                'key'   => 'service',
            ],
            // ============================================================
            // 管理端接口
            // ============================================================
            [
                'title' => '管理端 (Admin)',
                'path'  => 'app\admin\controller',
                'key'   => 'admin',
            ],
        ],
        'definitions'        => "app\common\Definitions",
        'auto_url' => [
            'letter_rule' => "lcfirst",
            'prefix'      => "",
        ],
        'auto_register_routes' => false,
        'cache'              => [
            'enable' => false,
        ],
        'auth'               => [
            'enable'     => false,  // 关闭认证：getConfig()要求token导致前端无法初始化
            'password'   => "admin888",
            'secret_key' => "open-admin-apidoc",
            'expire'     => 24 * 60 * 60
        ],
        'params' => [
            'header' => [
                ['name' => 'Authorization', 'type' => 'string', 'require' => false, 'desc' => 'JWT Token（管理端接口必填）'],
                ['name' => 'API-Version', 'type' => 'string', 'require' => false, 'default' => 'v1', 'desc' => 'API 版本号'],
                ['name' => 'Accept-Language', 'type' => 'string', 'require' => false, 'default' => 'zh_CN', 'desc' => '语言 (zh_CN | en)'],
            ],
        ],
        'responses' => [
            'success' => [
                ['name' => 'code', 'desc' => '业务状态码', 'type' => 'int', 'require' => 1],
                ['name' => 'message', 'desc' => '业务提示信息', 'type' => 'string', 'require' => 1],
                ['name' => 'data', 'desc' => '业务数据', 'main' => true, 'type' => 'object', 'require' => 1],
            ],
            'error' => [
                ['name' => 'code', 'desc' => '错误状态码', 'type' => 'int', 'require' => 1],
                ['name' => 'message', 'desc' => '错误描述', 'type' => 'string', 'require' => 1],
            ]
        ],
        'responses_status' => [
            ['name' => '200', 'desc' => '请求成功'],
            ['name' => '400', 'desc' => '请求参数错误 / 不支持的 API 版本'],
            ['name' => '401', 'desc' => '未登录 / Token 无效'],
            ['name' => '403', 'desc' => '无权限 / 安全攻击拦截'],
            ['name' => '404', 'desc' => '资源不存在'],
            ['name' => '405', 'desc' => '请求方法不允许'],
            ['name' => '413', 'desc' => '请求体过大'],
            ['name' => '415', 'desc' => '不支持的媒体类型'],
            ['name' => '422', 'desc' => '参数验证失败'],
            ['name' => '429', 'desc' => '请求过于频繁 / 账号临时锁定'],
            ['name' => '500', 'desc' => '服务器内部错误'],
        ],
        'default_author' => 'erik',
        'default_method'  => 'GET',
        'allowCrossDomain' => true,
        'ignored_annitation' => [],
        'ignored_methods' => ['__construct', 'getJWT', 'trackSession', 'filterSensitive', 'detectSource', 'scan', 'isBanned', 'escalate'],
        'database' => [],
        'docs'     => [],
        'generator' => []
    ]
];
