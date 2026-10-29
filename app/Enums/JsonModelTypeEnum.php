<?php

namespace App\Enums;

/** The b2r json files this project reads from the bucket; the mobile project writes them. */
enum JsonModelTypeEnum: int
{
    /** Prefix stamped on every exported json file name. */
    public const EXPORT_FILE_PREFIX = 'SrvOnClick_b2r_';

    //
    case EXPORT_BOOKING = 1;
    case APPLICATION_LOG = 3;

    /** The file name after EXPORT_FILE_PREFIX, up to the date: "export_bookings_" for SrvOnClick_b2r_export_bookings_2026-10-29.json. */
    public function prefixJsonFile(): string
    {
        return match ($this) {
            self::EXPORT_BOOKING => 'export_bookings_',
            self::APPLICATION_LOG => 'application_logs_',
        };
    }

    /** What an import matches to find this type's files, e.g. "SrvOnClick_b2r_export_bookings_". */
    public function importFileNamePrefix(): string
    {
        return self::EXPORT_FILE_PREFIX . $this->prefixJsonFile();
    }

    /** The key of the list inside the file: {"bookings": [...]} or {"application_logs": [...]}. */
    public function payloadKey(): string
    {
        return match ($this) {
            self::EXPORT_BOOKING => 'bookings',
            self::APPLICATION_LOG => 'application_logs',
        };
    }

    /** Bucket folder the mobile side drops this type's files in: import-json/booking-data or import-json/application-log. */
    public function importRemoteDirectory(): string
    {
        $folder = match ($this) {
            self::EXPORT_BOOKING => config('filesystems.folder_name.import_json.booking_data'),
            self::APPLICATION_LOG => config('filesystems.folder_name.import_json.application_log'),
        };

        return trim((string) config('filesystems.folder_name.import_json.base'), '/') . '/' . $folder;
    }

    /** Bucket folder a file moves to once it was read: loaded-json. */
    public static function loadedRemoteDirectory(): string
    {
        return (string) config('filesystems.folder_name.loaded_json');
    }
}
