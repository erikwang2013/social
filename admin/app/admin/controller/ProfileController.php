<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\common\EncryptionService;
use app\model\AdminUser;
use support\Request;
use support\Response;
use support\Redis;
use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTFactory;

/**
 * @Apidoc\Title("个人中心")
 */
class ProfileController extends BaseController
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
     * @Apidoc\Title("获取个人信息")
     * @Apidoc\Group("个人中心")
     * @Apidoc\Method("GET")
     * @Apidoc\Url("/admin/profile")
     * @Apidoc\Desc("获取当前登录管理员的个人信息")
     */
    public function show(Request $request): Response
    {
        $adminId = $request->adminId ?? 0;
        $user    = AdminUser::find($adminId);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $data = $user->toArray();
        unset($data['password'], $data['id_card']);

        return $this->success($this->encodeIds($data));
    }

    /**
     * @Apidoc\Title("更新个人信息")
     * @Apidoc\Group("个人中心")
     * @Apidoc\Method("PUT")
     * @Apidoc\Url("/admin/profile")
     * @Apidoc\Desc("更新当前登录管理员的个人信息")
     * @Apidoc\Param("real_name", type="string", require=false, desc="真实姓名")
     * @Apidoc\Param("phone", type="string", require=false, desc="手机号")
     * @Apidoc\Param("email", type="string", require=false, desc="邮箱")
     */
    public function updateProfile(Request $request): Response
    {
        $adminId = $request->adminId ?? 0;
        $user    = AdminUser::find($adminId);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        if ($request->input('real_name') !== null) {
            $user->real_name = $request->input('real_name');
        }
        if ($request->input('phone') !== null) {
            $user->phone = EncryptionService::decryptTransmission($request->input('phone', ''));
        }
        if ($request->input('email') !== null) {
            $user->email = EncryptionService::decryptTransmission($request->input('email', ''));
        }

        $user->save();

        $data = $user->toArray();
        unset($data['password'], $data['id_card']);
        // phone/email 由 Encryptable cast 自动加解密，无需额外处理

        return $this->success($this->encodeIds($data), trans('messages.update_success'));
    }

    /**
     * @Apidoc\Title("修改密码")
     * @Apidoc\Group("个人中心")
     * @Apidoc\Method("PUT")
     * @Apidoc\Url("/admin/profile/password")
     * @Apidoc\Desc("修改当前登录管理员的密码")
     * @Apidoc\Param("old_password", type="string", require=true, desc="旧密码")
     * @Apidoc\Param("new_password", type="string", require=true, desc="新密码")
     */
    public function updatePassword(Request $request): Response
    {
        $adminId = $request->adminId ?? 0;
        $user    = AdminUser::find($adminId);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $oldPassword = EncryptionService::decryptTransmission($request->input('old_password', ''));
        $newPassword = EncryptionService::decryptTransmission($request->input('new_password', ''));

        if ($oldPassword === '' || $newPassword === '') {
            return $this->fail(trans('messages.password_required'), 422);
        }

        if (!password_verify($oldPassword, $user->password)) {
            return $this->fail(trans('messages.old_password_wrong'), 422);
        }

        if (strlen($newPassword) < 6 || strlen($newPassword) > 32) {
            return $this->fail(trans('messages.password_too_short'), 422);
        }

        $user->password = password_hash($newPassword, PASSWORD_BCRYPT);
        $user->save();

        return $this->success([], trans('messages.password_changed'));
    }

    /**
     * @Apidoc\Title("登出")
     * @Apidoc\Group("个人中心")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/profile/logout")
     * @Apidoc\Desc("当前管理员登出，Token加入黑名单")
     */
    public function logout(Request $request): Response
    {
        $token = $request->header('Authorization', '');
        $token = str_replace('Bearer ', '', $token);

        if (empty($token)) {
            return $this->fail(trans('messages.not_logged_in'), 401);
        }

        try {
            $payload = self::getJWT()->decode($token);
            $ttl     = max((int)($payload['exp'] ?? 0) - time(), 0);
            Redis::setex('jwt_blacklist:' . md5($token), $ttl, '1');
        } catch (\Throwable $e) {
            // token 无效也视为登出成功
        }

        return $this->success([], trans('messages.logout_success'));
    }
}
