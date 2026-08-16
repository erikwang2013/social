<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\AdminUser;
use app\common\EncryptionService;
use support\Request;
use support\Response;
use Webman\Validation\Validator;

/**
 * @Apidoc\Title("用户管理")
 */
class UserController extends BaseController
{
    /**
     * @Apidoc\Title("用户列表")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Url("/admin/user")
     * @Apidoc\Desc("分页获取用户列表，支持关键词搜索和状态筛选")
     * @Apidoc\Param("page", type="int", require=false, desc="页码", default="1")
     * @Apidoc\Param("limit", type="int", require=false, desc="每页条数", default="15")
     * @Apidoc\Param("keyword", type="string", require=false, desc="搜索关键词")
     * @Apidoc\Param("status", type="int", require=false, desc="状态筛选(0禁用1启用)")
     * @Apidoc\Returned("list", type="array", desc="用户列表")
     * @Apidoc\Returned("total", type="int", desc="总数")
     * @Apidoc\Returned("page", type="int", desc="当前页码")
     * @Apidoc\Returned("limit", type="int", desc="每页条数")
     */
    public function index(Request $request): Response
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 15);
        $keyword = $request->input('keyword', '');
        $status = $request->input('status');

        $query = AdminUser::query();
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                  ->orWhere('real_name', 'like', "%{$keyword}%");
            });
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list = $query->offset(($page - 1) * $limit)
                      ->limit($limit)
                      ->orderBy('id', 'desc')
                      ->get()
                      ->map(function ($user) {
                          $data = $user->toArray();
                          unset($data['password'], $data['id_card']);
                          // 脱敏处理（Encryptable cast 已自动解密，直接对明文脱敏）
                          if (!empty($data['phone'])) {
                              $data['phone'] = preg_replace('/^(\d{3})\d+(\d{4})$/', '$1****$2', $data['phone']);
                          }
                          if (!empty($data['email'])) {
                              $parts = explode('@', $data['email']);
                              $data['email'] = mb_substr($parts[0], 0, 1) . '***@' . ($parts[1] ?? '');
                          }
                          return $this->encodeIds($data);
                      });

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * @Apidoc\Title("创建用户")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/user")
     * @Apidoc\Desc("创建新用户")
     * @Apidoc\Param("username", type="string", require=true, desc="用户名")
     * @Apidoc\Param("password", type="string", require=true, desc="密码")
     * @Apidoc\Param("real_name", type="string", require=true, desc="真实姓名")
     * @Apidoc\Param("status", type="int", require=false, desc="状态(0禁用1启用)", default="1")
     * @Apidoc\Param("phone", type="string", require=false, desc="手机号")
     * @Apidoc\Param("email", type="string", require=false, desc="邮箱")
     */
    public function store(Request $request): Response
    {
        $password = EncryptionService::decryptTransmission($request->input('password', ''));
        $phone = EncryptionService::decryptTransmission($request->input('phone', ''));
        $email = EncryptionService::decryptTransmission($request->input('email', ''));

        $data = array_merge($request->all(), [
            'password' => $password,
            'phone' => $phone,
            'email' => $email,
        ]);

        $validator = Validator::make($data, [
            'username' => 'required|string|min:3|max:50',
            'password' => 'required|string|min:6|max:32',
            'real_name' => 'required|string|max:50',
            'status' => 'in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $exists = AdminUser::where('username', $request->input('username'))->exists();
        if ($exists) {
            return $this->fail(trans('messages.username_exists'), 422);
        }

        $user = new AdminUser();
        $user->id = $this->generateId();
        $user->username = $request->input('username');
        $user->password = password_hash($password, PASSWORD_BCRYPT);
        $user->real_name = $request->input('real_name');
        $user->status = (int) $request->input('status', 1);
        $user->phone = $phone;
        $user->email = $email;
        $user->save();

        $udata = $user->toArray();
        unset($udata['password'], $udata['id_card']);
        return $this->success($this->encodeIds($udata), trans('messages.create_success'));
    }

    /**
     * @Apidoc\Title("用户详情")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Url("/admin/user/{id}")
     * @Apidoc\Desc("获取指定用户的详细信息")
     * @Apidoc\Param("id", type="string", require=true, desc="用户hashid")
     */
    public function show(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $data = $user->toArray();
        unset($data['password'], $data['id_card']);
        // Encryptable cast 已自动解密，phone/email 直接为明文
        return $this->success($this->encodeIds($data));
    }

    /**
     * @Apidoc\Title("更新用户")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("PUT")
     * @Apidoc\Url("/admin/user/{id}")
     * @Apidoc\Desc("更新指定用户的信息")
     * @Apidoc\Param("id", type="string", require=true, desc="用户hashid")
     * @Apidoc\Param("real_name", type="string", require=false, desc="真实姓名")
     * @Apidoc\Param("status", type="int", require=false, desc="状态")
     * @Apidoc\Param("password", type="string", require=false, desc="新密码")
     * @Apidoc\Param("phone", type="string", require=false, desc="手机号")
     * @Apidoc\Param("email", type="string", require=false, desc="邮箱")
     */
    public function update(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $user->real_name = $request->input('real_name', $user->real_name);
        $user->status = (int) $request->input('status', $user->status);

        if ($request->input('password') !== null && $request->input('password') !== '') {
            $user->password = password_hash(EncryptionService::decryptTransmission($request->input('password')), PASSWORD_BCRYPT);
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
        return $this->success($this->encodeIds($data), trans('messages.update_success'));
    }

    /**
     * @Apidoc\Title("删除用户")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("DELETE")
     * @Apidoc\Url("/admin/user/{id}")
     * @Apidoc\Desc("软删除指定用户，需密码二次确认")
     * @Apidoc\Param("id", type="string", require=true, desc="用户hashid")
     * @Apidoc\Param("password", type="string", require=true, desc="当前管理员密码")
     */
    public function destroy(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $adminId = $request->adminId ?? 0;
        $error = $this->confirmPassword($adminId, $request->input('password', ''), $request);
        if ($error !== null) {
            return $this->fail($error, 422);
        }

        $user->delete();
        return $this->success([], trans('messages.delete_success'));
    }

    /**
     * @Apidoc\Title("批量删除")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/user/batch/destroy")
     * @Apidoc\Desc("批量删除用户，需密码二次确认")
     * @Apidoc\Param("ids", type="array", require=true, desc="用户hashid数组")
     * @Apidoc\Param("password", type="string", require=true, desc="当前管理员密码")
     */
    public function batchDestroy(Request $request): Response
    {
        $ids      = $request->input('ids', []);
        $password = $request->input('password', '');

        if (empty($ids) || !is_array($ids)) {
            return $this->fail(trans('messages.no_user_selection'), 422);
        }

        $adminId = $request->adminId ?? 0;
        $error   = $this->confirmPassword($adminId, $password, $request);
        if ($error !== null) {
            return $this->fail($error, 422);
        }

        $decodedIds = [];
        $invalidIds = [];
        foreach ($ids as $id) {
            try {
                $decodedIds[] = $this->decodeId($id);
            } catch (\InvalidArgumentException $e) {
                $invalidIds[] = $id;
            }
        }
        if (!empty($invalidIds)) {
            return $this->fail(trans('messages.invalid_ids') . ': ' . implode(', ', $invalidIds), 422);
        }

        AdminUser::whereIn('id', $decodedIds)->delete();

        return $this->success(['count' => count($decodedIds)], '删除成功');
    }

    /**
     * @Apidoc\Title("批量启用/禁用")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/user/batch/status")
     * @Apidoc\Desc("批量修改用户启用/禁用状态")
     * @Apidoc\Param("ids", type="array", require=true, desc="用户hashid数组")
     * @Apidoc\Param("status", type="int", require=true, desc="目标状态(0禁用1启用)")
     */
    public function batchStatus(Request $request): Response
    {
        $ids    = $request->input('ids', []);
        $status = (int) $request->input('status', 0);

        if (empty($ids) || !is_array($ids)) {
            return $this->fail(trans('messages.no_user_selection_status'), 422);
        }

        if (!in_array($status, [0, 1], true)) {
            return $this->fail(trans('messages.invalid_status'), 422);
        }

        $decodedIds = [];
        $invalidIds = [];
        foreach ($ids as $id) {
            try {
                $decodedIds[] = $this->decodeId($id);
            } catch (\InvalidArgumentException $e) {
                $invalidIds[] = $id;
            }
        }
        if (!empty($invalidIds)) {
            return $this->fail(trans('messages.invalid_ids') . ': ' . implode(', ', $invalidIds), 422);
        }

        AdminUser::whereIn('id', $decodedIds)->update(['status' => $status]);

        $message = $status === 1 ? trans('messages.batch_enable_success') : trans('messages.batch_disable_success');
        return $this->success(['count' => count($decodedIds)], $message);
    }
}
