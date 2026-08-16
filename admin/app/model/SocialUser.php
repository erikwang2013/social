<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

class SocialUser extends Model
{
    // social_ 表无前缀，须用独立连接绕过 erik_ 前缀
    protected $connection = 'social';
    protected $table = 'social_users';
    public $timestamps = false;
    protected $hidden = ['password'];

    public function profile()
    {
        return $this->hasOne(SocialUserProfile::class, 'user_id');
    }
}
