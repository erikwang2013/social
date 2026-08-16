<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

class SocialUser extends Model
{
    protected $table = 'social_users';
    public $timestamps = false;

    public function profile()
    {
        return $this->hasOne(SocialUserProfile::class, 'user_id');
    }
}
