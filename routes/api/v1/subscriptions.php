<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'status', 'role:'.UserRole::PATIENT->value])
    ->prefix('subscriptions')
    ->group(function () {
        Route::get('/packages', [SubscriptionController::class, 'packages']);
        Route::get('/', [SubscriptionController::class, 'index']);
        Route::get('/current', [SubscriptionController::class, 'current']);
        Route::post('/{subscription}/cancel', [SubscriptionController::class, 'cancel'])
            ->whereUuid('subscription')->middleware(['throttle:5,1', 'idempotent']);
        Route::post('/', [SubscriptionController::class, 'store'])
            ->middleware(['throttle:5,1', 'idempotent']);
    });
