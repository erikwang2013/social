<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\AdminPermission;
use support\Request;
use support\Response;
use Webman\Validation\Validator;

/**
 * @Apidoc\Title("权限管理")
 */
class PermissionController extends BaseController
{
    /**
     * @Apidoc\Title("权限树")
     * @Apidoc\Group("权限管理")
     * @Apidoc\Method("GET")
     * @Apidoc\Url("/admin/permission")
     * @Apidoc\Desc("获取完整权限树结构，按排序排列")
     */
    public function index(Request $request): Response
    {
        $permissions = AdminPermission::orderBy('sort', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();

        $tree = $this->buildTree($permissions);
        return $this->success($tree);
    }

    /**
     * @Apidoc\Title("创建权限")
     * @Apidoc\Group("权限管理")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/permission")
     * @Apidoc\Desc("创建菜单/按钮/API权限节点")
     * @Apidoc\Param("name", type="string", require=true, desc="权限名称")
     * @Apidoc\Param("slug", type="string", require=true, desc="权限标识 (method.path)")
     * @Apidoc\Param("type", type="int", require=true, desc="类型: 1=菜单 2=按钮 3=API")
     * @Apidoc\Param("parent_id", type="int", require=false, desc="父权限ID")
     */
    public function store(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'slug' => 'required|string|max:100',
            'type' => 'required|in:1,2,3',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $perm = new AdminPermission();
        $perm->id = $this->generateId();
        $perm->parent_id = (int) $request->input('parent_id', 0);
        $perm->name = $request->input('name');
        $perm->slug = $request->input('slug');
        $perm->type = (int) $request->input('type');
        $perm->icon = $request->input('icon', '');
        $perm->path = $request->input('path', '');
        $perm->sort = (int) $request->input('sort', 0);
        $perm->save();

        return $this->success($this->encodeIds($perm->toArray()), '创建成功');
    }

    /**
     * @Apidoc\Title("更新权限")
     * @Apidoc\Group("权限管理")
     * @Apidoc\Method("PUT")
     * @Apidoc\Url("/admin/permission/{id}")
     * @Apidoc\Desc("更新权限节点属性")
     */
    public function update(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $perm = AdminPermission::find($id);
        if (!$perm) {
            return $this->fail('权限不存在', 404);
        }

        $perm->name = $request->input('name', $perm->name);
        $perm->icon = $request->input('icon', $perm->icon);
        $perm->path = $request->input('path', $perm->path);
        $perm->sort = (int) $request->input('sort', $perm->sort);
        $perm->save();

        return $this->success($this->encodeIds($perm->toArray()), '更新成功');
    }

    /**
     * @Apidoc\Title("删除权限")
     * @Apidoc\Group("权限管理")
     * @Apidoc\Method("DELETE")
     * @Apidoc\Url("/admin/permission/{id}")
     * @Apidoc\Desc("级联删除子权限，需密码二次确认")
     * @Apidoc\Param("password", type="string", require=true, desc="当前用户密码")
     */
    public function destroy(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $perm = AdminPermission::find($id);
        if (!$perm) {
            return $this->fail('权限不存在', 404);
        }

        $adminId = $request->adminId ?? 0;
        $error = $this->confirmPassword($adminId, $request->input('password', ''), $request);
        if ($error !== null) {
            return $this->fail($error, 422);
        }

        // 级联删除子权限
        AdminPermission::where('parent_id', $id)->delete();
        $perm->roles()->detach();
        $perm->delete();

        return $this->success([], '删除成功');
    }

    /**
     * 构建权限树
     */
    private function buildTree(array $permissions, int $parentId = 0): array
    {
        $tree = [];
        foreach ($permissions as $perm) {
            if ($perm['parent_id'] == $parentId) {
                $originalId = $perm['id'];
                $perm = $this->encodeIds($perm);
                $children = $this->buildTree($permissions, $originalId);
                if ($children) {
                    $perm['children'] = $children;
                }
                $tree[] = $perm;
            }
        }
        return $tree;
    }
}
