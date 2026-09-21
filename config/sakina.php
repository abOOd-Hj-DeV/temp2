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

    // Hours before the scheduled start after which a patient can no longer cancel.
    'cancellation_notice_hours' => env('SAKINA_CANCELLATION_NOTICE_HOURS', 12),

    // Hosts a therapist may use for the meeting link, keyed by session medium.
    'meeting_link_hosts' => [
        'zoom' => ['zoom.us'],
        'meet' => ['meet.google.com'],
        'whatsapp' => ['wa.me', 'chat.whatsapp.com', 'whatsapp.com'],
    ],

    // Platform share of each paid session; the remainder accrues to the therapist wallet.
    'currency' => env('SAKINA_CURRENCY', 'USD'),

    'platform_commission_rate' => env('SAKINA_PLATFORM_COMMISSION_RATE', 0.20),

    // Minimum wallet balance a therapist may request to withdraw.
    'min_withdrawal_amount' => env('SAKINA_MIN_WITHDRAWAL_AMOUNT', 20.00),

    // Consecutive low mood scores (<= mood_alert_threshold) that raise a red flag.
    'mood_alert_threshold' => env('SAKINA_MOOD_ALERT_THRESHOLD', 3),
    'mood_alert_streak' => env('SAKINA_MOOD_ALERT_STREAK', 3),

    // Minutes an open high-priority or unassigned red flag may wait before every
    // active clinical staff member is alerted.
    'red_flag_escalation_minutes' => (int) env('SAKINA_RED_FLAG_ESCALATION_MINUTES', 60),

    // An open red flag untouched for this long is superseded by a fresh one
    // (linked to it) instead of being merged into.
    'red_flag_stale_days' => (int) env('SAKINA_RED_FLAG_STALE_DAYS', 30),

    // Queue connection that persists retries when the default connection is
    // `sync` (which cannot retry): failed notifications and WhatsApp messages
    // are parked here for a worker instead of being lost or failing the request.
    'durable_queue_connection' => env('SAKINA_DURABLE_QUEUE_CONNECTION', 'database'),

    // Global api/* request ceiling per authenticated user (or IP) and the
    // hourly cap on full personal-data exports.
    'access_token_ttl_minutes' => (int) env('SAKINA_ACCESS_TOKEN_TTL_MINUTES', 120),
    'refresh_token_ttl_days' => (int) env('SAKINA_REFRESH_TOKEN_TTL_DAYS', 15),
    'therapist_switch_lock_hours' => (int) env('SAKINA_THERAPIST_SWITCH_LOCK_HOURS', 48),
    'payment_review_sla_hours' => (int) env('SAKINA_PAYMENT_REVIEW_SLA_HOURS', 12),
    'api_rate_limit_per_minute' => (int) env('SAKINA_API_RATE_LIMIT_PER_MINUTE', 120),
    'export_rate_limit_per_hour' => (int) env('SAKINA_EXPORT_RATE_LIMIT_PER_HOUR', 3),

    // Hard ceiling for ?per_page on every paginated endpoint.
    'max_per_page' => (int) env('SAKINA_MAX_PER_PAGE', 100),

    // Max bytes for non-multipart (JSON/form) request bodies. 0 disables.
    'max_json_body_bytes' => (int) env('SAKINA_MAX_JSON_BODY_BYTES', 262144),

    // Patient <-> assigned-therapist chat. Attachments are accepted only when the
    // sniffed MIME type is listed here; the size ceiling is per attachment kind (KB).
    'chat' => [
        'max_message_chars' => (int) env('SAKINA_CHAT_MAX_MESSAGE_CHARS', 4000),
        'attachment_mimes' => [
            'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'audio' => ['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm'],
            'file' => ['application/pdf', 'text/plain',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ],
        'attachment_max_kb' => [
            'image' => (int) env('SAKINA_CHAT_IMAGE_MAX_KB', 5120),
            'audio' => (int) env('SAKINA_CHAT_AUDIO_MAX_KB', 10240),
            'file' => (int) env('SAKINA_CHAT_FILE_MAX_KB', 10240),
        ],
    ],
];
