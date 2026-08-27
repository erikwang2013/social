<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    // service DB 前缀为 social_，映射 social_products
    protected $table = 'products';

    protected $fillable = ['platform', 'sku', 'coins', 'status'];

    protected $casts = [
        'coins' => 'integer',
        'status' => 'integer',
    ];
}
