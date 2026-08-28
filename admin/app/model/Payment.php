<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    // social_ 表，须用独立连接绕过 erik_ 前缀
    protected $connection = 'social';
    protected $table = 'social_payments';
    protected $fillable = ['user_id', 'platform', 'trade_no', 'client_ref', 'amount_cents', 'currency', 'coins', 'status', 'payload'];
    protected $casts = [
        'amount_cents' => 'integer',
        'coins' => 'integer',
    ];
}
