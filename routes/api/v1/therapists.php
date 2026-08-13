<?php
// routes/api/v1/therapists.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\TherapistController;

/*
|--------------------------------------------------------------------------
| Therapist Routes (v1)
|--------------------------------------------------------------------------
*/

// Public therapist listing (للجميع)
Route::prefix('therapists')
    ->name('therapists.')
    ->group(function () {
        Route::get('/', [TherapistController::class, 'publicIndex'])->name('public.index');
        Route::get('/{id}', [TherapistController::class, 'publicShow'])->name('public.show');
    });
