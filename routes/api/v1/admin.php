<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Admin\PaymentReviewController;
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
            ->whereUuid('payment');

        Route::get('/withdrawals', [WithdrawalReviewController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/review', [WithdrawalReviewController::class, 'review'])
            ->whereUuid('withdrawal');
    });

    Route::middleware("role:{$staffRoles}")->group(function () {
        Route::get('/therapists', [TherapistApprovalController::class, 'index']);
        Route::post('/therapists/{id}/approve', [TherapistApprovalController::class, 'approve'])
            ->whereUuid('id');
        Route::post('/therapists/{id}/reject', [TherapistApprovalController::class, 'reject'])
            ->whereUuid('id');
        Route::put('/therapists/{id}/clients-limit', [TherapistApprovalController::class, 'updateLimit'])
            ->whereUuid('id');

        Route::get('/therapist-switches', [TherapistSwitchReviewController::class, 'index']);
        Route::post('/therapist-switches/{switch}/review', [TherapistSwitchReviewController::class, 'review'])
            ->whereUuid('switch');
    });

    Route::middleware("role:{$clinicalRoles}")->group(function () {
        Route::get('/red-flags', [RedFlagController::class, 'index']);
        Route::get('/red-flags/{id}', [RedFlagController::class, 'show'])->whereUuid('id');
        Route::post('/red-flags/{id}/assign', [RedFlagController::class, 'assign'])->whereUuid('id');
        Route::post('/red-flags/{id}/status', [RedFlagController::class, 'updateStatus'])->whereUuid('id');
    });
});
