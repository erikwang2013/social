<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class CurrencyTransaction extends Model
{
    protected $fillable = ['user_id', 'type', 'amount', 'balance_after', 'ref_type', 'ref_id', 'note'];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];
}
