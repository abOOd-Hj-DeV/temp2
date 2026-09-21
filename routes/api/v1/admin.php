<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\NotificationLogController;
use App\Http\Controllers\Api\V1\Admin\OverviewController;
use App\Http\Controllers\Api\V1\Admin\PackageController;
use App\Http\Controllers\Api\V1\Admin\PatientDirectoryController;
use App\Http\Controllers\Api\V1\Admin\PaymentReviewController;
use App\Http\Controllers\Api\V1\Admin\ProgramController;
use App\Http\Controllers\Api\V1\Admin\RedFlagController;
use App\Http\Controllers\Api\V1\Admin\TherapistApprovalController;
use App\Http\Controllers\Api\V1\Admin\TherapistSwitchReviewController;
use App\Http\Controllers\Api\V1\Admin\WithdrawalReviewController;
use Illuminate\Support\Facades\Route;

$staffRoles = implode(',', [
    UserRole::ADMIN->value,
    UserRole::SUPER_ADMIN->value,
]);

$clinicalRoles = implode(',', [
    UserRole::ADMIN->value,
    UserRole::SUPER_ADMIN->value,
    UserRole::CLINICAL_SUPERVISOR->value,
]);

$financeRoles = implode(',', [
    UserRole::ADMIN->value,
    UserRole::SUPER_ADMIN->value,
    UserRole::FINANCE_PARTNER->value,
]);

Route::middleware(['auth:api', 'status'])->prefix('admin')->group(function () use ($staffRoles, $clinicalRoles, $financeRoles) {

    Route::middleware("role:{$financeRoles}")->group(function () {
        Route::get('/payments', [PaymentReviewController::class, 'pending']);
        Route::post('/payments/{payment}/review', [PaymentReviewController::class, 'review'])
            ->whereUuid('payment')->middleware('idempotent');

        Route::get('/withdrawals', [WithdrawalReviewController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/review', [WithdrawalReviewController::class, 'review'])
            ->whereUuid('withdrawal')->middleware('idempotent');
    });

    Route::middleware("role:{$staffRoles}")->group(function () {
        Route::get('/overview', [OverviewController::class, 'index']);

        Route::get('/audit', [AuditLogController::class, 'index'])->middleware('throttle:30,1');
        Route::get('/notifications', [NotificationLogController::class, 'index']);

        Route::get('/therapists', [TherapistApprovalController::class, 'index']);
        Route::post('/therapists/{id}/approve', [TherapistApprovalController::class, 'approve'])
            ->whereUuid('id');
        Route::post('/therapists/{id}/reject', [TherapistApprovalController::class, 'reject'])
            ->whereUuid('id');
        Route::put('/therapists/{id}/clients-limit', [TherapistApprovalController::class, 'updateLimit'])
            ->whereUuid('id');

    });

    // Patient roster: staff plus the clinical supervisor who triages their risk.
    Route::middleware("role:{$clinicalRoles}")->group(function () {
        Route::get('/patients', [PatientDirectoryController::class, 'index']);
        Route::get('/patients/{id}', [PatientDirectoryController::class, 'show'])->whereUuid('id');
    });

    // Head Master (clinical_supervisor) owns the self-help programme library.
    Route::middleware("role:{$clinicalRoles}")->group(function () {
        Route::get('/programs', [ProgramController::class, 'index']);
        Route::post('/programs', [ProgramController::class, 'store'])->middleware('idempotent');
        Route::put('/programs/{program}', [ProgramController::class, 'update'])->whereUuid('program');
        Route::delete('/programs/{program}', [ProgramController::class, 'destroy'])->whereUuid('program');
        Route::post('/programs/{program}/modules', [ProgramController::class, 'storeModule'])
            ->whereUuid('program')->middleware('idempotent');
        Route::put('/programs/{program}/modules/{module}', [ProgramController::class, 'updateModule'])
            ->whereUuid(['program', 'module']);
        Route::delete('/programs/{program}/modules/{module}', [ProgramController::class, 'destroyModule'])
            ->whereUuid(['program', 'module']);
    });

    // Head Master (clinical_supervisor) owns the package catalogue.
    Route::middleware("role:{$clinicalRoles}")->group(function () {
        Route::get('/packages', [PackageController::class, 'index']);
        Route::post('/packages', [PackageController::class, 'store'])->middleware('idempotent');
        Route::put('/packages/{package}', [PackageController::class, 'update'])->whereUuid('package');
        Route::post('/packages/{package}/publish', [PackageController::class, 'publish'])->whereUuid('package');
        Route::post('/packages/{package}/unpublish', [PackageController::class, 'unpublish'])->whereUuid('package');
    });

    // Head Master (clinical_supervisor) gives the final word on therapist switches.
    Route::middleware("role:{$clinicalRoles}")->group(function () {
        Route::get('/therapist-switches', [TherapistSwitchReviewController::class, 'index']);
        Route::post('/therapist-switches/{switch}/review', [TherapistSwitchReviewController::class, 'review'])
            ->whereUuid('switch')->middleware('idempotent');
    });

    Route::middleware("role:{$clinicalRoles}")->group(function () {
        Route::get('/red-flags', [RedFlagController::class, 'index']);
        Route::get('/red-flags/{id}', [RedFlagController::class, 'show'])->whereUuid('id');
        Route::post('/red-flags/{id}/assign', [RedFlagController::class, 'assign'])->whereUuid('id');
        Route::post('/red-flags/{id}/status', [RedFlagController::class, 'updateStatus'])->whereUuid('id');
    });
});
