<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

/**
 * 平铺水印：45° 旋转半透明白字，防搬运。
 */
class WatermarkService
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    /** 就地加水印；非图片或 GD 不支持时静默跳过 */
    public static function tile(string $path, string $text = 'https://erik.xyz'): void
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $img = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            default => false,
        };
        if ($img === false) {
            return;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 64 || $h < 64) {
            imagedestroy($img);
            return;
        }

        $fontSize = max(12, (int) round(min($w, $h) / 30));
        $box = imagettfbbox($fontSize, 0, self::FONT, $text);
        $tw = (int) ($box[2] - $box[0]);
        $th = (int) ($box[1] - $box[7]);

        // 45° 旋转后的包围盒
        $pad = (int) ($th * 0.8);
        $tile = imagecreatetruecolor($tw + $pad * 2, $th + $pad * 2);
        imagesavealpha($tile, true);
        $transparent = imagecolorallocatealpha($tile, 255, 255, 255, 127);
        imagefill($tile, 0, 0, $transparent);
        $white = imagecolorallocatealpha($tile, 255, 255, 255, 100);
        imagettftext($tile, $fontSize, 0, $pad, $pad + $th, $white, self::FONT, $text);
        $rot = imagerotate($tile, 45, $transparent);
        $rw = imagesx($rot);
        $rh = imagesy($rot);

        // 平铺覆盖全图
        for ($y = -$rh; $y < $h; $y += $rh) {
            for ($x = -$rw; $x < $w; $x += $rw) {
                imagecopy($img, $rot, $x, $y, 0, 0, $rw, $rh);
            }
        }

        // ponytail: gif 仅首帧加水印，动图多帧需逐帧合成
        match ($ext) {
            'jpg', 'jpeg' => imagejpeg($img, $path, 90),
            'png' => imagepng($img, $path),
            'gif' => imagegif($img, $path),
            default => null,
        };
        imagedestroy($img);
        imagedestroy($tile);
        imagedestroy($rot);
    }
}
