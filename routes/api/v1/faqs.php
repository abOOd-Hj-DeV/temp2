<?php

use App\Http\Controllers\Api\V1\FaqController;
use Illuminate\Support\Facades\Route;

// Public read-only FAQ list for the app's support screen.
Route::get('/faqs', [FaqController::class, 'index'])
    ->middleware('throttle:60,1')->name('faqs.index');
