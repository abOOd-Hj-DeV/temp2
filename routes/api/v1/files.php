<?php

use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'status'])->group(function () {
    Route::prefix('files')->group(function () {
        Route::post('/upload', [FileController::class, 'upload'])->middleware('throttle:20,1');
        Route::match(['get', 'post'], '/download/{path}', [FileController::class, 'download'])
            ->where('path', '.*')->middleware('throttle:60,1');
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id');
    });
});
