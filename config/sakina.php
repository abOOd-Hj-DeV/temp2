<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sakina Platform Settings
    |--------------------------------------------------------------------------
    */

    // Default price charged for a non-free session.
    'session_price' => env('SAKINA_SESSION_PRICE', 50.00),

    // Length of a booked session in minutes.
    'session_duration_minutes' => env('SAKINA_SESSION_DURATION_MINUTES', 60),

    // Subscription catalogue — price keyed by SubscriptionType value.
    'subscription_prices' => [
        '4_weeks' => env('SAKINA_SUB_4W_PRICE', 150.00),
        '8_weeks' => env('SAKINA_SUB_8W_PRICE', 260.00),
    ],

    // Filesystem disk used for payment proofs and therapist license files.
    // 's3' in production, 'local' for development.
    'uploads_disk' => env('UPLOADS_DISK', 'local'),

];
