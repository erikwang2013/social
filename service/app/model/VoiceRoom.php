<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class VoiceRoom extends Model
{
    protected $table = 'voice_rooms';
    protected $fillable = ['owner_id', 'name', 'status'];
    protected $casts = ['status' => 'integer'];
}
