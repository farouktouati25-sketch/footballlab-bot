<?php

return [
    // "meta" (direct Cloud API) or "360dialog" (BSP; needed for coexistence onboarding).
    'provider' => env('WHATSAPP_PROVIDER', 'meta'),

    // 360dialog: the webhook carries this shared secret in an X-Webhook-Secret header
    // (360dialog doesn't sign webhooks the way Meta does).
    'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET'),

    // Permanent System User token with whatsapp_business_messaging + whatsapp_business_management.
    'token' => env('WHATSAPP_TOKEN'),

    // Phone number ID of +971 50 374 1554 (NOT the phone number itself).
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    // Meta app secret, used to verify X-Hub-Signature-256 on webhooks.
    'app_secret' => env('WHATSAPP_APP_SECRET'),

    // Any random string; paste the same value in the Meta webhook settings.
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),

    'api_base' => env('WHATSAPP_API_BASE', env('WHATSAPP_PROVIDER') === '360dialog'
        ? 'https://waba-v2.360dialog.io'
        : 'https://graph.facebook.com'),

    // Admins who approve bookings. wa_id format: country code + number, no "+" or spaces.
    // e.g. ADMIN_NUMBERS="971501234567,971559876543" and ADMIN_NAMES="Farouk,Partner"
    'admins' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_NUMBERS', ''))))),
    'admin_names' => array_values(array_map('trim', explode(',', (string) env('ADMIN_NAMES', '')))),

    // Meta-approved templates, used when the 24h customer-service window is closed.
    'templates' => [
        'language' => env('WHATSAPP_TEMPLATE_LANG', 'en'),
        'admin_approval' => env('TEMPLATE_ADMIN_APPROVAL', 'booking_approval_request'),
        'admin_alert' => env('TEMPLATE_ADMIN_ALERT', 'admin_alert'),
        'client_update' => env('TEMPLATE_CLIENT_UPDATE', 'booking_update'),
        'waitlist_opened' => env('TEMPLATE_WAITLIST_OPENED', 'waitlist_game_opened'),
    ],

    // Safety margin: treat the 24h window as closed a bit early.
    'window_minutes' => (int) env('WHATSAPP_WINDOW_MINUTES', 1420),
];
