<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\ParallelLayerController;
use App\Http\Controllers\Api\V1\TherapistContentController;
use App\Http\Controllers\Api\V1\TherapistController;
use App\Http\Controllers\Api\V1\TherapistModuleController;
use App\Http\Controllers\Api\V1\TherapistReviewController;
use App\Http\Controllers\Api\V1\TherapistSwitchController;
use Illuminate\Support\Facades\Route;

// Patient-facing therapist browsing (any authenticated, verified user).
Route::middleware(['auth:api', 'status'])->prefix('therapists')->group(function () {
    Route::get('/', [TherapistController::class, 'index']);
    Route::get('/{id}/slots', [TherapistController::class, 'slots'])
        ->whereUuid('id');
    Route::get('/{id}/reviews', [TherapistReviewController::class, 'index'])->whereUuid('id');

    Route::middleware('role:'.UserRole::PATIENT->value)->group(function () {
        Route::post('/{id}/reviews', [TherapistReviewController::class, 'store'])
            ->whereUuid('id')->middleware(['throttle:10,1', 'idempotent']);
        Route::put('/{id}/reviews/mine', [TherapistReviewController::class, 'update'])
            ->whereUuid('id')->middleware('throttle:10,1');
        Route::get('/{id}/reviews/mine', [TherapistReviewController::class, 'mine'])->whereUuid('id');
    });

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

            Route::get('/content', [TherapistContentController::class, 'index']);
            Route::post('/content', [TherapistContentController::class, 'store'])->middleware('idempotent');
            Route::get('/content/{id}', [TherapistContentController::class, 'show'])->whereUuid('id');
            Route::put('/content/{id}', [TherapistContentController::class, 'update'])->whereUuid('id');
            Route::delete('/content/{id}', [TherapistContentController::class, 'destroy'])->whereUuid('id');

            Route::get('/clients/{id}/modules', [TherapistModuleController::class, 'progress'])->whereUuid('id');
            Route::post('/clients/{id}/modules/{module}/hide', [TherapistModuleController::class, 'hide'])
                ->whereUuid(['id', 'module']);
            Route::delete('/clients/{id}/modules/{module}/hide', [TherapistModuleController::class, 'unhide'])
                ->whereUuid(['id', 'module']);

            Route::get('/clients/{id}/parallel', [ParallelLayerController::class, 'show'])->whereUuid('id');
            Route::post('/clients/{id}/parallel', [ParallelLayerController::class, 'store'])
                ->whereUuid('id')->middleware('idempotent');

            Route::get('/wallet', [TherapistController::class, 'wallet']);
            Route::post('/wallet/withdraw', [TherapistController::class, 'withdraw'])->middleware(['throttle:5,1', 'idempotent']);
            Route::get('/reports', [TherapistController::class, 'reports']);
            Route::get('/me/reviews', [TherapistReviewController::class, 'own']);
            // Step 1 of a patient's therapist switch: the requested therapist answers.
            Route::get('/me/switch-requests', [TherapistSwitchController::class, 'incoming']);
            Route::post('/me/switch-requests/{switch}/decide', [TherapistSwitchController::class, 'therapistDecide'])
                ->whereUuid('switch')->middleware('idempotent');
        });
    });

    Route::get('/{id}', [TherapistController::class, 'show'])->whereUuid('id');
});

// Short aliases used by the mobile client spec.
Route::middleware(['auth:api', 'status', 'role:'.UserRole::THERAPIST->value, 'therapist.approved'])->group(function () {
    Route::get('/clients', [TherapistController::class, 'clients']);
    Route::get('/clients/{id}', [TherapistController::class, 'client'])->whereUuid('id');
    Route::get('/wallet', [TherapistController::class, 'wallet']);
    Route::post('/wallet/withdraw', [TherapistController::class, 'withdraw'])->middleware(['throttle:5,1', 'idempotent']);
    Route::get('/reports', [TherapistController::class, 'reports']);
});
