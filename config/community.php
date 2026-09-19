<?php

return [
    'enabled' => (bool) env('COMMUNITY_ENABLED', true),
    'whatsapp_enabled' => (bool) env('WHATSAPP_VERIFICATION_ENABLED', false),
    'kapso_key' => env('KAPSO_API_KEY', ''),
    'phone_number_id' => env('KAPSO_PHONE_NUMBER_ID', ''),
    'api_version' => env('KAPSO_API_VERSION', 'v24.0'),
    'template' => env('KAPSO_AUTH_TEMPLATE_NAME', 'incitta_verifica_whatsapp'),
    'android_template' => env('KAPSO_ANDROID_AUTH_TEMPLATE_NAME', ''),
    'language' => env('KAPSO_AUTH_TEMPLATE_LANGUAGE', 'it'),
    // Chiave dedicata, senza ripiego su APP_KEY: vuota, la verifica WhatsApp resta spenta.
    'phone_hash_key' => env('WHATSAPP_PHONE_HASH_KEY', ''),
    'code_minutes' => 5,
    'max_attempts' => 5,
    // Tetti per account: le richieste fatte da altri sullo stesso numero non li consumano.
    'hourly_send_limit' => 3,
    'daily_send_limit' => 5,
    // Account diversi dal richiedente che nelle ultime 24 ore hanno ricevuto un codice sullo
    // stesso numero: raggiunta la soglia il numero non accetta un account in più. Con 3 un
    // numero riceve i codici di al massimo tre account, ciascuno entro il proprio tetto.
    'number_foreign_daily_limit' => 3,
    'global_daily_send_limit' => (int) env('WHATSAPP_DAILY_SEND_LIMIT', 500),
];
