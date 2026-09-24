<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\MoodController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TherapistSwitchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'status', 'role:'.UserRole::PATIENT->value])
    ->prefix('patients')
    ->name('patients.')
    ->group(function () {
        Route::get('/profile', [PatientController::class, 'getProfile'])->name('profile');
        Route::put('/profile', [PatientController::class, 'updateProfile'])->name('profile.update');
        Route::post('/profile/update', [PatientController::class, 'updateProfile'])->name('profile.update.post');
        Route::get('/onboarding', [PatientController::class, 'getOnboarding'])->name('onboarding');
        Route::get('/dashboard', [PatientController::class, 'getDashboard'])->name('dashboard');
        Route::get('/progress', [PatientController::class, 'getProgress'])->name('progress');
        Route::get('/appointments', [PatientController::class, 'getAppointments'])->name('appointments');
        Route::get('/post-session/{sessionId}', [PatientController::class, 'getPostSession'])
            ->whereUuid('sessionId')->name('post-session');
        Route::get('/programs', [PatientController::class, 'getPrograms'])->name('programs');
        Route::get('/recommendations', [PatientController::class, 'getRecommendations'])->name('recommendations');
        Route::get('/modules/{module}', [PatientController::class, 'getModule'])
            ->whereUuid('module')->name('modules.show');
        Route::post('/modules/{module}/complete', [PatientController::class, 'completeModule'])
            ->whereUuid('module')->name('modules.complete');
        Route::put('/modules/{module}/homework', [PatientController::class, 'submitHomework'])
            ->whereUuid('module')->name('modules.homework');
        Route::delete('/account', [PatientController::class, 'deleteAccount'])->name('account.delete');
        Route::get('/export-data', [PatientController::class, 'exportData'])->middleware('throttle:export')->name('data.export');

        Route::post('/assessment', [AssessmentController::class, 'store'])
            ->middleware(['throttle:20,1', 'idempotent'])->name('assessment.store');
        Route::get('/assessment/history', [AssessmentController::class, 'history'])->name('assessment.history');

        Route::post('/mood', [MoodController::class, 'store'])->middleware('throttle:30,1')->name('mood.store');
        Route::get('/mood/chart', [MoodController::class, 'chart'])->name('mood.chart');
        Route::get('/mood/history', [MoodController::class, 'history'])->name('mood.history');

        Route::get('/emergency', [PatientController::class, 'getEmergency'])->name('emergency');
        Route::post('/emergency/alert', [PatientController::class, 'postEmergencyAlert'])
            ->middleware('throttle:5,1')->name('emergency.alert');

        Route::get('/content', [PatientController::class, 'getContent'])->name('content.index');

        Route::get('/support', [SupportController::class, 'index'])->name('support.index');
        Route::post('/support', [SupportController::class, 'store'])
            ->middleware('throttle:10,1')->name('support.store');
        Route::get('/support/{id}', [SupportController::class, 'show'])
            ->whereUuid('id')->name('support.show');
    });

// Short aliases used by the mobile client spec.
Route::middleware(['auth:api', 'status', 'role:'.UserRole::PATIENT->value])->group(function () {
    Route::post('/mood', [MoodController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/appointments', [PatientController::class, 'getAppointments']);
    Route::get('/dashboard', [PatientController::class, 'getDashboard']);
    Route::post('/therapist/switch', [TherapistSwitchController::class, 'store'])->middleware(['throttle:5,1', 'idempotent']);
    Route::get('/therapist/switch', [TherapistSwitchController::class, 'index']);
});
