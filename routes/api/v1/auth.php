<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\AuthThrottle;
use App\Http\Middleware\EnsureAuthCaptcha;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::get('/privacy-policy', [AuthController::class, 'privacyPolicy'])
        ->middleware(AuthThrottle::class.':60,1')->name('privacy_policy');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware([AuthThrottle::class.':5,1', EnsureAuthCaptcha::class])->name('register');

    Route::post('/otp/verify', [AuthController::class, 'verifyOTP'])
        ->middleware([AuthThrottle::class.':10,1', EnsureAuthCaptcha::class])->name('otp.verify');

    Route::post('/otp/resend', [AuthController::class, 'resendOTP'])
        ->middleware([AuthThrottle::class.':3,1', EnsureAuthCaptcha::class])->name('otp.resend');
    Route::post('/otp/send', [AuthController::class, 'resendOTP'])
        ->middleware([AuthThrottle::class.':3,1', EnsureAuthCaptcha::class])->name('otp.send');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware([AuthThrottle::class.':10,1', EnsureAuthCaptcha::class])->name('login');
    Route::post('/login/2fa', [AuthController::class, 'verifyLoginOtp'])
        ->middleware([AuthThrottle::class.':10,1', EnsureAuthCaptcha::class])->name('login.2fa');
    Route::post('/login/2fa/resend', [AuthController::class, 'resendLoginOtp'])
        ->middleware([AuthThrottle::class.':3,1', EnsureAuthCaptcha::class])->name('login.2fa.resend');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware([AuthThrottle::class.':3,1', EnsureAuthCaptcha::class])->name('password.forgot');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware([AuthThrottle::class.':5,1', EnsureAuthCaptcha::class])->name('password.reset');

    Route::post('/activate', [AuthController::class, 'activate'])
        ->middleware([AuthThrottle::class.':5,1', EnsureAuthCaptcha::class])->name('activate');

    Route::post('/status', [AuthController::class, 'checkStatus'])
        ->middleware(AuthThrottle::class.':20,1')->name('status.check');

    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->middleware(AuthThrottle::class.':10,1')->name('refresh');

    Route::middleware('auth:api')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->name('user');
        Route::put('/user/timezone', [AuthController::class, 'updateTimezone'])->name('user.timezone');
        Route::put('/user/password', [AuthController::class, 'changePassword'])
            ->middleware(AuthThrottle::class.':5,1')->name('user.password');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout.all');
    });
});
