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
    Route::post('/otp/send', [AuthController::class, 'resendOTP'])
        ->middleware('throttle:3,1')->name('otp.send');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('login');
    Route::post('/login/2fa', [AuthController::class, 'verifyLoginOtp'])
        ->middleware('throttle:10,1')->name('login.2fa');
    Route::post('/login/2fa/resend', [AuthController::class, 'resendLoginOtp'])
        ->middleware('throttle:3,1')->name('login.2fa.resend');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:3,1')->name('password.forgot');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,1')->name('password.reset');

    Route::post('/activate', [AuthController::class, 'activate'])
        ->middleware('throttle:5,1')->name('activate');

    Route::post('/status', [AuthController::class, 'checkStatus'])
        ->middleware('throttle:20,1')->name('status.check');

    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:10,1')->name('refresh');

    Route::middleware('auth:api')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->name('user');
        Route::put('/user/timezone', [AuthController::class, 'updateTimezone'])->name('user.timezone');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout.all');
    });
});
