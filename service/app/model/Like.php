<?php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    protected $table = 'likes';
    protected $fillable = ['post_id', 'user_id'];
}
