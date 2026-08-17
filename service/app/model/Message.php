<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'sender_id', 'client_msg_id', 'type',
        'content', 'image_url', 'recall_status', 'recall_at',
    ];

    protected $casts = [
        'type' => 'integer',
        'recall_status' => 'integer',
    ];
}
