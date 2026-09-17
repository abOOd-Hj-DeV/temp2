<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')->name('register');

    Route::post('/otp/verify', [AuthController::class, 'verifyOTP'])
        ->middleware('throttle:10,1')->name('otp.verify');

    Route::post('/otp/resend', [AuthController::class, 'resendOTP'])
        ->middleware('throttle:3,1')->name('otp.resend');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('login');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:3,1')->name('password.forgot');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,1')->name('password.reset');

    Route::post('/status', [AuthController::class, 'checkStatus'])
        ->middleware('throttle:20,1')->name('status.check');

    Route::middleware('auth:api')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->name('user');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});
