<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    // service DB 前缀为 social_，映射 social_withdrawals
    protected $table = 'withdrawals';

    protected $fillable = ['user_id', 'platform', 'account', 'coins', 'currency', 'status', 'reason', 'client_ref'];

    protected $casts = [
        'coins' => 'integer',
    ];
}
