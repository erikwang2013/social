<?php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $table = 'user_profiles';
    protected $fillable = ['user_id', 'nickname', 'avatar', 'bio', 'gender', 'birthday'];
}
