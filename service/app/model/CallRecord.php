<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class CallRecord extends Model
{
    protected $fillable = ['caller_id', 'callee_id', 'status', 'started_at', 'ended_at'];

    protected $casts = [
        'status' => 'integer',
    ];
}
