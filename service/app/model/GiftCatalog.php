<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class GiftCatalog extends Model
{
    protected $table = 'gift_catalog';

    protected $fillable = ['name', 'coins_price', 'effect_key', 'status', 'sort'];

    protected $casts = [
        'coins_price' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
    ];
}
