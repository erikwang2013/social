<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\api\v1\controller;

use support\Request;
use support\Response;
use Throwable;

/**
 * @Apidoc\Title("验证码")
 */
/**
 * @Apidoc\Title("验证码")
 */
class CaptchaController
{
    /**
     * @Apidoc\Title("生成验证码")
     * @Apidoc\Group("验证码")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/captcha/generate")
     * @Apidoc\Desc("随机生成 click/slider/rotate 三种验证码之一")
     * @Apidoc\Param("difficulty", type="string", require=false, desc="难度等级", default="medium")
     * @Apidoc\Returned("key", type="string", desc="验证码key")
     * @Apidoc\Returned("type", type="string", desc="验证码类型: click|slider|rotate")
     * @Apidoc\Returned("image", type="string", desc="base64 data URI 图片")
     * @Apidoc\Returned("extra", type="object", desc="类型相关附加数据 targets(xy坐标)/x y puzzle_w puzzle_h puzzle(滑块)/angle(旋转)")
     */
    public function generate(Request $request): Response
    {
        $difficulty = $request->input('difficulty', 'medium');

        try {
            $result = captcha_create('random');

            return json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'key'   => $result['key'],
                    'type'  => $result['type'],
                    'image' => $result['image'], // POSTER 返回 data URI，经 json_encode 后为 base64
                    'extra' => $result['extra'] ?? new \stdClass(),
                ],
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => 500,
                'message' => '验证码生成失败: ' . $e->getMessage(),
                'data' => ['file' => $e->getFile(), 'line' => $e->getLine()],
            ]);
        }
    }

    /**
     * @Apidoc\Title("校验验证码")
     * @Apidoc\Group("验证码")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/captcha/verify")
     * @Apidoc\Desc("校验验证码答案。clicks 格式: click=[{x,y}], slider=int, rotate=int")
     * @Apidoc\Param("key", type="string", require=true, desc="验证码key")
     * @Apidoc\Param("type", type="string", require=true, desc="验证码类型: click|slider|rotate")
     * @Apidoc\Param("clicks", type="mixed", require=true, desc="click=[{x,y}], slider=偏移量, rotate=角度")
     * @Apidoc\Returned("valid", type="bool", desc="是否校验通过")
     */
    public function verify(Request $request): Response
    {
        $key   = $request->input('key', '');
        $type  = $request->input('type', 'click');
        $clicks = $request->input('clicks', []);

        if (empty($key) || empty($clicks)) {
            return json(['code' => 422, 'message' => trans('messages.captcha_missing'), 'data' => []]);
        }

        $valid = captcha_verify($key, $type, $clicks);

        // 验证成功标记 key，登录接口不再重复校验
        if ($valid) {
            try { \support\Redis::setex("captcha_verified:{$key}", 300, '1'); } catch (\Throwable) {}
        }

        return json([
            'code' => $valid ? 0 : 422,
            'message' => $valid ? trans('messages.captcha_verify_pass') : trans('messages.captcha_verify_fail'),
            'data' => ['valid' => $valid],
        ]);
    }
}
