<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\common\Storage;
use app\common\WatermarkService;
use support\Request;
use support\Response;

/**
 * @Apidoc\Title("文件上传")
 */
class UploadController extends BaseController
{
    private array $allowExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xlsx', 'docx'];
    private int $maxSize = 10 * 1024 * 1024;

    /**
     * @Apidoc\Title("文件上传")
     * @Apidoc\Group("文件上传")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/upload")
     * @Apidoc\Desc("上传文件，支持jpg/png/gif/pdf/xlsx/docx")
     * @Apidoc\Param("file", type="file", require=true, desc="上传文件")
     * @Apidoc\Returned("url", type="string", desc="文件访问路径")
     */
    public function upload(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file) {
            return $this->fail('请选择文件', 422);
        }

        if (!$file->isValid()) {
            return $this->fail('文件上传失败', 500);
        }

        $ext = strtolower($file->getUploadExtension() ?: 'bin');
        if (!in_array($ext, $this->allowExts, true)) {
            return $this->fail('不支持的文件类型: .' . $ext, 422);
        }

        if ($file->getSize() > $this->maxSize) {
            return $this->fail('文件大小不能超过 10MB', 422);
        }

        $dateDir  = date('Y-m-d');
        $filename = md5(uniqid((string) mt_rand(), true)) . '.' . $ext;
        // 水印在系统临时目录处理，避免与 local 落盘路径重叠（同路径会被下方 unlink 误删）
        $tmp = sys_get_temp_dir() . '/up_' . $filename;
        $file->move($tmp);

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            WatermarkService::tile($tmp);
        }

        // M6c：交 Storage（local 落盘 / s3 传桶），URL 随活动服务商
        $url = Storage::put("upload/{$dateDir}/{$filename}", (string) file_get_contents($tmp));
        unlink($tmp);

        return $this->success(['url' => $url], '上传成功');
    }
}
