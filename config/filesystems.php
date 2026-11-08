<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
            'http' => [
                'verify' => false,
            ]
        ],

        'minio' => [
            'driver' => 's3',
            'key' => env('MINIO_ACCESS_KEY_ID'),
            'secret' => env('MINIO_SECRET_ACCESS_KEY'),
            'region' => env('MINIO_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('MINIO_BUCKET'),
            'url' => env('MINIO_URL'),
            'endpoint' => env('MINIO_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(env('MINIO_USE_PATH_STYLE_ENDPOINT', true), FILTER_VALIDATE_BOOL),
            'throw' => true,
            'report' => false,
        ],


        'digital-hatch' => [
            'driver' => 'ftp',
            'host' => env('HATCH_FTP_HOST'),
            'username' => env('HATCH_FTP_USERNAME'),
            'password' => env('HATCH_FTP_PASSWORD'),
            'root' => env('FTP_ROOT', ''), // e.g. /path/to/root
            'ignorePassiveAddress' => true,
            'passive' => true,
            'ssl' => false,
            'timeout' => 30,
            'throw' => true,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

    'storage_service' => env('STORAGE_SERVICE', 'minio'),

    'storage_dir' => env('STORAGE_DIR', 'public'),

    'temp_url_lifetime' => (int) env('TEMP_URL_LIFETIME', 30),

    // Folder names inside the bucket.
    'folder_name' => [
        'log_folder_name' => (string) env('LOG_FOLDER_NAME', 'logs'),


        'import_json' => [
            'base' => (string) env('IMPORT_JSON', 'import-json'),

            'booking_data' => (string) env('BOOKING_DATA_JSON_FOLDER_NAME', ''),
            'application_log' => (string) env('APPLICATION_LOG_JSON_FOLDER_NAME', ''),
        ],

        'loaded_json' => (string) env('LOADED_JSON_FOLDER_NAME', 'loaded-json'),


        'import_files' => (string) env('IMPORT_FILES_FOLDER_NAME', 'import-files'),
        'loaded_files' => (string) env('LOADED_FILES_FOLDER_NAME', 'loaded-files'),
        'attachments' => (string) env('ATTACHMENTS_FOLDER_NAME', 'files'),

        'export_json' => [
            'base' => (string) env('EXPORT_JSON', 'export-json'),

            'booking_status' => (string) env('BOOKING_STATUS_JSON_FOLDER_NAME', 'booking-status'),
            'booking_values' => (string) env('BOOKING_VALUES_JSON_FOLDER_NAME', 'booking-values'),
            'system_config' => (string) env('SYSTEM_CONFIG_JSON_FOLDER_NAME', 'system-config'),
            'users' => (string) env('USERS_JSON_FOLDER_NAME', 'users'),
        ],

        'export_report' => (string) env('EXPORT_REPORT_FOLDER_NAME', 'export-report'),
    ],

];
