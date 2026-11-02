<?php

return [

    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'timezone' => 'UTC',

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    // Requests per minute for the "api" rate limiter.
    'rate_limitation' => env('RATE_LIMITATION', 100),

    // false outside production: the bucket name becomes part of the object keys.
    'prod_stage' => env('PROD_STAGE', true),

    // b2r files the mobile side drops in the bucket.
    'import_json' => [
        // false stops the import commands and GET /api/test-import.
        'enabled' => filter_var(env('IMPORT_JSON_ENABLED', true), FILTER_VALIDATE_BOOL),

        'booking_chunk_size' => (int) env('IMPORT_BOOKING_JSON_CHUNK_SIZE', 100),
        'application_log_chunk_size' => (int) env('IMPORT_APPLICATION_LOG_JSON_CHUNK_SIZE', 500),
    ],

    // r2b files this system writes for the mobile side.
    'export_json' => [
        // false stops the schedule and POST /sync/run; decisions wait until it is on.
        'enabled' => filter_var(env('EXPORT_JSON_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Must match the prefix the mobile importer actually reads (it looks for b2r).
        'booking_status_prefix' => (string) env('EXPORT_BOOKING_STATUS_PREFIX', 'SrvOnClick_r2b_booking_status_'),

        // Timezone of the day in the status file name; null = app timezone.
        'timezone' => env('EXPORT_JSON_TIMEZONE') ?: null,

        // Sends configuration changes as r2b system_config files; kept while off.
        'config_channel_enabled' => filter_var(env('SYNC_CONFIG_CHANNEL_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Failed writes retry with growing waits.
        'max_attempts' => (int) env('EXPORT_JSON_MAX_ATTEMPTS', 8),

        'cron' => env('EXPORT_JSON_CRON', '*/2 * * * *'),
    ],

    // Retry/timeout settings and schedule per job in app/Jobs.
    'jobs' => [
        'import_booking_json' => [
            'cron' => env('IMPORT_BOOKING_JSON_CRON', '*/5 * * * *'),
            'tries' => (int) env('IMPORT_BOOKING_JSON_JOB_TRIES', 3),
            'backoff' => (int) env('IMPORT_BOOKING_JSON_JOB_BACKOFF', 10),
            'timeout' => (int) env('IMPORT_BOOKING_JSON_JOB_TIMEOUT', 900),
        ],

        'import_application_log_json' => [
            'cron' => env('IMPORT_APPLICATION_LOG_JSON_CRON', '1-59/5 * * * *'),
            'tries' => (int) env('IMPORT_APPLICATION_LOG_JSON_JOB_TRIES', 5),
            'backoff' => (int) env('IMPORT_APPLICATION_LOG_JSON_JOB_BACKOFF', 60),
            'timeout' => (int) env('IMPORT_APPLICATION_LOG_JSON_JOB_TIMEOUT', 900),
        ],

        // The r2b sends to the digital hatch (FTP), one job per stream. The commands only queue them, so a queue worker must be running.
        // They run on the odd minutes, after the export wrote its files. A run that fails (FTP down) is tried again after the back-off.
        'digital_hatch_send_users' => [
            'cron' => env('DIGITAL_HATCH_SEND_USERS_CRON', '1-59/2 * * * *'),
            'tries' => (int) env('DIGITAL_HATCH_SEND_USERS_JOB_TRIES', 3),
            'backoff' => (int) env('DIGITAL_HATCH_SEND_USERS_JOB_BACKOFF', 60),
            'timeout' => (int) env('DIGITAL_HATCH_SEND_USERS_JOB_TIMEOUT', 300),
        ],

        'digital_hatch_send_booking_status' => [
            'cron' => env('DIGITAL_HATCH_SEND_BOOKING_STATUS_CRON', '1-59/2 * * * *'),
            'tries' => (int) env('DIGITAL_HATCH_SEND_BOOKING_STATUS_JOB_TRIES', 3),
            'backoff' => (int) env('DIGITAL_HATCH_SEND_BOOKING_STATUS_JOB_BACKOFF', 60),
            'timeout' => (int) env('DIGITAL_HATCH_SEND_BOOKING_STATUS_JOB_TIMEOUT', 300),
        ],

        // The files and images wait in the bucket folder import-files; a file belongs to a booking, so this comes after the two crons above.
        'import_file' => [
            'cron' => env('IMPORT_FILE_CRON', '4-59/5 * * * *'),
        ],

        // The b2r import commands (importBookingJson, importApplicationLogJson, importFile; each ":bucket" or ":ftp") only queue their own job, so a
        // queue worker must be running. The job reads the bucket folder (and, for ":ftp", first takes the new files from the digital hatch into it)
        // and queues the chunk jobs above. A run that fails (FTP down) is tried again after the back-off. All six jobs use these settings.
        'import_job' => [
            'tries' => (int) env('IMPORT_JOB_TRIES', 3),
            'backoff' => (int) env('IMPORT_JOB_BACKOFF', 60),
            'timeout' => (int) env('IMPORT_JOB_TIMEOUT', 900),
        ],

        // When the ":ftp" imports run (they do nothing while HATCH_ENABLED=false). The ":bucket" ones use the two crons at the top. The logs
        // come a minute after the bookings because a log belongs to a booking.
        'digital_hatch_import_booking' => [
            'cron' => env('DIGITAL_HATCH_IMPORT_BOOKING_CRON', '2-59/5 * * * *'),
        ],

        'digital_hatch_import_application_log' => [
            'cron' => env('DIGITAL_HATCH_IMPORT_APPLICATION_LOG_CRON', '3-59/5 * * * *'),
        ],

        'digital_hatch_import_file' => [
            'cron' => env('DIGITAL_HATCH_IMPORT_FILE_CRON', '4-59/5 * * * *'),
        ],
    ],

    // Defaults used when no system reference overrides them.
    'booking_type' => [
        'barber_booking' => [
            'haircut_time' => env('HAIRCUT_TIME', 30),
            'clean_time' => env('CLEAN_TIME', 15),
            'advance_month' => env('ADVANCE_MONTH', 2),
            'service_start_time' => env('SERVICE_START_TIME', '07:00'),
            'service_end_time' => env('SERVICE_END_TIME', '18:00'),
            'break_start_time' => env('BREAK_START_TIME', '11:15'),
            'break_end_time' => env('BREAK_END_TIME', '14:45'),
        ],
        'uploads' => [
            'image' => [
                'mimes' => explode(',', env('BOOKING_UPLOAD_IMAGE_MIMES', 'image/png,image/jpeg,image/jpg')),
                'max_kb' => (int) env('BOOKING_UPLOAD_IMAGE_MAX_KB', 5120),
            ],
            'file' => [
                'mimes' => explode(',', env(
                    'BOOKING_UPLOAD_FILE_MIMES',
                    'application/pdf,'
                    . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                    . 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
                    . 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                )),
                'max_kb' => (int) env('BOOKING_UPLOAD_FILE_MAX_KB', 10240),
            ],
        ],
    ],

];
