<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Admin\PaymentReviewController;
use App\Http\Controllers\Api\V1\Admin\TherapistApprovalController;
use Illuminate\Support\Facades\Route;

$staffRoles = implode(',', [
    UserRole::ADMIN->value,
    UserRole::SUPER_ADMIN->value,
]);

$financeRoles = implode(',', [
    UserRole::ADMIN->value,
    UserRole::SUPER_ADMIN->value,
    UserRole::FINANCE_PARTNER->value,
]);

Route::middleware(['auth:api', 'status'])->prefix('admin')->group(function () use ($staffRoles, $financeRoles) {

    Route::middleware("role:{$financeRoles}")->group(function () {
        Route::get('/payments', [PaymentReviewController::class, 'pending']);
        Route::post('/payments/{payment}/review', [PaymentReviewController::class, 'review'])
            ->whereUuid('payment');
    });

    Route::middleware("role:{$staffRoles}")->group(function () {
        Route::get('/therapists', [TherapistApprovalController::class, 'index']);
        Route::post('/therapists/{id}/approve', [TherapistApprovalController::class, 'approve'])
            ->whereUuid('id');
        Route::post('/therapists/{id}/reject', [TherapistApprovalController::class, 'reject'])
            ->whereUuid('id');
    });
});
