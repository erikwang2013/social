<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\WatermarkService;
use PHPUnit\Framework\TestCase;

class WatermarkServiceTest extends TestCase
{
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    private function makeJpeg(int $w, int $h): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wm') . '.jpg';
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 100, 50));
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        $this->tmpFiles[] = $path;
        return $path;
    }

    public function testJpegGetsWatermarked(): void
    {
        $path = $this->makeJpeg(320, 240);
        $before = md5_file($path);
        [$w, $h] = getimagesize($path);

        WatermarkService::tile($path);

        $this->assertNotSame($before, md5_file($path));
        [$w2, $h2] = getimagesize($path);
        $this->assertSame([$w, $h], [$w2, $h2]); // 尺寸不变，就地改写
    }

    public function testNonImageUntouched(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wm');
        file_put_contents($path, 'not an image');
        $this->tmpFiles[] = $path;
        $before = md5_file($path);

        WatermarkService::tile($path);

        $this->assertSame($before, md5_file($path));
    }

    public function testTinyImageSkipped(): void
    {
        $path = $this->makeJpeg(32, 32);
        $before = md5_file($path);

        WatermarkService::tile($path);

        $this->assertSame($before, md5_file($path)); // <64px 不加
    }
}
