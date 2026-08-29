<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\common\Storage;
use app\model\StorageProvider;
use support\Request;
use support\Response;
use Throwable;
use Webman\Validation\Validator;

/**
 * @Apidoc\Title("CDN 存储服务商")
 */
class StorageProviderController extends BaseController
{
    /**
     * @Apidoc\Title("服务商列表")
     * @Apidoc\Group("CDN 存储服务商")
     * @Apidoc\Url("/admin/storage/providers")
     * @Apidoc\Desc("活动服务商置顶，key/secret 不回显")
     */
    public function index(Request $request): Response
    {
        return $this->success(
            StorageProvider::orderBy('is_active', 'desc')->orderBy('id')->get()
        );
    }

    /**
     * @Apidoc\Title("创建服务商")
     * @Apidoc\Group("CDN 存储服务商")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/storage/providers")
     */
    public function store(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'driver' => 'required|in:local,s3',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }
        $driver = $request->input('driver');
        if ($driver === 's3') {
            foreach (['endpoint', 'bucket', 'cdn_url'] as $f) {
                if ($request->input($f, '') === '') {
                    return $this->fail("s3 服务商必填: $f", 422);
                }
            }
        }

        $p = new StorageProvider();
        $p->id = $this->generateId();
        $p->name = $request->input('name');
        $p->driver = $driver;
        $p->endpoint = $request->input('endpoint', '');
        $p->region = $request->input('region', 'auto');
        $p->key = $request->input('key', '');
        $p->secret = $request->input('secret', '');
        $p->bucket = $request->input('bucket', '');
        $p->cdn_url = $request->input('cdn_url', '');
        $p->enabled = (int) $request->input('enabled', 1);
        $p->is_active = 0;
        $p->save();

        return $this->success($p->toArray(), trans('messages.create_success'));
    }

    /**
     * @Apidoc\Title("更新服务商")
     * @Apidoc\Group("CDN 存储服务商")
     * @Apidoc\Method("PUT")
     * @Apidoc\Url("/admin/storage/providers/{id}")
     */
    public function update(Request $request, string $id): Response
    {
        $p = StorageProvider::find($id);
        if (!$p) {
            return $this->fail(trans('messages.config_not_found'), 404);
        }
        foreach (['name', 'driver', 'endpoint', 'region', 'key', 'secret', 'bucket', 'cdn_url'] as $f) {
            if ($request->input($f) !== null) {
                $p->{$f} = $request->input($f);
            }
        }
        if ($request->input('enabled') !== null) {
            $p->enabled = (int) $request->input('enabled');
        }
        $p->save();
        return $this->success($p->toArray(), trans('messages.update_success'));
    }

    /**
     * @Apidoc\Title("删除服务商")
     * @Apidoc\Group("CDN 存储服务商")
     * @Apidoc\Method("DELETE")
     * @Apidoc\Url("/admin/storage/providers/{id}")
     */
    public function destroy(Request $request, string $id): Response
    {
        $p = StorageProvider::find($id);
        if (!$p) {
            return $this->fail(trans('messages.config_not_found'), 404);
        }
        if ((int) $p->is_active === 1) {
            return $this->fail('活动服务商不可删除', 422);
        }
        $p->delete();
        return $this->success([], trans('messages.delete_success'));
    }

    /**
     * @Apidoc\Title("设为活动服务商")
     * @Apidoc\Group("CDN 存储服务商")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/storage/providers/{id}/activate")
     * @Apidoc\Desc("s3 先验签连接（ListObjectsV2 MaxKeys=1），失败不激活；成功后清 service 缓存")
     */
    public function activate(Request $request, string $id): Response
    {
        $p = StorageProvider::find($id);
        if (!$p) {
            return $this->fail(trans('messages.config_not_found'), 404);
        }
        try {
            Storage::verify($p);
        } catch (Throwable $e) {
            return $this->fail('连接失败: ' . $e->getMessage(), 422);
        }
        StorageProvider::where('is_active', 1)->update(['is_active' => 0]);
        StorageProvider::where('id', $p->id)->update(['is_active' => 1]);
        Storage::clearCache();
        return $this->success($p->refresh()->toArray(), '已切换为活动服务商');
    }
}
