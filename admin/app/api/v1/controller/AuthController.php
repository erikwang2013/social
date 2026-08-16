<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\AdminUser;
use app\common\SnowflakeService;
use app\common\EncryptionService;
use support\Container;
use support\Redis;
use support\Request;
use support\Response;
use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTFactory;
use Throwable;

/**
 * @Apidoc\Title("认证")
 */
class AuthController
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

    /**
     * @Apidoc\Title("登录")
     * @Apidoc\Group("认证")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/auth/login")
     * @Apidoc\Desc("验证码校验通过后登录。密码使用 RSA-2048 公钥加密后 Base64 传输，私钥仅服务端持有")
     * @Apidoc\Param("username", type="string", require=true, desc="用户名")
     * @Apidoc\Param("password", type="string", require=true, desc="RSA-2048 PKCS1v1.5 加密后 Base64（兼容 AES/明文回退）")
     * @Apidoc\Param("captcha_key", type="string", require=true, desc="验证码key（需先通过 /api/captcha/verify）")
     * @Apidoc\Returned("access_token", type="string", desc="访问令牌")
     * @Apidoc\Returned("refresh_token", type="string", desc="刷新令牌")
     * @Apidoc\Returned("expires_in", type="int", desc="过期时间(秒)")
     */
    public function login(Request $request): Response
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $captchaKey = (string) $request->input('captcha_key', '');

        if ($username === '' || strlen($username) < 3 || strlen($username) > 50) {
            return json(['code' => 422, 'message' => '用户名格式不正确', 'data' => []]);
        }
        if ($captchaKey === '') {
            return json(['code' => 422, 'message' => '验证码参数缺失', 'data' => []]);
        }
        if ($captchaKey === '') {
            return json(['code' => 422, 'message' => '验证码参数缺失', 'data' => []]);
        }

        // 检查验证码是否已通过 /api/captcha/verify 校验
        try {
            $verified = Redis::get("captcha_verified:{$captchaKey}");
            if (!$verified) {
                return json(['code' => 422, 'message' => '请先完成验证码校验', 'data' => []]);
            }
            Redis::del("captcha_verified:{$captchaKey}");
        } catch (\Throwable) {}

        // 校验用户凭证
        $user = AdminUser::where('username', $username)->first();

        // 账号锁定检查（5次失败/15分钟）
        $lockKey = "account_lock:{$username}";
        try {
            if (Redis::get($lockKey)) {
                return json(['code' => 429, 'message' => trans('messages.account_locked'), 'data' => []]);
            }
        } catch (\Throwable) {}

        // 密码解密：RSA 非对称 > AES 对称 > 明文，逐级回退保证兼容
        $rawPassword = (string) $request->input('password', '');
        $password = EncryptionService::decryptTransmission($rawPassword);

        if ($password === '' || strlen($password) < 6 || strlen($password) > 32 || !$user || !password_verify($password, $user->password)) {
            // 登录失败：计数 + 锁定
            try {
                $failKey = "login_fail:{$username}";
                $fails = Redis::incr($failKey);
                if ($fails === 1) Redis::expire($failKey, 900);
                if ($fails >= 5) {
                    Redis::setex($lockKey, 900, '1');
                    Redis::del($failKey);
                    return json(['code' => 429, 'message' => trans('messages.account_locked'), 'data' => []]);
                }
            } catch (\Throwable) {}
            return json(['code' => 401, 'message' => trans('messages.invalid_credentials'), 'data' => []]);
        }

        // 登录成功：清除失败计数
        try { Redis::del("login_fail:{$username}"); Redis::del($lockKey); } catch (\Throwable) {}

        if ($user->status === 0) {
            return json(['code' => 403, 'message' => trans('messages.account_disabled'), 'data' => []]);
        }

        // 签发 JWT
        $jwt = self::getJWT();
        $tokenExpire = (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200);
        $token = $jwt->encode(['sub' => $user->id, 'username' => $user->username]);
        $refreshToken = $jwt->encode(['sub' => $user->id, 'token_type' => 'refresh'],
            (int)(config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600)
        );

        // 并发会话限制
        $this->trackSession($user->id, $token, $tokenExpire);

        // 更新登录信息
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $request->getRealIp();
        $user->save();

        return json([
            'code'    => 0,
            'message' => trans('messages.login_success'),
            'data'    => [
                'access_token'  => $token,
                'refresh_token' => $refreshToken,
                'expires_in'    => (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200),
                'user'          => [
                    'id'        => Container::get('hashids')->encode($user->id),
                    'username'  => $user->username,
                    'real_name' => $user->real_name,
                ],
            ],
        ]);
    }

    /**
     * @Apidoc\Title("刷新令牌")
     * @Apidoc\Group("认证")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/auth/refresh")
     * @Apidoc\Desc("使用refresh_token刷新access_token")
     * @Apidoc\Param("refresh_token", type="string", require=true, desc="刷新令牌")
     * @Apidoc\Returned("access_token", type="string", desc="访问令牌")
     * @Apidoc\Returned("refresh_token", type="string", desc="新刷新令牌")
     * @Apidoc\Returned("expires_in", type="int", desc="过期时间(秒)")
     */
    public function refresh(Request $request): Response
    {
        $refreshToken = $request->input('refresh_token', '');

        if (empty($refreshToken)) {
            return json(['code' => 422, 'message' => trans('messages.refresh_missing'), 'data' => []]);
        }

        try {
            $jwt = self::getJWT();
            $payload = $jwt->decode($refreshToken);

            // 刷新时更新最后登录时间和IP
            $userId = $payload['sub'] ?? 0;
            if ($userId) {
                $user = AdminUser::find($userId);
                if ($user) {
                    $user->last_login_at = date('Y-m-d H:i:s');
                    $user->last_login_ip = $request->getRealIp();
                    $user->save();
                }
            }

            $tokenExpire = (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200);
            $token = $jwt->encode(['sub' => $payload['sub'], 'username' => $payload['username'] ?? '']);
            $newRefresh = $jwt->encode(['sub' => $payload['sub'], 'token_type' => 'refresh'],
                (int)(config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600)
            );

            // 并发会话限制：注册新 token，移除旧 refresh token 的活跃状态
            $this->trackSession($userId, $token, $tokenExpire);
            try { Redis::zrem("user_tokens:{$userId}", md5($refreshToken)); } catch (\Throwable) {}

            return json([
                'code'    => 0,
                'message' => 'success',
                'data'    => [
                    'access_token'  => $token,
                    'refresh_token' => $newRefresh,
                    'expires_in'    => (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200),
                ],
            ]);
        } catch (Throwable $e) {
            return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
        }
    }

    /**
     * 并发会话限制 — 同一用户最多 3 个有效 token
     * @param int $userId 用户 ID
     * @param string $token JWT access_token
     * @param int $expiresIn token 有效期（秒）
     */
    private function trackSession(int $userId, string $token, int $expiresIn): void
    {
        try {
            $key = "user_tokens:{$userId}";
            $exp = time() + $expiresIn;
            $member = md5($token);

            // 清理已过期的 token
            Redis::zremrangebyscore($key, 0, time());
            // 添加新 token
            Redis::zadd($key, $exp, $member);
            // 超过 3 个 → 踢出最旧的
            $count = Redis::zcard($key);
            if ($count > 3) {
                $oldest = Redis::zrange($key, 0, 0, true);
                if ($oldest) {
                    $oldMember = array_key_first($oldest);
                    $oldExp = (int) $oldest[$oldMember];
                    $ttl = max($oldExp - time(), 0);
                    Redis::zrem($key, $oldMember);
                    if ($ttl > 0) {
                        Redis::setex("jwt_blacklist:{$oldMember}", $ttl, '1');
                    }
                }
            }
            Redis::expire($key, $expiresIn + 3600);
        } catch (\Throwable) {
            // Redis 故障不影响登录
        }
    }
}
