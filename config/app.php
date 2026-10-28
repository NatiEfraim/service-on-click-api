<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    // Requests per minute the "api" rate limiter allows (per user, or per IP when not logged in).
    'rate_limitation' => env('RATE_LIMITATION', 100),

    // Set PROD_STAGE=false outside production: the bucket name is then part of the object keys (StorageService).
    'prod_stage' => env('PROD_STAGE', true),

    // Booking type settings the management endpoints fall back to when no system reference overrides them.
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
