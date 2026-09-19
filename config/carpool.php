<?php

return [
    'enabled' => env('CARPOOL_ENABLED', true),
    'new_rides' => env('CARPOOL_NEW_RIDES', true),
    'chat_enabled' => env('CARPOOL_CHAT_ENABLED', true),
    'terms_version' => '2026-09-18',
    'max_seats' => 8,
    'max_pending' => 3,
    'ip_retention_days' => 90,
    'message_retention_days' => 90,
    'history_retention_days' => 365,
    'chat_hours' => 24,
];
