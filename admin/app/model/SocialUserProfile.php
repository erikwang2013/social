<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

class SocialUserProfile extends Model
{
    protected $table = 'social_user_profiles';
    public $timestamps = false;
}
