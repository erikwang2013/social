<?php
namespace app\common;

use app\model\User;

class UserBrief
{
    public static function of(User $user): array
    {
        $profile = $user->profile;
        return [
            'id' => $user->id,
            'nickname' => $profile->nickname ?? '',
            'avatar' => $profile->avatar ?? '',
            'bio' => $profile->bio ?? '',
            'gender' => $profile->gender ?? 0,
        ];
    }
}
