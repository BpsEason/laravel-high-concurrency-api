<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ItemController;

// API 版本控制
Route::prefix('v1')->group(function () {

    // 認證相關路由
    Route::group(['prefix' => 'auth'], function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        // 需要認證的 Auth 路由
        Route::middleware('auth:api')->group(function () {
            Route::post('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });
    });

    // 商品相關路由
    Route::apiResource('items', ItemController::class);

    // 商品購買路由，需要認證
    Route::post('items/{item}/purchase', [ItemController::class, 'purchase'])
        ->middleware('auth:api')
        ->name('items.purchase');
});