<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class GiftGiven extends Model
{
    protected $table = 'gifts_given';

    protected $fillable = ['from_uid', 'to_uid', 'room_id', 'room_type', 'gift_id', 'quantity', 'coins_total', 'client_ref'];

    protected $casts = [
        'room_type' => 'integer',
        'quantity' => 'integer',
        'coins_total' => 'integer',
    ];
}
