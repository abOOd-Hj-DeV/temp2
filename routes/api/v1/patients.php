<?php
// routes/api/v1/patients.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\AssessmentController;
use App\Enums\AssessmentType;
use App\Enums\UserRole;

/*
|--------------------------------------------------------------------------
| Patient Routes (v1)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'role:' . UserRole::PATIENT->value])
    ->prefix('patients')
    ->name('patients.')
    ->group(function () {

        // 🔵 Profile Management
        Route::get('/profile', [PatientController::class, 'getProfile'])->name('profile');
        Route::put('/profile', [PatientController::class, 'updateProfile'])->name('profile.update');

        // 🔵 Dashboard
        Route::get('/dashboard', [PatientController::class, 'getDashboard'])->name('dashboard');

        // 🔵 Onboarding
        Route::get('/onboarding', [PatientController::class, 'getOnboarding'])->name('onboarding');

        // 🔵 Account Management
        Route::delete('/account', [PatientController::class, 'deleteAccount'])->name('account.delete');
        Route::get('/export-data', [PatientController::class, 'exportData'])->name('data.export');

        // 🔵 Progress Tracking
        Route::get('/progress', [PatientController::class, 'getProgress'])->name('progress');

        // 🔵 Assessments
        Route::post('/assessment', [AssessmentController::class, 'store']); // إنشاء تقييم
        Route::get('/assessment/history', [AssessmentController::class, 'history']); // السجل

        Route::get('/appointments', [PatientController::class, 'getAppointments'])->name('appointments');

        // 🔵 البرامج (الجديدة)
        Route::get('/programs', [PatientController::class, 'getPrograms'])->name('programs');



    });
