<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\SystemConfig;
use support\Request;
use support\Response;
use Webman\Validation\Validator;

/**
 * @Apidoc\Title("系统配置")
 */
class ConfigController extends BaseController
{
    /**
     * @Apidoc\Title("配置列表")
     * @Apidoc\Group("系统配置")
     * @Apidoc\Url("/admin/config")
     * @Apidoc\Desc("分页获取系统配置列表，支持按分组筛选")
     * @Apidoc\Param("page", type="int", require=false, desc="页码", default="1")
     * @Apidoc\Param("limit", type="int", require=false, desc="每页条数", default="15")
     * @Apidoc\Param("group", type="string", require=false, desc="配置分组")
     * @Apidoc\Returned("list", type="array", desc="配置列表")
     * @Apidoc\Returned("total", type="int", desc="总数")
     */
    public function index(Request $request): Response
    {
        $page  = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 15);
        $group = $request->input('group', '');

        $query = SystemConfig::query();
        if ($group !== '') {
            $query->where('group', $group);
        }

        $total = $query->count();
        $list  = $query->offset(($page - 1) * $limit)
                       ->limit($limit)
                       ->orderBy('group')
                       ->orderBy('key')
                       ->get()
                       ->map(fn($item) => $this->encodeIds($item->toArray()));

        return $this->success([
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * @Apidoc\Title("创建配置")
     * @Apidoc\Group("系统配置")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/config")
     * @Apidoc\Desc("创建新的系统配置项")
     * @Apidoc\Param("group", type="string", require=true, desc="配置分组")
     * @Apidoc\Param("key", type="string", require=true, desc="配置键名")
     * @Apidoc\Param("value", type="string", require=true, desc="配置值")
     * @Apidoc\Param("type", type="string", require=false, desc="值类型", default="string")
     * @Apidoc\Param("description", type="string", require=false, desc="配置说明")
     */
    public function store(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'group' => 'required|string|max:100',
            'key'   => 'required|string|max:100',
            'value' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $exists = SystemConfig::where('group', $request->input('group'))
                              ->where('key', $request->input('key'))
                              ->exists();
        if ($exists) {
            return $this->fail(trans('messages.config_exists'), 422);
        }

        $config = new SystemConfig();
        $config->id          = $this->generateId();
        $config->group       = $request->input('group');
        $config->key         = $request->input('key');
        $config->value       = $request->input('value');
        $config->type        = $request->input('type', 'string');
        $config->description = $request->input('description', '');
        $config->save();

        return $this->success($this->encodeIds($config->toArray()), trans('messages.create_success'));
    }

    /**
     * @Apidoc\Title("更新配置")
     * @Apidoc\Group("系统配置")
     * @Apidoc\Method("PUT")
     * @Apidoc\Url("/admin/config/{id}")
     * @Apidoc\Desc("更新指定配置项")
     * @Apidoc\Param("id", type="string", require=true, desc="配置hashid")
     * @Apidoc\Param("value", type="string", require=false, desc="配置值")
     * @Apidoc\Param("type", type="string", require=false, desc="值类型")
     * @Apidoc\Param("description", type="string", require=false, desc="配置说明")
     */
    public function update(Request $request, string $id): Response
    {
        $id     = $this->decodeId($id);
        $config = SystemConfig::find($id);
        if (!$config) {
            return $this->fail(trans('messages.config_not_found'), 404);
        }

        if ($request->input('value') !== null) {
            $config->value = $request->input('value');
        }
        if ($request->input('type') !== null) {
            $config->type = $request->input('type');
        }
        if ($request->input('description') !== null) {
            $config->description = $request->input('description');
        }

        $config->save();

        return $this->success($this->encodeIds($config->toArray()), trans('messages.update_success'));
    }

    /**
     * @Apidoc\Title("删除配置")
     * @Apidoc\Group("系统配置")
     * @Apidoc\Method("DELETE")
     * @Apidoc\Url("/admin/config/{id}")
     * @Apidoc\Desc("删除指定配置项，需密码二次确认")
     * @Apidoc\Param("id", type="string", require=true, desc="配置hashid")
     * @Apidoc\Param("password", type="string", require=true, desc="当前管理员密码")
     */
    public function destroy(Request $request, string $id): Response
    {
        $id     = $this->decodeId($id);
        $config = SystemConfig::find($id);
        if (!$config) {
            return $this->fail(trans('messages.config_not_found'), 404);
        }

        $adminId = $request->adminId ?? 0;
        $error   = $this->confirmPassword($adminId, $request->input('password', ''), $request);
        if ($error !== null) {
            return $this->fail($error, 422);
        }

        $config->delete();
        return $this->success([], trans('messages.delete_success'));
    }
}
