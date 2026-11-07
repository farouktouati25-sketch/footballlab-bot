<?php

return [
    'brand' => 'FootballLab DXB',
    'timezone' => env('FBL_TIMEZONE', 'Asia/Dubai'),
    'currency' => 'AED',

    // Business facts the AI is allowed to state. Edit here, not in the prompt.
    'facts' => [
        'price_from' => (int) env('FBL_PRICE_FROM', 50),
        'venues' => array_values(array_filter(array_map('trim', explode('|', (string) env(
            'FBL_VENUES',
            'Dubai Schools Al Barsha|Al Safa Sports Ground, Nad Al Sheba'
        ))))),
        'group_link' => env('FBL_GROUP_LINK', 'https://chat.whatsapp.com/BIJG7gpzJWIDLD6UsYZPHR'),
        'membership' => 'No membership, no commitment: players book only the games they want.',
        'extra' => env('FBL_EXTRA_FACTS', ''),

        // Optional prepaid credit packs. The bot only explains them; buying is handled by a human.
        'credit_packs' => [
            ['name' => 'Starter', 'price' => 265, 'credits' => 6, 'weeks' => 4, 'per_credit' => 44],
            ['name' => 'Pro', 'price' => 520, 'credits' => 13, 'weeks' => 6, 'per_credit' => 40, 'note' => 'most popular'],
            ['name' => 'Elite', 'price' => 720, 'credits' => 20, 'weeks' => 8, 'per_credit' => 36],
        ],
        'credit_pack_rules' => '1 credit = 1 game (7v7, 8v8 or 11v11). One-time payment, no auto-renewal. '
            .'Validity starts from purchase; unused credits expire at the end of the window. '
            .'Tournaments are not included and are booked separately. A 3 AED card fee applies on direct Ziina payments. '
            .'Paying per game stays available for anyone who prefers it.',
    ],

    'defaults' => [
        'capacity' => (int) env('FBL_DEFAULT_CAPACITY', 14),
        'price' => (int) env('FBL_DEFAULT_PRICE', 50),
        'payment_link' => env('FBL_DEFAULT_PAYMENT_LINK'),
    ],

    // Unpaid held spots are released after this many minutes.
    'payment_timeout_minutes' => (int) env('FBL_PAYMENT_TIMEOUT_MINUTES', 120),

    // When a human replies from the phone app, the bot stays quiet in that chat for this long.
    'human_takeover_hours' => (int) env('FBL_HUMAN_TAKEOVER_HOURS', 12),

    // The bot's own number (for "Book on WhatsApp" links), no + or spaces.
    'bot_number' => env('FBL_BOT_NUMBER', '971503741554'),

    // Live roster page: https://your-domain/games/{token}. Pin it in the group description.
    // Empty = page disabled. Use a long random string so the page isn't guessable.
    'public_list_token' => env('FBL_PUBLIC_LIST_TOKEN'),

    // After every confirm/add/remove, send admins the game's list ready to paste in the group.
    'auto_share_list' => (bool) env('FBL_AUTO_SHARE_LIST', true),

    // Ping admins when a full game's waitlist reaches these sizes (suggests opening a second game).
    'waitlist_alert_at' => [1, 4, 8],

    // Max players one booking can hold.
    'max_players_per_booking' => (int) env('FBL_MAX_PLAYERS_PER_BOOKING', 6),
];
