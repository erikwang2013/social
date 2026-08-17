<?php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Follow extends Model
{
    protected $fillable = ['follower_id', 'followee_id'];

    public function followee()
    {
        return $this->belongsTo(User::class, 'followee_id');
    }

    public function follower()
    {
        return $this->belongsTo(User::class, 'follower_id');
    }
}
