<?php
return [
    'enable'  => true,
    'apidoc' => [
        'title'              => 'Social 用户端 API',
        'desc'               => '社交平台用户端接口文档（M1：认证/资料/动态/评论/点赞）',
        'apps'           => [
            [
                'title' => 'Social 用户端 API',
                'path'  => 'app\controller',
                'key'   => 'api',
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
            'enable'     => false,
            'password'   => "123456",
            'secret_key' => "social-service-apidoc",
            'expire'     => 24 * 60 * 60
        ],
        'params' => [
            'header' => [
                ['name' => 'Authorization', 'type' => 'string', 'require' => false, 'desc' => 'Bearer access_token（登录后接口必填）'],
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
            ['name' => '400', 'desc' => '请求参数错误'],
            ['name' => '401', 'desc' => '未登录 / Token 无效'],
            ['name' => '404', 'desc' => '资源不存在'],
            ['name' => '500', 'desc' => '服务器内部错误'],
        ],
        'default_author' => 'erik',
        'default_method'  => 'GET',
        'allowCrossDomain' => true,
        'ignored_annitation' => [],
        'ignored_methods' => [],
        'database' => [],
        'docs'     => [],
        'generator' => []
    ]
];
