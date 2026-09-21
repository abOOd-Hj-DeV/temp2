<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\ChatController;
use Illuminate\Support\Facades\Route;

// Patient <-> assigned therapist only; the pairing itself is enforced in ChatService.
Route::middleware(['auth:api', 'status', 'role:'.UserRole::PATIENT->value.','.UserRole::THERAPIST->value])
    ->prefix('chat')
    ->group(function () {
        Route::get('/', [ChatController::class, 'index'])->middleware('throttle:60,1');
        Route::get('/attachments/{message}', [ChatController::class, 'attachment'])
            ->whereUuid('message')->middleware('throttle:60,1');

        Route::get('/{userId}', [ChatController::class, 'show'])->whereUuid('userId')->middleware('throttle:120,1');
        Route::post('/{userId}', [ChatController::class, 'send'])
            ->whereUuid('userId')->middleware(['throttle:30,1', 'idempotent']);
        Route::post('/{userId}/read', [ChatController::class, 'markRead'])->whereUuid('userId')->middleware('throttle:60,1');
    });
