<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class VoiceRoomMember extends Model
{
    protected $table = 'voice_room_members';
    protected $fillable = ['room_id', 'user_id', 'role'];
    protected $casts = ['role' => 'integer'];
}
