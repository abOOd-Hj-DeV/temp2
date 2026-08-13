<?php
// routes/api/v1/auth.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;

/*
|--------------------------------------------------------------------------
| Authentication Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')
    ->name('auth.')
    ->group(function () {

        // Public endpoints
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/otp/verify', [AuthController::class, 'verifyOTP'])->name('otp.verify');
        Route::post('/otp/resend', [AuthController::class, 'resendOTP'])->name('otp.resend');
        Route::post('/login', [AuthController::class, 'login'])->name('login');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('/status', [AuthController::class, 'checkStatus'])->name('status.check');

        // Protected endpoints
        Route::middleware(['auth:api'])->group(function () {
            Route::get('/user', [AuthController::class, 'user'])->name('user');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });
