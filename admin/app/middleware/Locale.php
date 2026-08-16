<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use support\Container;

/**
 * 语言检测中间件
 *
 * 按优先级确定当前请求的语言:
 * 1. 查询参数 ?lang=en
 * 2. Accept-Language 请求头
 * 3. 默认语言 (zh_CN)
 */
class Locale implements MiddlewareInterface
{
    /** 支持的语言列表 */
    private const SUPPORTED = ['zh_CN', 'en'];

    /** 默认语言 */
    private const DEFAULT = 'zh_CN';

    public function process(Request $request, callable $handler): Response
    {
        $locale = self::DEFAULT;

        // 1. 显式查询参数 ?lang=en
        $lang = $request->get('lang', '');
        if ($lang && in_array($lang, self::SUPPORTED, true)) {
            $locale = $lang;
        } else {
            // 2. Accept-Language 头
            $acceptLang = $request->header('Accept-Language', '');
            foreach (self::SUPPORTED as $supported) {
                $prefix = substr($supported, 0, 2);
                if (stripos($acceptLang, $prefix) !== false) {
                    $locale = $supported;
                    break;
                }
            }
        }

        locale($locale);
        return $handler($request);
    }
}
