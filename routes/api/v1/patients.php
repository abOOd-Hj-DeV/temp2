<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\PatientController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'status', 'role:'.UserRole::PATIENT->value])
    ->prefix('patients')
    ->name('patients.')
    ->group(function () {
        Route::get('/profile', [PatientController::class, 'getProfile'])->name('profile');
        Route::put('/profile', [PatientController::class, 'updateProfile'])->name('profile.update');
        Route::get('/onboarding', [PatientController::class, 'getOnboarding'])->name('onboarding');
        Route::get('/dashboard', [PatientController::class, 'getDashboard'])->name('dashboard');
        Route::get('/progress', [PatientController::class, 'getProgress'])->name('progress');
        Route::get('/appointments', [PatientController::class, 'getAppointments'])->name('appointments');
        Route::get('/programs', [PatientController::class, 'getPrograms'])->name('programs');
        Route::delete('/account', [PatientController::class, 'deleteAccount'])->name('account.delete');
        Route::get('/export-data', [PatientController::class, 'exportData'])->name('data.export');

        Route::post('/assessment', [AssessmentController::class, 'store'])
            ->middleware('throttle:20,1')->name('assessment.store');
        Route::get('/assessment/history', [AssessmentController::class, 'history'])->name('assessment.history');
    });
