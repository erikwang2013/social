<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\AdminRole;
use support\Request;
use support\Response;
use Webman\Validation\Validator;

/**
 * @Apidoc\Title("角色管理")
 */
class RoleController extends BaseController
{
    /**
     * @Apidoc\Title("角色列表")
     * @Apidoc\Group("角色管理")
     * @Apidoc\Url("/admin/role")
     * @Apidoc\Desc("分页获取角色列表")
     * @Apidoc\Param("page", type="int", require=false, desc="页码", default="1")
     * @Apidoc\Param("limit", type="int", require=false, desc="每页条数", default="15")
     * @Apidoc\Returned("list", type="array", desc="角色列表")
     * @Apidoc\Returned("total", type="int", desc="总数")
     */
    public function index(Request $request): Response
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 15);
        $keyword = trim((string) $request->input('keyword', ''));
        $status = $request->input('status');

        $query = AdminRole::withCount('users');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('slug', 'like', "%{$keyword}%");
            });
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list = $query->offset(($page - 1) * $limit)
                      ->limit($limit)
                      ->orderBy('id', 'asc')
                      ->get()
                      ->map(fn($role) => $this->encodeIds($role->toArray()));

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * @Apidoc\Title("创建角色")
     * @Apidoc\Group("角色管理")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/role")
     * @Apidoc\Desc("创建新角色并同步权限")
     * @Apidoc\Param("name", type="string", require=true, desc="角色名称")
     * @Apidoc\Param("slug", type="string", require=true, desc="角色标识")
     * @Apidoc\Param("description", type="string", require=false, desc="角色描述")
     * @Apidoc\Param("status", type="int", require=false, desc="状态", default="1")
     * @Apidoc\Param("permission_ids", type="array", require=false, desc="权限ID数组")
     */
    public function store(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'slug' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $role = new AdminRole();
        $role->id = $this->generateId();
        $role->name = $request->input('name');
        $role->slug = $request->input('slug');
        $role->description = $request->input('description', '');
        $role->status = (int) $request->input('status', 1);
        $role->save();

        // 同步权限（hashid → int）
        if ($request->input('permission_ids') !== null) {
            $ids = array_map(fn($hid) => $this->decodeId((string) $hid), $request->input('permission_ids', []));
            $role->permissions()->sync($ids);
        }

        return $this->success($this->encodeIds($role->toArray()), '创建成功');
    }

    /**
     * @Apidoc\Title("更新角色")
     * @Apidoc\Group("角色管理")
     * @Apidoc\Method("PUT")
     * @Apidoc\Url("/admin/role/{id}")
     * @Apidoc\Desc("更新指定角色信息并同步权限")
     * @Apidoc\Param("id", type="string", require=true, desc="角色hashid")
     * @Apidoc\Param("name", type="string", require=false, desc="角色名称")
     * @Apidoc\Param("description", type="string", require=false, desc="角色描述")
     * @Apidoc\Param("status", type="int", require=false, desc="状态")
     * @Apidoc\Param("permission_ids", type="array", require=false, desc="权限ID数组")
     */
    public function update(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $role = AdminRole::find($id);
        if (!$role) {
            return $this->fail('角色不存在', 404);
        }
        if ($role->slug === 'super_admin') {
            return $this->fail('超级管理员不可编辑', 403);
        }

        $role->name = $request->input('name', $role->name);
        $role->description = $request->input('description', $role->description);
        $role->status = (int) $request->input('status', $role->status);
        $role->save();

        if ($request->input('permission_ids') !== null) {
            $ids = array_map(fn($hid) => $this->decodeId((string) $hid), $request->input('permission_ids', []));
            $role->permissions()->sync($ids);
        }

        return $this->success($this->encodeIds($role->toArray()), '更新成功');
    }

    /**
     * @Apidoc\Title("删除角色")
     * @Apidoc\Group("角色管理")
     * @Apidoc\Method("DELETE")
     * @Apidoc\Url("/admin/role/{id}")
     * @Apidoc\Desc("删除指定角色，需密码二次确认")
     * @Apidoc\Param("id", type="string", require=true, desc="角色hashid")
     * @Apidoc\Param("password", type="string", require=true, desc="当前管理员密码")
     */
    public function destroy(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $role = AdminRole::find($id);
        if (!$role) {
            return $this->fail('角色不存在', 404);
        }
        if ($role->slug === 'super_admin') {
            return $this->fail('超级管理员不可删除', 403);
        }

        $adminId = $request->adminId ?? 0;
        $error = $this->confirmPassword($adminId, $request->input('password', ''), $request);
        if ($error !== null) {
            return $this->fail($error, 422);
        }

        $role->permissions()->detach();
        $role->users()->detach();
        $role->delete();

        return $this->success([], '删除成功');
    }
}
