<?php
namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use app\common\JwtHelper;
use app\common\UserStatus;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $auth = $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return json(['code' => 401, 'message' => '未登录', 'lang_key' => 'auth.unauthorized'], 401);
        }
        $payload = JwtHelper::decode($m[1]);
        $sid = (string) ($payload->sid ?? '');
        if (!$payload
            || ($payload->type ?? '') !== 'access'
            || JwtHelper::isRevoked($payload->jti)
            || ($sid !== '' && JwtHelper::isSidRevoked($sid))) {
            return json(['code' => 401, 'message' => '凭证无效或已过期', 'lang_key' => 'auth.token_invalid'], 401);
        }
        $uid = (int) $payload->sub;
        if (!UserStatus::isActive($uid)) {
            return json(['code' => 403, 'message' => '账号已被禁用', 'lang_key' => 'auth.account_disabled'], 403);
        }
        $request->uid = $uid;
        $request->jti = $payload->jti;
        $request->sid = $sid;
        return $handler($request);
    }
}
