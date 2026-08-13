<?php
// routes/api.php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All API routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group.
|
*/

// =========================================================================
// API Version 1
// =========================================================================
Route::prefix('v1')->group(function () {

    // Load all v1 route files
    require __DIR__ . '/api/v1/auth.php';
    require __DIR__ . '/api/v1/patients.php';
    require __DIR__ . '/api/v1/therapists.php';

    // سيتم إضافة المزيد لاحقاً
    // require __DIR__ . '/api/v1/sessions.php';
    // require __DIR__ . '/api/v1/assessments.php';
    // require __DIR__ . '/api/v1/admin.php';
});

// =========================================================================
// Test Route (للتحقق من عمل الـ API)
// =========================================================================
Route::get('/test-api', function () {
    return response()->json([
        'message' => 'API is working!',
        'version' => '1.0',
        'timestamp' => now()->toDateTimeString(),
    ]);
})->name('api.test');
