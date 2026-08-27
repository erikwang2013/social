<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class LiveRoom extends Model
{
    protected $table = 'live_rooms';
    protected $fillable = ['owner_id', 'title', 'status', 'push_url', 'play_url', 'started_at', 'ended_at'];
    protected $casts = ['status' => 'integer'];
}
