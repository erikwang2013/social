<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\storage\VoiceStorage;
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
        try {
            $out = (new VoiceStorage(base_path() . '/storage/voice'))->ingest($file->getPathname());
        } catch (\RuntimeException $e) {
            $code = is_int($e->getCode()) && $e->getCode() > 0 ? $e->getCode() : 500;
            return json(['code' => $code, 'message' => $e->getMessage(), 'lang_key' => $e->getMessage()], $code);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'voice_url' => $out['url'],
            'voice_duration' => $out['duration'],
        ]]);
    }

    /** 静态语音文件（白名单防路径穿越） */
    public function voiceFile(Request $request, string $file): Response
    {
        if (!preg_match('/^[a-f0-9]{32}\.m4a$/', $file)) {
            return json(['code' => 400, 'message' => 'bad file', 'lang_key' => 'voice.bad_file'], 400);
        }
        $path = base_path() . '/storage/voice/' . $file;
        if (!is_file($path)) {
            return json(['code' => 404, 'message' => 'not found', 'lang_key' => 'voice.not_found'], 404);
        }
        return \response()->withFile($path)->withHeader('Content-Type', 'audio/mp4');
    }
}
