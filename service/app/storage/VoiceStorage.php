<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\storage;

class VoiceStorage
{
    public const MAX_BYTES = 2 * 1024 * 1024;   // ≤2MB
    public const MAX_SECONDS = 60;              // ≤60s

    public function __construct(private string $dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    /** 校验 → 转 m4a（AAC 32k 单声道）→ 落盘 → 返回 {name, url, duration} */
    public function ingest(string $src): array
    {
        if (filesize($src) > self::MAX_BYTES) {
            throw new \RuntimeException('voice.too_large', 413);
        }
        $name = md5(random_bytes(16)) . '.m4a';
        $dst = $this->dir . '/' . $name;
        exec('ffmpeg -y -i ' . escapeshellarg($src) . ' -c:a aac -b:a 32k -ac 1 -vn ' . escapeshellarg($dst) . ' 2>/dev/null');
        if (!is_file($dst)) {
            throw new \RuntimeException('voice.transcode_failed', 500);
        }
        $duration = $this->probe($dst);
        if ($duration > self::MAX_SECONDS) {
            @unlink($dst);
            throw new \RuntimeException('voice.too_long', 400);
        }
        return ['name' => $name, 'url' => '/voice/' . $name, 'duration' => $duration];
    }

    private function probe(string $file): int
    {
        exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($file) . ' 2>/dev/null', $out);
        return (int) round((float) ($out[0] ?? 0));
    }
}
