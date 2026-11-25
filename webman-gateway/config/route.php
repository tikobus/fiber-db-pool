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

Route::group('/api', function () {
    Route::get('/user', [app\controller\UserController::class, 'get']);
    Route::get('/users', [app\controller\UserController::class, 'list']);
    Route::post('/user', [app\controller\UserController::class, 'create']);
    Route::get('/health', [app\controller\UserController::class, 'health']);
    Route::get('/stats', [app\controller\StatsController::class, 'index']);
});


