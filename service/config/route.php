<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;
use app\middleware\AuthMiddleware;
use app\controller\ImVoiceController;
use app\controller\VoiceController;

Route::get('/health', [\app\controller\HealthController::class, 'index']);

// apidoc 静态 UI 无目录索引，重定向到 index.html（路由由 hg/apidoc 插件 route.php 自注册）
Route::get('/apidoc', fn() => redirect('/apidoc/index.html'));
Route::get('/apidoc/', fn() => redirect('/apidoc/index.html'));

Route::group('/api/v1', function () {
    Route::post('/auth/register', [app\controller\AuthController::class, 'register']);
    Route::post('/auth/login', [app\controller\AuthController::class, 'login']);
    Route::post('/auth/refresh', [app\controller\AuthController::class, 'refresh']);

    Route::group('', function () {
        Route::post('/auth/logout', [app\controller\AuthController::class, 'logout']);
        Route::get('/auth/me', [app\controller\AuthController::class, 'me']);
        Route::get('/me', [app\controller\MeController::class, 'index']);
        Route::put('/me', [app\controller\MeController::class, 'update']);
        Route::post('/users/{id}/follow', [app\controller\FollowController::class, 'follow']);
        Route::post('/users/{id}/unfollow', [app\controller\FollowController::class, 'unfollow']);
        Route::get('/users/{id}/following', [app\controller\FollowController::class, 'following']);
        Route::get('/users/{id}/followers', [app\controller\FollowController::class, 'followers']);
        Route::get('/users/{id}/relation', [app\controller\FollowController::class, 'relation']);
        Route::post('/posts', [app\controller\PostController::class, 'create']);
        Route::get('/posts', [app\controller\PostController::class, 'timeline']);
        Route::get('/posts/{id}', [app\controller\PostController::class, 'detail']);
        Route::post('/posts/{id}/like', [app\controller\PostController::class, 'like']);
        Route::post('/posts/{id}/unlike', [app\controller\PostController::class, 'unlike']);
        Route::get('/posts/{id}/comments', [app\controller\CommentController::class, 'index']);
        Route::post('/posts/{id}/comments', [app\controller\CommentController::class, 'create']);
        Route::get('/notifications', [app\controller\NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [app\controller\NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [app\controller\NotificationController::class, 'read']);
        Route::post('/notifications/read-all', [app\controller\NotificationController::class, 'readAll']);
        Route::get('/search/posts', [app\controller\SearchController::class, 'posts']);
        Route::get('/search/users', [app\controller\SearchController::class, 'users']);
        Route::get('/im/conversations', [app\controller\ImController::class, 'conversations']);
        Route::post('/im/conversations', [app\controller\ImController::class, 'create']);
        Route::get('/im/conversations/{id}/messages', [app\controller\ImController::class, 'messages']);
        Route::post('/im/device-token', [app\controller\ImController::class, 'deviceToken']);
        Route::post('/im/voice', [ImVoiceController::class, 'upload']);
        Route::get('/voice/calls', [VoiceController::class, 'calls']); // 静态路由先注册，避免被 /voice/{file} 吞掉
        Route::get('/voice/{file}', [ImVoiceController::class, 'voiceFile']);
    })->middleware(AuthMiddleware::class);
});







