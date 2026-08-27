<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\LiveSync;
use Live\V1\GetVoiceFileRequest;
use Live\V1\UploadVoiceRequest;
use support\Request;
use Webman\Http\Response;

class ImVoiceController
{
    /** 上传语音：multipart field=voice → {voice_url, voice_duration} */
    public function upload(Request $request): Response
    {
        $file = $request->file('voice');
        if ($file === null || !$file->isValid()) {
            return json(['code' => 400, 'message' => '缺少 voice 文件', 'lang_key' => 'voice.file_required'], 400);
        }
        $req = new UploadVoiceRequest();
        $req->setUid((int) $request->uid);
        $req->setVoice(file_get_contents($file->getPathname()));
        return LiveSync::respond(LiveSync::voiceRpc(fn($c) => $c->UploadVoice($req)));
    }

    /** 静态语音文件（Rust 侧白名单校验，PHP 以 audio/mp4 转发） */
    public function voiceFile(Request $request, string $file): Response
    {
        $req = new GetVoiceFileRequest();
        $req->setFile($file);
        $r = LiveSync::voiceRpc(fn($c) => $c->GetVoiceFile($req));
        if ($r === null || $r['code'] !== 0) {
            return LiveSync::respond($r);
        }
        return \response($r['bytes_data'])->withHeader('Content-Type', 'audio/mp4');
    }
}
