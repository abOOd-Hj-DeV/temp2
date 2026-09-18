<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/api/v1/auth.php';
    require __DIR__.'/api/v1/patients.php';
    require __DIR__.'/api/v1/therapists.php';
    require __DIR__.'/api/v1/sessions.php';
    require __DIR__.'/api/v1/subscriptions.php';
    require __DIR__.'/api/v1/admin.php';
    require __DIR__.'/api/v1/files.php';
});
