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

// CLI 下 request() 返回 null，Post::getLikedAttribute 依赖 request()->uid，注入默认请求
\Webman\Context::set(\Webman\Http\Request::class, new \support\Request('GET / HTTP/1.1'));
