<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    // social_ 表，须用独立连接绕过 erik_ 前缀
    protected $connection = 'social';
    protected $table = 'social_products';
    protected $fillable = ['platform', 'sku', 'coins', 'status'];
    protected $casts = [
        'coins' => 'integer',
        'status' => 'integer',
    ];
}
