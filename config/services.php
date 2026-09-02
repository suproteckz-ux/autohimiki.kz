<?php

return [
    'kaspi' => [
        'merchant_id' => env('KASPI_MERCHANT_ID'),
        'city_id' => env('KASPI_CITY_ID', '750000000'),
        'button_template' => 'button',
        'widget_script_url' => 'https://kaspi.kz/kaspibutton/widget/ks-wi_ext.js',
    ],
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'resend' => [
        'key' => env('RESEND_KEY'),
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
];
