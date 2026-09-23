<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'status'])->prefix('sessions')->group(function () {

    // Patient actions.
    Route::middleware('role:'.UserRole::PATIENT->value)->group(function () {
        Route::post('/book', [SessionController::class, 'book'])
            ->middleware(['throttle:10,1', 'idempotent']);
        Route::get('/', [SessionController::class, 'index']);
        Route::post('/{session}/proof', [SessionController::class, 'submitProof'])
            ->middleware(['throttle:10,1', 'idempotent']);
        Route::post('/{session}/reschedule', [SessionController::class, 'requestReschedule'])
            ->whereUuid('session')->middleware(['throttle:10,1', 'idempotent']);
    });

    // Attendance: the patient, or a supervisor/admin resolving a dispute.
    Route::post('/{session}/attendance', [SessionController::class, 'confirmAttendance'])
        ->whereUuid('session')
        ->middleware('role:'.implode(',', [
            UserRole::PATIENT->value, UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value, UserRole::CLINICAL_SUPERVISOR->value,
        ]));

    // Shared read/cancel for the session's participants.
    Route::get('/{session}', [SessionController::class, 'show'])->whereUuid('session');
    Route::post('/{session}/cancel', [SessionController::class, 'cancel'])->whereUuid('session');

    // Therapist actions (ownership enforced in the service; approved only).
    Route::middleware(['role:'.UserRole::THERAPIST->value, 'therapist.approved'])->group(function () {
        Route::post('/{session}/confirm', [SessionController::class, 'confirm'])->whereUuid('session');
        Route::post('/{session}/complete', [SessionController::class, 'complete'])->whereUuid('session');
        Route::post('/{session}/report', [SessionController::class, 'report'])->whereUuid('session');
        Route::post('/{session}/link', [SessionController::class, 'setLink'])->whereUuid('session');
        Route::post('/{session}/reschedule/decide', [SessionController::class, 'decideReschedule'])->whereUuid('session');
        Route::post('/{session}/cancel/decide', [SessionController::class, 'decideCancellation'])->whereUuid('session');
    });
});
