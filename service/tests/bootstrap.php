<?php
require __DIR__ . '/../vendor/autoload.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}

// 加载 webman 配置（含 config/plugin/*），供 config()/JwtHelper 使用；route.php 依赖运行中的路由表，排除
\Webman\Config::load(BASE_PATH . '/config', ['route']);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => 'social_',
]);
$capsule->setEventDispatcher(new Dispatcher(new Container));
$capsule->setAsGlobal();
$capsule->bootEloquent();

Capsule::schema()->create('users', function ($t) {
    $t->increments('id');
    $t->string('email', 190)->unique();
    $t->string('password');
    $t->tinyInteger('status')->default(1);
    $t->timestamps();
});
Capsule::schema()->create('user_profiles', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('user_id')->unique();
    $t->string('nickname', 64)->default('');
    $t->string('avatar', 255)->default('');
    $t->string('bio', 255)->default('');
    $t->tinyInteger('gender')->default(0);
    $t->date('birthday')->nullable();
    $t->timestamps();
});
Capsule::schema()->create('posts', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('user_id');
    $t->text('content');
    $t->unsignedInteger('like_count')->default(0);
    $t->unsignedInteger('comment_count')->default(0);
    $t->timestamps();
    $t->index('user_id');
    $t->index('created_at');
});
Capsule::schema()->create('comments', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('post_id');
    $t->unsignedBigInteger('user_id');
    $t->string('content', 500);
    $t->timestamps();
    $t->index('post_id');
});
Capsule::schema()->create('likes', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('post_id');
    $t->unsignedBigInteger('user_id');
    $t->timestamps();
    $t->unique(['post_id', 'user_id']);
});
Capsule::schema()->create('follows', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('follower_id');
    $t->unsignedBigInteger('followee_id');
    $t->timestamps();
    $t->unique(['follower_id', 'followee_id']);
});
Capsule::schema()->create('notifications', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('actor_id');
    $t->string('type', 32);
    $t->string('ref_type', 32)->default('');
    $t->unsignedBigInteger('ref_id')->default(0);
    $t->string('content', 500)->default('');
    $t->timestamp('read_at')->nullable();
    $t->timestamps();
    $t->index(['user_id', 'read_at']);
});

Capsule::schema()->create('conversations', function ($t) {
    $t->increments('id');
    $t->tinyInteger('type')->default(1);
    $t->string('name', 100)->default('');
    $t->unsignedBigInteger('owner_id')->default(0);
    $t->tinyInteger('status')->default(1);
    $t->timestamps();
});
Capsule::schema()->create('conversation_members', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('conversation_id');
    $t->unsignedBigInteger('user_id');
    $t->tinyInteger('role')->default(0);
    $t->tinyInteger('status')->default(1);
    $t->timestamps();
});
Capsule::schema()->create('messages', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('conversation_id');
    $t->unsignedBigInteger('sender_id');
    $t->string('client_msg_id', 64)->default('');
    $t->tinyInteger('type')->default(1);
    $t->text('content')->nullable();
    $t->string('image_url', 500)->default('');
    $t->string('voice_url', 500)->default('');
    $t->unsignedSmallInteger('voice_duration')->default(0);
    $t->tinyInteger('recall_status')->default(0);
    $t->timestamp('recall_at')->nullable();
    $t->timestamps();
    $t->unique('client_msg_id');
});
Capsule::schema()->create('message_reads', function ($t) {
    $t->unsignedBigInteger('conversation_id');
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('last_read_id')->default(0);
    $t->timestamp('updated_at')->nullable();
    $t->primary(['conversation_id', 'user_id']);
});
Capsule::schema()->create('device_tokens', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('user_id');
    $t->string('platform', 20);
    $t->string('token', 255)->default('');
    $t->timestamps();
    $t->unique(['user_id', 'platform']);
});
Capsule::schema()->create('call_records', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('caller_id');
    $t->unsignedBigInteger('callee_id');
    $t->tinyInteger('status')->default(1);
    $t->timestamp('started_at')->nullable();
    $t->timestamp('ended_at')->nullable();
    $t->timestamps();
    $t->index(['callee_id', 'id']);
});
Capsule::schema()->create('voice_rooms', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('owner_id');
    $t->string('name', 100);
    $t->tinyInteger('status')->default(1);
    $t->timestamps();
    $t->index(['status', 'updated_at']);
});
Capsule::schema()->create('voice_room_members', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('room_id');
    $t->unsignedBigInteger('user_id');
    $t->tinyInteger('role')->default(0);
    $t->timestamps();
    $t->unique(['room_id', 'user_id']);
});

// CLI 下 request() 返回 null，Post::getLikedAttribute 依赖 request()->uid，注入默认请求
\Webman\Context::set(\Webman\Http\Request::class, new \support\Request('GET / HTTP/1.1'));
