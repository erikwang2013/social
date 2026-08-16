<?php
namespace app\controller;

use support\Request;
use app\model\User;
use app\model\UserProfile;
use app\common\JwtHelper;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("认证")
 */
class AuthController
{
    private const LOGIN_FAIL_LIMIT = 5;
    private const LOGIN_FAIL_TTL = 900;

    /**
     * @Apidoc\Title("注册")
     * @Apidoc\Url("/api/v1/auth/register")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("email", type="string", require=true, desc="邮箱")
     * @Apidoc\Param("password", type="string", require=true, desc="密码(6-32位)")
     * @Apidoc\Param("nickname", type="string", require=false, desc="昵称")
     * @Apidoc\Returned(ref="Response")
     * @Apidoc\Returned("data", type="object", desc="token 信息")
     * @Apidoc\Returned("data.access_token", type="string", desc="访问令牌(2h)")
     * @Apidoc\Returned("data.refresh_token", type="string", desc="刷新令牌(14d)")
     */
    public function register(Request $request)
    {
        $email = trim((string) $request->post('email'));
        $password = (string) $request->post('password');
        $nickname = trim((string) $request->post('nickname', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'message' => '邮箱格式不正确', 'lang_key' => 'auth.email_invalid'], 400);
        }
        if (strlen($password) < 6 || strlen($password) > 32) {
            return json(['code' => 400, 'message' => '密码长度需 6-32 位', 'lang_key' => 'auth.password_length'], 400);
        }
        if (User::where('email', $email)->exists()) {
            return json(['code' => 409, 'message' => '邮箱已注册', 'lang_key' => 'auth.email_exists'], 409);
        }

        $user = User::create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        UserProfile::create([
            'user_id' => $user->id,
            'nickname' => $nickname !== '' ? $nickname : explode('@', $email)[0],
        ]);

        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $this->tokens($user->id)]);
    }

    /**
     * @Apidoc\Title("登录")
     * @Apidoc\Url("/api/v1/auth/login")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("email", type="string", require=true, desc="邮箱")
     * @Apidoc\Param("password", type="string", require=true, desc="密码")
     * @Apidoc\Returned(ref="Response")
     */
    public function login(Request $request)
    {
        $email = trim((string) $request->post('email'));
        $password = (string) $request->post('password');

        $redis = $this->failCounter($email);
        $key = 'login:fail:' . $email;
        if ($redis && (int) $redis->get($key) >= self::LOGIN_FAIL_LIMIT) {
            return json(['code' => 429, 'message' => '尝试次数过多，请15分钟后再试', 'lang_key' => 'auth.too_many_attempts'], 429);
        }

        $user = User::where('email', $email)->first();
        if (!$user || !password_verify($password, $user->password)) {
            if ($redis) {
                $redis->incr($key);
                $redis->expire($key, self::LOGIN_FAIL_TTL);
            }
            return json(['code' => 401, 'message' => '邮箱或密码错误', 'lang_key' => 'auth.credentials_invalid'], 401);
        }
        if ((int) $user->status !== 1) {
            return json(['code' => 403, 'message' => '账号已被禁用', 'lang_key' => 'auth.account_disabled'], 403);
        }
        if ($redis) {
            $redis->del($key);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $this->tokens($user->id)]);
    }

    /**
     * @Apidoc\Title("刷新令牌")
     * @Apidoc\Url("/api/v1/auth/refresh")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("refresh_token", type="string", require=true, desc="刷新令牌")
     * @Apidoc\Returned(ref="Response")
     */
    public function refresh(Request $request)
    {
        $token = trim((string) $request->post('refresh_token'));
        $payload = JwtHelper::decode($token);
        if (!$payload || ($payload->type ?? '') !== 'refresh' || JwtHelper::isRevoked($payload->jti)) {
            return json(['code' => 401, 'message' => '刷新令牌无效', 'lang_key' => 'auth.token_invalid'], 401);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $this->tokens((int) $payload->sub)]);
    }

    /**
     * @Apidoc\Title("登出")
     * @Apidoc\Url("/api/v1/auth/logout")
     * @Apidoc\Method("POST")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Returned(ref="Response")
     */
    public function logout(Request $request)
    {
        JwtHelper::revoke($request->jti, 7200);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok']);
    }

    /**
     * @Apidoc\Title("当前用户")
     * @Apidoc\Url("/api/v1/auth/me")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Returned(ref="Response")
     * @Apidoc\Returned("data.user", type="object", desc="用户信息")
     * @Apidoc\Returned("data.profile", type="object", desc="资料信息")
     */
    public function me(Request $request)
    {
        $user = User::find($request->uid);
        $profile = UserProfile::where('user_id', $request->uid)->first();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'user' => $user,
            'profile' => $profile,
        ]]);
    }

    private function tokens(int $userId): array
    {
        $cfg = config('plugin.erikwang2013.jwt.jwt');
        return [
            'access_token' => JwtHelper::encode($userId, 'access', (int) $cfg['default_expire']),
            'refresh_token' => JwtHelper::encode($userId, 'refresh', (int) $cfg['refresh_expire']),
            'expires_in' => (int) $cfg['default_expire'],
        ];
    }

    private function failCounter(string $email): ?\Redis
    {
        if (!redisAvailable()) return null;
        $redis = new \Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
        return $redis;
    }
}
