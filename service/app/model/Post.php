<?php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'posts';
    protected $fillable = ['user_id', 'content'];
    protected $appends = ['liked'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getLikedAttribute()
    {
        $uid = request()->uid ?? 0;
        return $uid ? Like::where('post_id', $this->id)->where('user_id', $uid)->exists() : false;
    }
}
