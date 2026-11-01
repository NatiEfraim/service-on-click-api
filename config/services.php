<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Login: ADFS identifies the person behind the portal token.
    'adfs' => [
        'url' => env('ADFS_URL'),
        'user' => env('ADFS_USER'),
        'password' => env('ADFS_PASSWORD'),
    ],

    // People directory: maps a personal number to an ID number.
    'vatican' => [
        'url' => env('VATICAN_URL'),
        'token' => env('VATICAN_TOKEN'),
    ],

];
