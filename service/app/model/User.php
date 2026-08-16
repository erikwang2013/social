<?php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
    protected $fillable = ['email', 'password', 'status'];
    protected $hidden = ['password'];
}
