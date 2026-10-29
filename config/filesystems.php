<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

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

        // Local development (STORAGE_SERVICE=minio). The s3 disk above is for the servers.
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

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

    // Which disk stores the booking images and files: "minio" (local) or "s3" (servers).
    'storage_service' => env('STORAGE_SERVICE', 'minio'),

    // Folder inside the bucket for the uploaded files.
    'storage_dir' => env('STORAGE_DIR', 'public'),

    // Lifetime (minutes) of the signed URLs returned for the private bucket.
    'temp_url_lifetime' => (int) env('TEMP_URL_LIFETIME', 30),

    // Folder names inside the bucket.
    'folder_name' => [
        // The application log, uploaded to the bucket when a request fails (StorageService::logToS3()).
        'log_folder_name' => (string) env('LOG_FOLDER_NAME', 'logs'),

        // The b2r files the mobile side drops in the bucket - read by ImportBookingService / ImportApplicationLogService.
        'import_json' => [
            'base' => (string) env('IMPORT_JSON', 'import-json'),

            'booking_data' => (string) env('BOOKING_DATA_JSON_FOLDER_NAME', 'booking-data'),
            'application_log' => (string) env('APPLICATION_LOG_JSON_FOLDER_NAME', 'application-log'),
        ],

        // A file that was read moves here (both kinds of files, side by side).
        'loaded_json' => (string) env('LOADED_JSON_FOLDER_NAME', 'loaded-json'),
    ],

];
