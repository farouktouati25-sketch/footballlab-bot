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
            'Dubai Schools Al Barsha|Al Safa Sports Ground'
        ))))),
        // Google Maps pins, sent when someone asks where a pitch is. Key = words found in the game's venue name.
        'venue_maps' => [
            'al safa' => env('FBL_MAP_AL_SAFA', 'https://maps.app.goo.gl/Uo2jFF9MCQWcXGgz5'),
            'dubai schools' => env('FBL_MAP_DUBAI_SCHOOLS', 'https://maps.app.goo.gl/d3tcegj5gMRPURQ8A'),
            'north london' => env('FBL_MAP_NAD_AL_SHEBA', 'https://maps.app.goo.gl/4kBpxxiH3wwMstzq9'),
            'nad al sheba' => env('FBL_MAP_NAD_AL_SHEBA', 'https://maps.app.goo.gl/4kBpxxiH3wwMstzq9'),
        ],
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

    // Unknown number (no ad, no chat history): ask the admins "old client or new player?" before replying.
    'ask_admin_unknown' => (bool) env('FBL_ASK_ADMIN_UNKNOWN', true),
    'ask_admin_wait_minutes' => (int) env('FBL_ASK_ADMIN_WAIT_MINUTES', 15),

    'defaults' => [
        'capacity' => (int) env('FBL_DEFAULT_CAPACITY', 14),
        'price' => (int) env('FBL_DEFAULT_PRICE', 50),
        'payment_link' => env('FBL_DEFAULT_PAYMENT_LINK'),
    ],

    // Price and spots per format. A venue rule (matched on part of the venue name) overrides the format.
    'formats' => [
        '7v7' => ['price' => 53, 'capacity' => 14],
        '8v8' => ['price' => 53, 'capacity' => 16],
        '11v11' => ['price' => 58, 'capacity' => 22],
        'tournament' => ['price' => 58, 'capacity' => 22], // adjust per tournament with "edit 12 price 70"
    ],
    'venue_pricing' => [
        'north london collegiate' => ['price' => 58, 'capacity' => 14], // Nad Al Sheba pitch
    ],

    // Fixed weekly games, created automatically "weekly_days_ahead" days in advance.
    // "choices" = format decided each week: the bot asks admins the day before at "format_question_at".
    'weekly' => [
        ['day' => 'monday', 'time' => '21:00', 'venue' => 'Al Safa Sports Ground', 'format' => '7v7'],
        ['day' => 'tuesday', 'time' => '21:00', 'venue' => 'Dubai Schools Al Barsha', 'choices' => ['8v8', '11v11', 'tournament'], 'venues' => 'nightly'],
        ['day' => 'wednesday', 'time' => '21:00', 'venue' => 'Al Safa Sports Ground', 'format' => '7v7'],
        ['day' => 'thursday', 'time' => '21:00', 'venue' => 'Al Safa Sports Ground', 'format' => '7v7'],
        ['day' => 'friday', 'time' => '21:00', 'venue' => 'Dubai Schools Al Barsha', 'choices' => ['8v8', '11v11', 'tournament'], 'venues' => 'nightly'],
        ['day' => 'saturday', 'time' => '20:00', 'venue' => 'Dubai Schools Al Barsha', 'choices' => ['8v8', '11v11', 'tournament'], 'venues' => 'nightly'],
        ['day' => 'sunday', 'time' => '20:00', 'venue' => 'Dubai Schools Al Barsha', 'choices' => ['8v8', '11v11', 'tournament'], 'venues' => 'nightly'],
    ],
    // 'venues' => 'nightly': the admins also confirm the pitch each night (buttons below, button title => venue name).
    'nightly_venues' => [
        'Dubai Schools' => 'Dubai Schools Al Barsha',
        'Al Safa' => 'Al Safa Sports Ground',
        'Nad Al Sheba' => 'North London Collegiate School - Nad Al Sheba',
    ],
    'weekly_days_ahead' => (int) env('FBL_WEEKLY_DAYS_AHEAD', 7),
    'format_question_at' => env('FBL_FORMAT_QUESTION_AT', '12:00'),

    // Players reserve anytime; the payment link goes out the day before the game at this time (Dubai).
    'payment_link_day_before_at' => env('FBL_PAYMENT_LINK_AT', '12:00'),

    // Online payments. With a Ziina API token, every booking gets its own pay link for the exact
    // amount (price × players), and payment is confirmed automatically by Ziina's webhook.
    'payments' => [
        // admin_link = the bot asks an admin, who replies with a payment link for that booking.
        // ziina      = the bot creates the exact-amount link itself via the Ziina API (needs ZIINA_API_TOKEN).
        // fixed      = the game's link / FBL_DEFAULT_PAYMENT_LINK.
        'mode' => env('FBL_PAYMENT_MODE', 'admin_link'),
        'ziina_token' => env('ZIINA_API_TOKEN', ''),
        'ziina_webhook_secret' => env('ZIINA_WEBHOOK_SECRET', ''),
        'ziina_test_mode' => (bool) env('ZIINA_TEST_MODE', false),
        // Added on top of each booking (e.g. the 3 AED card fee). 0 = none.
        'fee_per_booking' => (float) env('FBL_PAYMENT_FEE', 0),
        // true = a Ziina-verified payment confirms the spot without an admin tap.
        'auto_confirm_verified' => (bool) env('FBL_AUTO_CONFIRM_PAID', false),
        // Optional page the client lands on after paying (e.g. wa.me link back to the bot).
        'thank_you_url' => env('FBL_PAYMENT_RETURN_URL', 'https://wa.me/'.env('FBL_BOT_NUMBER', '971503741554')),
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

    // Message people who asked for a day / played that weekday last week when its game is published.
    'reminders' => [
        'enabled' => (bool) env('FBL_REMINDERS', true),
        'lookback_days' => (int) env('FBL_REMINDER_LOOKBACK_DAYS', 60),
    ],

    // Text(s) of the WhatsApp Business app greeting/away message, separated by "||". Echoes that start
    // with one of these are automatic, so they don't pause the bot. Repeated canned first-contact
    // messages are detected automatically too; this just makes it certain from the very first lead.
    'auto_reply_texts' => array_values(array_filter(array_map('trim', explode('||', (string) env('FBL_AUTO_REPLY_TEXTS', ''))))),

    // Ping admins when a full game's waitlist reaches these sizes (suggests opening a second game).
    'waitlist_alert_at' => [1, 4, 8],

    // Max players one booking can hold.
    'max_players_per_booking' => (int) env('FBL_MAX_PLAYERS_PER_BOOKING', 6),
];
