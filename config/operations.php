<?php

return [
    'outbox_lease_seconds' => 300,
    'heartbeat_prefix' => env('OPERATIONS_HEARTBEAT_PREFIX', 'sakina:ops'),
    'heartbeat_max_age_seconds' => (int) env('OPERATIONS_HEARTBEAT_MAX_AGE_SECONDS', 180),
    'require_heartbeats' => env('OPERATIONS_REQUIRE_HEARTBEATS', env('APP_ENV') === 'production'),
    'outbox_alert_age_seconds' => (int) env('OPERATIONS_OUTBOX_ALERT_AGE_SECONDS', 300),
    'queue_alert_size' => (int) env('OPERATIONS_QUEUE_ALERT_SIZE', 1000),
];
