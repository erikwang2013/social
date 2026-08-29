<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\Storage;
use app\common\WatermarkService;
use support\Request;
use Webman\Http\Response;

class ImageController
{
    private const MAX_SIZE = 10 * 1024 * 1024;

    /** 图片上传：multipart field=image → 水印 → Storage（local 落盘 / s3 传桶）→ {url, width, height} */
    public function upload(Request $request): Response
    {
        $file = $request->file('image');
        if ($file === null || !$file->isValid()) {
            return json(['code' => 400, 'message' => '缺少 image 文件', 'lang_key' => 'image.file_required'], 400);
        }
        $ext = strtolower((string) $file->getUploadExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return json(['code' => 422, 'message' => '仅支持 jpg/png/gif', 'lang_key' => 'image.ext_invalid'], 422);
        }
        if ($file->getSize() > self::MAX_SIZE) {
            return json(['code' => 422, 'message' => '文件大小不能超过 10MB', 'lang_key' => 'image.too_large'], 422);
        }
        $size = @getimagesize($file->getPathname());
        if ($size === false) {
            return json(['code' => 422, 'message' => '非有效图片', 'lang_key' => 'image.invalid'], 422);
        }

        $dateDir = date('Y-m-d');
        $filename = md5(uniqid((string) mt_rand(), true)) . '.' . $ext;
        // 水印在系统临时目录处理，避免与 local 落盘路径重叠（同路径会被下方 unlink 误删）
        $tmp = sys_get_temp_dir() . '/wm_' . $filename;
        $file->move($tmp);
        WatermarkService::tile($tmp);

        // M6c：水印后交 Storage（local 落盘 / s3 传桶），URL 随活动服务商
        $url = Storage::put('upload/' . $dateDir . '/' . $filename, (string) file_get_contents($tmp));
        unlink($tmp);

        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'image.uploaded', 'data' => [
            'url' => $url,
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ]]);
    }
}
