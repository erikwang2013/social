<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    // service DB 前缀为 social_，映射 social_payments
    protected $table = 'payments';

    protected $fillable = ['user_id', 'platform', 'trade_no', 'client_ref', 'amount_cents', 'currency', 'coins', 'status', 'payload'];

    protected $casts = [
        'amount_cents' => 'integer',
        'coins' => 'integer',
    ];
}
