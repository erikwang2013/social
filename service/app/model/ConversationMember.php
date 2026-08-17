<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class ConversationMember extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'role', 'status'];
}
