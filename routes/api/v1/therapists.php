<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\TherapistController;
use Illuminate\Support\Facades\Route;

// Patient-facing therapist browsing (any authenticated, verified user).
Route::middleware(['auth:api', 'status'])->prefix('therapists')->group(function () {
    Route::get('/', [TherapistController::class, 'index']);
    Route::get('/{id}/slots', [TherapistController::class, 'slots'])
        ->whereUuid('id');

    // Therapist self-service — keep /me/* before /{id} so it isn't swallowed.
    Route::middleware('role:'.UserRole::THERAPIST->value)->group(function () {
        Route::get('/me/dashboard', [TherapistController::class, 'dashboard']);
        Route::get('/me/sessions', [TherapistController::class, 'sessions']);
        Route::put('/me/settings', [TherapistController::class, 'updateSettings']);
        Route::post('/me/approval', [TherapistController::class, 'submitApproval']);
    });

    Route::get('/{id}', [TherapistController::class, 'show'])->whereUuid('id');
});
