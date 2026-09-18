<?php

return [
    'enabled' => (bool) env('COMMUNITY_ENABLED', true),
    'whatsapp_enabled' => (bool) env('WHATSAPP_VERIFICATION_ENABLED', false),
    'kapso_key' => env('KAPSO_API_KEY', ''),
    'phone_number_id' => env('KAPSO_PHONE_NUMBER_ID', ''),
    'api_version' => env('KAPSO_API_VERSION', 'v24.0'),
    'template' => env('KAPSO_AUTH_TEMPLATE_NAME', 'incitta_verifica_whatsapp'),
    'language' => env('KAPSO_AUTH_TEMPLATE_LANGUAGE', 'it'),
    'phone_hash_key' => env('WHATSAPP_PHONE_HASH_KEY', env('APP_KEY', '')),
    'code_minutes' => 5,
    'max_attempts' => 5,
    'daily_send_limit' => 5,
    'global_daily_send_limit' => (int) env('WHATSAPP_DAILY_SEND_LIMIT', 500),
];
