<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\FaqManagementController;
use App\Http\Controllers\Api\V1\Admin\NotificationLogController;
use App\Http\Controllers\Api\V1\Admin\OverviewController;
use App\Http\Controllers\Api\V1\Admin\PackageController;
use App\Http\Controllers\Api\V1\Admin\PatientDirectoryController;
use App\Http\Controllers\Api\V1\Admin\PaymentReviewController;
use App\Http\Controllers\Api\V1\Admin\ProgramController;
use App\Http\Controllers\Api\V1\Admin\RedFlagController;
use App\Http\Controllers\Api\V1\Admin\SessionManagementController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionManagementController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketController;
use App\Http\Controllers\Api\V1\Admin\TherapistApprovalController;
use App\Http\Controllers\Api\V1\Admin\TherapistSwitchReviewController;
use App\Http\Controllers\Api\V1\Admin\UserAccountController;
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

$supportRoles = implode(',', [
    UserRole::ADMIN->value,
    UserRole::SUPER_ADMIN->value,
    UserRole::CLINICAL_SUPERVISOR->value,
    UserRole::SUPPORT_AGENT->value,
]);

$contentRoles = implode(',', [
    UserRole::ADMIN->value,
    UserRole::SUPER_ADMIN->value,
    UserRole::CONTENT_MANAGER->value,
]);

Route::middleware(['auth:api', 'status'])->prefix('admin')->group(function () use ($staffRoles, $clinicalRoles, $financeRoles, $supportRoles, $contentRoles) {

    Route::middleware("role:{$financeRoles}")->group(function () {
        Route::get('/payments', [PaymentReviewController::class, 'pending']);
        Route::post('/payments/{payment}/review', [PaymentReviewController::class, 'review'])
            ->whereUuid('payment')->middleware('idempotent');

        Route::post('/subscriptions/{subscription}/cancel', [SubscriptionManagementController::class, 'cancel'])
            ->whereUuid('subscription')->middleware('idempotent');

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

    // Staff/therapist accounts: the clinical supervisor may only create and
    // manage therapists; the per-role matrix is enforced in StaffAccountService.
    Route::middleware("role:{$clinicalRoles}")->group(function () {
        Route::get('/users', [UserAccountController::class, 'index']);
        Route::post('/users', [UserAccountController::class, 'store'])->middleware(['idempotent', 'throttle:20,1']);
        Route::post('/users/{user}/invitation/resend', [UserAccountController::class, 'resendInvitation'])
            ->whereUuid('user')->middleware('throttle:10,1');
        Route::patch('/users/{user}/active', [UserAccountController::class, 'setActive'])->whereUuid('user');
    });

    // Patient roster and session oversight: staff plus the clinical supervisor.
    Route::middleware("role:{$clinicalRoles}")->group(function () {
        Route::get('/patients', [PatientDirectoryController::class, 'index']);
        Route::get('/patients/{id}', [PatientDirectoryController::class, 'show'])->whereUuid('id');
        Route::post('/sessions/{session}/cancel', [SessionManagementController::class, 'cancel'])
            ->whereUuid('session')->middleware('idempotent');
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

    // Support tickets: support agents and clinical staff triage.
    Route::middleware("role:{$supportRoles}")->group(function () {
        Route::get('/support', [SupportTicketController::class, 'index']);
        Route::get('/support/{id}', [SupportTicketController::class, 'show'])->whereUuid('id');
        Route::post('/support/{id}/assign', [SupportTicketController::class, 'assign'])
            ->whereUuid('id')->middleware('idempotent');
        Route::post('/support/{id}/status', [SupportTicketController::class, 'setStatus'])->whereUuid('id');
    });

    // FAQ management: the content manager owns the FAQ library.
    Route::middleware("role:{$contentRoles}")->group(function () {
        Route::get('/faqs', [FaqManagementController::class, 'index']);
        Route::post('/faqs', [FaqManagementController::class, 'store'])->middleware('idempotent');
        Route::put('/faqs/{id}', [FaqManagementController::class, 'update'])->whereUuid('id');
        Route::delete('/faqs/{id}', [FaqManagementController::class, 'destroy'])->whereUuid('id');
    });
});
