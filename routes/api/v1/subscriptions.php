<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['auth:api', 'status', 'role:'.UserRole::PATIENT->value, PermissionMiddleware::class.':access patient care,api'])
    ->prefix('subscriptions')
    ->group(function () {
        Route::get('/packages', [SubscriptionController::class, 'packages']);
        Route::get('/', [SubscriptionController::class, 'index'])->middleware(PermissionMiddleware::class.':view appointments,api');
        Route::get('/current', [SubscriptionController::class, 'current'])->middleware(PermissionMiddleware::class.':view appointments,api');
        Route::post('/{subscription}/cancel', [SubscriptionController::class, 'cancel'])
            ->whereUuid('subscription')->middleware(['throttle:5,1', 'idempotent', PermissionMiddleware::class.':book appointments,api']);
        Route::post('/', [SubscriptionController::class, 'store'])
            ->middleware(['throttle:5,1', 'idempotent', PermissionMiddleware::class.':book appointments,api']);
    });
