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
        ],

        // Local development only; the s3 disk is for the servers.
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

        // The digital hatch: the FTP the two systems exchange their files through (DigitalHatchService).
        // "throw" is on so an unreachable server fails the run instead of looking like an empty folder.
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

    // "minio" locally, "s3" on the servers.
    'storage_service' => env('STORAGE_SERVICE', 'minio'),

    'storage_dir' => env('STORAGE_DIR', 'public'),

    // Minutes a signed URL for the private bucket stays valid.
    'temp_url_lifetime' => (int) env('TEMP_URL_LIFETIME', 30),

    // Folder names inside the bucket.
    'folder_name' => [
        'log_folder_name' => (string) env('LOG_FOLDER_NAME', 'logs'),

        // b2r json files dropped by the mobile side: SrvOnClick_b2r_export_bookings_* and SrvOnClick_b2r_application_logs_*. Both kinds go straight into
        // "base", told apart by their names; fill the two folders below to read them from subfolders of it instead (e.g. booking-data, application-log).
        'import_json' => [
            'base' => (string) env('IMPORT_JSON', 'import-json'),

            'booking_data' => (string) env('BOOKING_DATA_JSON_FOLDER_NAME', ''),
            'application_log' => (string) env('APPLICATION_LOG_JSON_FOLDER_NAME', ''),
        ],

        // A json file that was saved in the database moves here (a backup of what was read).
        'loaded_json' => (string) env('LOADED_JSON_FOLDER_NAME', 'loaded-json'),

        // b2r files and images, SrvOnClickFiles_<stored name>.<ext>: they arrive in "import_files". Once one is saved in the database - linked to the
        // booking whose json names it - it is copied to "attachments" (where the bookings serve their files and images from, all of them in one
        // folder) and the received file moves to "loaded_files" (a backup, like loaded_json).
        'import_files' => (string) env('IMPORT_FILES_FOLDER_NAME', 'import-files'),
        'loaded_files' => (string) env('LOADED_FILES_FOLDER_NAME', 'loaded-files'),
        'attachments' => (string) env('ATTACHMENTS_FOLDER_NAME', 'files'),

        // r2b files written for the mobile side.
        'export_json' => [
            'base' => (string) env('EXPORT_JSON', 'export-json'),

            'booking_status' => (string) env('BOOKING_STATUS_JSON_FOLDER_NAME', 'booking-status'),
            'booking_values' => (string) env('BOOKING_VALUES_JSON_FOLDER_NAME', 'booking-values'),
            'system_config' => (string) env('SYSTEM_CONFIG_JSON_FOLDER_NAME', 'system-config'),
            'users' => (string) env('USERS_JSON_FOLDER_NAME', 'users'),
        ],
    ],

];
