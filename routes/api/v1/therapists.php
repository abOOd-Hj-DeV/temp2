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
        // Onboarding: allowed before approval.
        Route::put('/me/settings', [TherapistController::class, 'updateSettings']);
        Route::post('/settings', [TherapistController::class, 'updateSettings']);
        Route::post('/me/approval', [TherapistController::class, 'submitApproval'])->middleware('throttle:5,1');
        Route::post('/approval', [TherapistController::class, 'submitApproval'])->middleware('throttle:5,1');

        // Operational: approved therapists only.
        Route::middleware('therapist.approved')->group(function () {
            Route::get('/me/dashboard', [TherapistController::class, 'dashboard']);
            Route::get('/dashboard', [TherapistController::class, 'dashboard']);
            Route::get('/me/sessions', [TherapistController::class, 'sessions']);

            Route::get('/clients', [TherapistController::class, 'clients']);
            Route::get('/clients/{id}', [TherapistController::class, 'client'])->whereUuid('id');
            Route::get('/clients/{id}/notes', [TherapistController::class, 'clientNotes'])->whereUuid('id');
            Route::post('/clients/{id}/notes', [TherapistController::class, 'addClientNote'])->whereUuid('id');

            Route::get('/wallet', [TherapistController::class, 'wallet']);
            Route::post('/wallet/withdraw', [TherapistController::class, 'withdraw'])->middleware('throttle:5,1');
            Route::get('/reports', [TherapistController::class, 'reports']);
        });
    });

    Route::get('/{id}', [TherapistController::class, 'show'])->whereUuid('id');
});

// Short aliases used by the mobile client spec.
Route::middleware(['auth:api', 'status', 'role:'.UserRole::THERAPIST->value, 'therapist.approved'])->group(function () {
    Route::get('/clients', [TherapistController::class, 'clients']);
    Route::get('/clients/{id}', [TherapistController::class, 'client'])->whereUuid('id');
    Route::get('/wallet', [TherapistController::class, 'wallet']);
    Route::post('/wallet/withdraw', [TherapistController::class, 'withdraw'])->middleware('throttle:5,1');
    Route::get('/reports', [TherapistController::class, 'reports']);
});
