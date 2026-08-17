<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'platform', 'token'];
}
