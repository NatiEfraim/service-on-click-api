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

    // The digital hatch (FTP) the mobile side exchanges files through; the bucket stays this system's working area.
    // File names: <prefix>_r2b_<stream>_<date>.json written here, <prefix>_b2r_<stream>_<date>.json read here, <files_prefix>_<stored name>.<ext> for attachments.
    'digital-hatch' => [
        // r2b folder: this system puts the booking status and users files here.
        'red_to_black_ftp_path' => env('RED_TO_BLACK_FTP_PATH', '/R2B/Tikshoret-R2B-Prod/'),
        // b2r folders: the booking and application log files, and the attachments, the mobile side puts here.
        'black_to_red_ftp_path' => env('BLACK_TO_RED_FTP_PATH', '/Tikshoret-B2R-Prod/'),
        'black_to_red_ftp_attachments_path' => env('BLACK_TO_RED_FTP_ATTACHMENTS_PATH', '/DigitalFiles-B2R-Prod/'),
        // false stops the three digital-hatch commands.
        'enabled' => filter_var(env('HATCH_ENABLED', false), FILTER_VALIDATE_BOOL),
        'format' => env('HATCH_FORMAT', 'json'),
        // Keep SrvOnClick unless the mobile side renamed its files too: the bucket importers look for SrvOnClick_b2r_.
        'prefix' => env('HATCH_PREFIX', 'SrvOnClick'),
        'files_prefix' => env('HATCH_FILES_PREFIX', 'SrvOnClickFiles'),
        'local' => [
            'export' => [
                'path' => env('DIGITAL_HATCH_EXPORT_PATH'),
            ],
            'import' => [
                'path' => env('DIGITAL_HATCH_IMPORT_PATH'),
            ],
        ],
    ],

];
