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
use app\controller\LiveController;

Route::get('/health', [\app\controller\HealthController::class, 'index']);

// 首页（iframe 容器）：webman-framework v2.2.4 默认路由不再解析 / → 显式注册，否则根路径 404
Route::get('/', [\app\controller\IndexController::class, 'index']);

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
        Route::get('/wallet/balance', [app\controller\WalletController::class, 'balance']);
        Route::get('/wallet/transactions', [app\controller\WalletController::class, 'transactions']);
        Route::post('/wallet/withdraw', [app\controller\WalletController::class, 'withdraw']);
        Route::post('/wallet/withdraw/{id}/cancel', [app\controller\WalletController::class, 'cancelWithdrawal']);
        Route::get('/wallet/withdrawals', [app\controller\WalletController::class, 'withdrawals']);
        Route::post('/iap/recharge', [app\controller\RechargeController::class, 'recharge']);
        Route::post('/payment/order', [app\controller\PaymentController::class, 'order']);
        Route::get('/im/conversations', [app\controller\ImController::class, 'conversations']);
        Route::post('/im/conversations', [app\controller\ImController::class, 'create']);
        Route::get('/im/conversations/{id}/messages', [app\controller\ImController::class, 'messages']);
        Route::post('/im/device-token', [app\controller\ImController::class, 'deviceToken']);
        Route::post('/im/voice', [ImVoiceController::class, 'upload']);
        Route::post('/im/image', [\app\controller\ImageController::class, 'upload']);
        Route::get('/voice/calls', [VoiceController::class, 'calls']); // 静态路由先注册，避免被 /voice/{file} 吞掉
        Route::post('/voice/rooms', [VoiceController::class, 'createRoom']);
        Route::get('/voice/rooms', [VoiceController::class, 'rooms']);
        Route::get('/voice/rooms/{id}', [VoiceController::class, 'roomDetail']);
        Route::post('/voice/rooms/{id}/close', [VoiceController::class, 'closeRoom']);
        Route::get('/voice/{file}', [ImVoiceController::class, 'voiceFile']);
        Route::post('/live/rooms', [LiveController::class, 'create']);
        Route::get('/live/rooms', [LiveController::class, 'rooms']);
        Route::get('/live/rooms/{id}', [LiveController::class, 'detail']);
        Route::post('/live/rooms/{id}/close', [LiveController::class, 'close']);
        Route::post('/live/rooms/{id}/mic', [LiveController::class, 'micUp']);
        Route::delete('/live/rooms/{id}/mic', [LiveController::class, 'micDown']);
        Route::post('/live/rooms/{id}/gift', [app\controller\GiftController::class, 'send']);
        Route::get('/gifts', [app\controller\GiftController::class, 'catalog']);
    })->middleware(AuthMiddleware::class);

    // 支付回调：渠道服务器直达，无需认证（信任靠验签）
    Route::post('/payment/callback/{platform}', [app\controller\PaymentController::class, 'callback']);
});







