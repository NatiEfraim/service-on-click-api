<?php

namespace App\Enums;

/**
 * The streams of files that travel through the digital hatch (FTP), named the way the mobile side names them:
 * r2b = written by this system, b2r = written by the mobile side. Each stream has a folder in the bucket.
 */
enum DigitalHatchFileEnum: string
{
    case BOOKING_STATUS = 'booking_status';
    case USERS = 'users';
    case SYSTEM_REFERENCES = 'system_references';

    case EXPORT_BOOKINGS = 'export_bookings';
    case APPLICATION_LOGS = 'application_logs';

    /** Streams this system sends. @return array<int, self> */
    public static function exportable(): array
    {
        return [self::BOOKING_STATUS, self::USERS, self::SYSTEM_REFERENCES];
    }

    /** Streams this system fetches. @return array<int, self> */
    public static function importable(): array
    {
        return [self::EXPORT_BOOKINGS, self::APPLICATION_LOGS];
    }

    public function isExportable(): bool
    {
        return in_array($this, self::exportable(), true);
    }

    /** "r2b" or "b2r", the part of the file name after the system prefix. */
    public function direction(): string
    {
        return $this->isExportable() ? 'r2b' : 'b2r';
    }

    /** Start of the file names of this stream, e.g. "SrvOnClick_b2r_export_bookings_" (the date follows). */
    public function fileNamePrefix(): string
    {
        return config('services.digital-hatch.prefix') . '_' . $this->direction() . '_' . $this->value . '_';
    }

    /** Start of the file names of the attachments, e.g. "SrvOnClickFiles_" (the stored name follows). */
    public static function attachmentFileNamePrefix(): string
    {
        return config('services.digital-hatch.files_prefix') . '_';
    }

    /** The bucket folder holding this stream's files: written there (r2b) or landed there for the importer to read (b2r). */
    public function bucketDirectory(): string
    {
        return match ($this) {
            self::BOOKING_STATUS => $this->exportDirectory('booking_status'),
            self::USERS => $this->exportDirectory('users'),
            self::SYSTEM_REFERENCES => $this->exportDirectory('system_references'),
            self::EXPORT_BOOKINGS => JsonModelTypeEnum::EXPORT_BOOKING->importRemoteDirectory(),
            self::APPLICATION_LOGS => JsonModelTypeEnum::APPLICATION_LOG->importRemoteDirectory(),
        };
    }

    /** The bucket folder for the backup of what this r2b stream sent to the FTP: export-json/ftp/{stream-folder}. @throws \LogicException for b2r streams */
    public function ftpBucketDirectory(): string
    {
        return match ($this) {
            self::BOOKING_STATUS => $this->ftpExportDirectory('booking_status'),
            self::USERS => $this->ftpExportDirectory('users'),
            self::SYSTEM_REFERENCES => $this->ftpExportDirectory('system_references'),
            default => throw new \LogicException("{$this->value} has no FTP backup directory"),
        };
    }

    private function exportDirectory(string $folder): string
    {
        return trim((string) config('filesystems.folder_name.export_json.base'), '/') . '/' . config('filesystems.folder_name.export_json.' . $folder);
    }

    private function ftpExportDirectory(string $folder): string
    {
        $base = trim((string) config('filesystems.folder_name.export_json.base'), '/');
        $sub = trim((string) config('filesystems.folder_name.export_json.ftp_sub', 'ftp'), '/');

        return $base . '/' . $sub . '/' . config('filesystems.folder_name.export_json.' . $folder);
    }
}
