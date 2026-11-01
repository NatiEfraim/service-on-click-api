<?php

namespace App\Enums;

/** The b2r json files read from the bucket (written by the mobile project). */
enum JsonModelTypeEnum: int
{
    /** Prefix of every exported json file name. */
    public const EXPORT_FILE_PREFIX = 'SrvOnClick_b2r_';

    case EXPORT_BOOKING = 1;
    case APPLICATION_LOG = 3;

    /** File name part between the prefix and the date, e.g. "export_bookings_". */
    public function prefixJsonFile(): string
    {
        return match ($this) {
            self::EXPORT_BOOKING => 'export_bookings_',
            self::APPLICATION_LOG => 'application_logs_',
        };
    }

    /** Prefix used to find this type's files, e.g. "SrvOnClick_b2r_export_bookings_". */
    public function importFileNamePrefix(): string
    {
        return self::EXPORT_FILE_PREFIX . $this->prefixJsonFile();
    }

    /** Key of the list inside the file. */
    public function payloadKey(): string
    {
        return match ($this) {
            self::EXPORT_BOOKING => 'bookings',
            self::APPLICATION_LOG => 'application_logs',
        };
    }

    /** Bucket folder where this type's files are dropped. */
    public function importRemoteDirectory(): string
    {
        $folder = match ($this) {
            self::EXPORT_BOOKING => config('filesystems.folder_name.import_json.booking_data'),
            self::APPLICATION_LOG => config('filesystems.folder_name.import_json.application_log'),
        };

        return trim((string) config('filesystems.folder_name.import_json.base'), '/') . '/' . $folder;
    }

    /** Bucket folder a file is moved to once read. */
    public static function loadedRemoteDirectory(): string
    {
        return (string) config('filesystems.folder_name.loaded_json');
    }
}
