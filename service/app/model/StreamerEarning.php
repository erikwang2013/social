<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class StreamerEarning extends Model
{
    protected $fillable = ['streamer_uid', 'gift_given_id', 'ratio', 'coins_amount'];

    protected $casts = [
        'ratio' => 'integer',
        'coins_amount' => 'integer',
    ];
}
