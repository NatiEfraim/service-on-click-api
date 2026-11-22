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

    'adfs' => [
        'url' => env('ADFS_URL'),
        'user' => env('ADFS_USER'),
        'password' => env('ADFS_PASSWORD'),
    ],

    'vatican' => [
        'url' => env('VATICAN_URL'),
        'token' => env('VATICAN_TOKEN'),
    ],

    // The infosec guidelines the user-open declaration links to (טופס פתיחת משתמש).
    'ict' => [
        'infosec_guidelines_url' => env('ICT_INFOSEC_GUIDELINES_URL'),
    ],


    'digital-hatch' => [
        'red_to_black_ftp_path' => env('RED_TO_BLACK_FTP_PATH', '/R2B/Tikshoret-R2B-Prod/'),
        'black_to_red_ftp_path' => env('BLACK_TO_RED_FTP_PATH', '/Tikshoret-B2R-Prod/'),
        'black_to_red_ftp_attachments_path' => env('BLACK_TO_RED_FTP_ATTACHMENTS_PATH', '/DigitalFiles-B2R-Prod/'),
        'enabled' => filter_var(env('HATCH_ENABLED', false), FILTER_VALIDATE_BOOL),

        'force_ftp' => filter_var(env('HATCH_FORCE_FTP', false), FILTER_VALIDATE_BOOL),
        'format' => env('HATCH_FORMAT', 'json'),
        'prefix' => env('HATCH_PREFIX', 'SrvOnClick'),
        'files_prefix' => env('HATCH_FILES_PREFIX', 'SrvOnClickFiles'),

        'local' => [
            'import' => [
                'path' => env('DIGITAL_HATCH_IMPORT_PATH'),
                'files_path' => env('DIGITAL_HATCH_IMPORT_FILES_PATH'),
            ],
        ],
    ],

];
