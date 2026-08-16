<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

/**
 * poster-php 配置
 * 点击验证码 + 海报生成
 * @link https://github.com/erikwang2013/poster-php
 */
return [
    // ── 图像处理驱动 ──
    'image' => [
        // 驱动类型: auto=自动检测 | gd | imagick
        'driver' => getenv('POSTER_IMAGE_DRIVER') ?: 'auto',
        // JPEG 输出质量 0-100
        'quality' => (int)(getenv('POSTER_IMAGE_QUALITY') ?: 90),
        // 默认字体路径，null=使用包自带字体
        'font' =>  dirname(__DIR__) . '/src/fonts/Alibaba-PuHuiTi-Regular.ttf',
    ],

    // ── 验证码模块 ──
    'captcha' => [
        // 验证数据存储: auto=自动检测 | file | session | redis
        'storage' => getenv('POSTER_CAPTCHA_STORAGE') ?: 'auto',
        // 验证码有效期（秒），过期后 key 作废
        'ttl' => (int)(getenv('POSTER_CAPTCHA_TTL') ?: 300),
        // 同一 key 最多验证次数，防暴力枚举
        'max_attempts' => (int)(getenv('POSTER_CAPTCHA_MAX_ATTEMPTS') ?: 3),
        // 默认难度: easy=2个点 | medium=3个点 | hard=4个点
        'default_difficulty' => getenv('POSTER_CAPTCHA_DIFFICULTY') ?: 'medium',

         // null = 使用程序化生成
        // Background image directory; null = procedural generation
        'background_dir' =>base_path() .'/public/img',

        // 程序化背景风格 / Procedural background styles
        // Available: 'minimal', 'vibrant', 'natural'
        'background_styles' => ['minimal', 'vibrant', 'natural'],


        // 验证误差容忍
        'tolerance' => [
            'click'  => 18,   // 点击验证像素半径
            'rotate' => 5,    // 旋转验证角度
            'slider' => 4,    // 滑块验证像素
        ],
        // Redis 存储配置（storage=redis 时生效）
        'redis' => [
            'prefix'     => 'poster:captcha:',
            'connection' => 'default',
        ],
        // 文件存储配置（storage=file 时生效）
        'file' => [
            'path' => null, // null=使用系统临时目录
        ],
         'click_words' => [
            '合',
            '家',
            '欢',
            '乐',
            '良',
            '辰',
            '美',
            '景',
            '千',
            '变',
            '万',
            '化',
            '心',
            '有',
            '灵',
            '犀',
            '五',
            '湖',
            '四',
            '海',
            '山',
            '川',
            '美',
            '景',
            '花',
            '好',
            '月',
            '圆'

        ]
    ],

    // ── 海报生成模块 ──
    'poster' => [
        'default_width'  => 750,
        'default_height' => 1334,
        'font'           =>  dirname(__DIR__) . '/src/fonts/Alibaba-PuHuiTi-Regular.ttf',
        'jpeg_quality'   => 90,
        'png_compression' => 6,
    ],
];
