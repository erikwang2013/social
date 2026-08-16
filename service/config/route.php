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

Route::get('/health', [\app\controller\HealthController::class, 'index']);

// apidoc 静态 UI 无目录索引，重定向到 index.html（路由由 hg/apidoc 插件 route.php 自注册）
Route::get('/apidoc', fn() => redirect('/apidoc/index.html'));
Route::get('/apidoc/', fn() => redirect('/apidoc/index.html'));






