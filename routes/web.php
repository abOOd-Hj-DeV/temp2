<?php

use App\Services\Notifications\OperationsHealth;
use Illuminate\Support\Facades\Route;

Route::get('/ready', function (OperationsHealth $health) {
    $ready = $health->ready();

    return response()->json(['status' => $ready ? 'ready' : 'unavailable'], $ready ? 200 : 503)
        ->header('Cache-Control', 'no-store');
});

Route::get('/', function () {
    return view('welcome');
});
