<?php

return [
    'captcha' => [
        // Production always requires a configured server-side verifier.
        'enabled' => env('AUTH_CAPTCHA_ENABLED', false),
        'verifier' => env('AUTH_CAPTCHA_VERIFIER'),
    ],
];
