<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * 全局中间件配置
 *
 * 以下中间件对所有请求生效，按注册顺序依次执行。
 * 执行顺序: Cors → SecurityMiddleware (erikwang2013/security-php) → RateLimit → ApiVersion → {路由组中间件} → Controller
 */

return [
    '@' => [
        app\middleware\Cors::class,
        app\middleware\Locale::class,
        app\middleware\SecurityFilter::class,  // 基于 erikwang2013/security-php
        app\middleware\RateLimit::class,
    ],
];
