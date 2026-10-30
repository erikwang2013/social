<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use app\model\AdminUser;
use support\Request;
use Webman\Http\Response;
use support\Redis;
use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTFactory;
use Erikwang2013\Jwt\JWTException;

class AdminAuth
{
    private static ?JWT $jwt = null;

    private static function getJWT(): JWT
    {
        if (self::$jwt === null) {
            $config = config('plugin.erikwang2013.jwt.jwt', []);
            self::$jwt = JWTFactory::createFromConfig($config);
        }
        return self::$jwt;
    }

    public function process(Request $request, callable $next): Response
    {
        $token = $request->header('Authorization', '');
        $token = str_replace('Bearer ', '', $token);

        if (empty($token)) {
            return json(['code' => 401, 'message' => '未登录', 'data' => []]);
        }

        // 检查 JWT 黑名单
        $blacklistKey = 'jwt_blacklist:' . md5($token);
        try {
            if (Redis::get($blacklistKey)) {
                return json(['code' => 401, 'message' => 'Token已失效，请重新登录', 'data' => []]);
            }
        } catch (\Throwable $e) {
            // Redis down, skip blacklist check
        }

        try {
            $payload = self::getJWT()->decode($token);
            $tokenType = $payload['token_type'] ?? null;
            if ($tokenType !== null && $tokenType !== 'access') {
                return json(['code' => 401, 'message' => 'Token已过期或无效', 'data' => []]);
            }
            $sid = (string) ($payload['sid'] ?? '');
            if ($sid !== '') {
                try {
                    if (Redis::get('jwt:sid:' . $sid)) {
                        return json(['code' => 401, 'message' => 'Token已失效，请重新登录', 'data' => []]);
                    }
                } catch (\Throwable $e) {
                    // Redis down, skip sid blacklist check
                }
            }
            $adminId = (int) ($payload['sub'] ?? 0);
            $admin = $adminId > 0 ? AdminUser::find($adminId) : null;
            if (!$admin || (int) $admin->status !== 1) {
                return json(['code' => 401, 'message' => 'Token已过期或无效', 'data' => []]);
            }
            $request->adminId = $adminId;
            $request->adminUsername = $payload['username'] ?? '';
        } catch (JWTException | \Exception $e) {
            return json(['code' => 401, 'message' => 'Token已过期或无效', 'data' => []]);
        }

        return $next($request);
    }
}
