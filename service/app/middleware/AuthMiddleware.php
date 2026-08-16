<?php
namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use app\common\JwtHelper;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $auth = $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return json(['code' => 401, 'message' => '未登录', 'lang_key' => 'auth.unauthorized'], 401);
        }
        $payload = JwtHelper::decode($m[1]);
        if (!$payload || ($payload->type ?? '') !== 'access' || JwtHelper::isRevoked($payload->jti)) {
            return json(['code' => 401, 'message' => '凭证无效或已过期', 'lang_key' => 'auth.token_invalid'], 401);
        }
        $request->uid = (int) $payload->sub;
        $request->jti = $payload->jti;
        return $handler($request);
    }
}
