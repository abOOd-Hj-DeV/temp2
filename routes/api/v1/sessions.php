<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'status'])->prefix('sessions')->group(function () {

    // Patient actions.
    Route::middleware('role:'.UserRole::PATIENT->value)->group(function () {
        Route::post('/book', [SessionController::class, 'book'])
            ->middleware('throttle:10,1');
        Route::get('/', [SessionController::class, 'index']);
        Route::post('/{session}/proof', [SessionController::class, 'submitProof'])
            ->middleware('throttle:10,1');
    });

    // Shared read/cancel for the session's participants.
    Route::get('/{session}', [SessionController::class, 'show'])->whereUuid('session');
    Route::post('/{session}/cancel', [SessionController::class, 'cancel'])->whereUuid('session');

    // Therapist actions (ownership enforced in the service).
    Route::middleware('role:'.UserRole::THERAPIST->value)->group(function () {
        Route::post('/{session}/confirm', [SessionController::class, 'confirm'])->whereUuid('session');
        Route::post('/{session}/complete', [SessionController::class, 'complete'])->whereUuid('session');
        Route::post('/{session}/link', [SessionController::class, 'setLink'])->whereUuid('session');
    });
});
