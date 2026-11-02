<?php

namespace App\Services;

use App\Enums\DigitalHatchFileEnum;
use App\Traits\GuardsDigitalHatchRuns;
use App\Traits\HatchImportable;

/**
 * The b2r side of the digital hatch, what the ":ftp" import commands do: the new files the mobile side put on the FTP (or in the local test
 * folders) are taken into the bucket, then imported from there by the same services the ":bucket" commands use, so a json file ends up in the
 * database and in loaded-json:
 *  - importBookings: SrvOnClick_b2r_export_bookings_* -> import-json/booking-data -> ImportBookingService;
 *  - importApplicationLogs: SrvOnClick_b2r_application_logs_* -> import-json/application-log -> ImportApplicationLogService;
 *  - importFiles: SrvOnClickFiles_* -> import-files -> ImportFileService (links them to the bookings that name them).
 * (The r2b side is one service per stream: UsersExportService and BookingStatusSnapshotService.)
 * Each run is serialized by a lock, so the scheduler and a manual run cannot overlap. HATCH_ENABLED=false turns it off.
 */
class DigitalHatchService
{
    use GuardsDigitalHatchRuns, HatchImportable;

    /**
     * @param bool $force take the files again even when they were taken before
     * @param bool $sync save the rows in this process instead of queueing them as chunk jobs
     * @return array{enabled: bool, busy?: bool, source?: string, fetched?: array<int, string>, errors?: array<int, string>, imported?: array<string, mixed>}
     *         fetched = the names landed in the bucket, errors = what went wrong taking them, imported = what the bucket import did
     */
    public function importBookings(bool $force = false, bool $sync = false): array
    {
        return $this->locked('import-bookings', fn (): array => $this->fetchAndImport(
            DigitalHatchFileEnum::EXPORT_BOOKINGS, app(ImportBookingService::class), $force, $sync,
        ));
    }

    /**
     * @param bool $force take the files again even when they were taken before
     * @param bool $sync save the rows in this process instead of queueing them as chunk jobs
     * @return array{enabled: bool, busy?: bool, source?: string, fetched?: array<int, string>, errors?: array<int, string>, imported?: array<string, mixed>}
     */
    public function importApplicationLogs(bool $force = false, bool $sync = false): array
    {
        return $this->locked('import-application-logs', fn (): array => $this->fetchAndImport(
            DigitalHatchFileEnum::APPLICATION_LOGS, app(ImportApplicationLogService::class), $force, $sync,
        ));
    }

    /**
     * @param bool $force take the files again even when they were taken before
     * @return array{enabled: bool, busy?: bool, source?: string, fetched?: array<int, string>, errors?: array<int, string>, imported?: array<string, mixed>}
     */
    public function importFiles(bool $force = false): array
    {
        return $this->locked('import-files', function () use ($force): array {
            $taken = $this->downloadAttachments($force);

            return [
                'source' => $taken['source'],
                'fetched' => $taken['files'],
                'errors' => $taken['errors'],
                'imported' => app(ImportFileService::class)->importFromBucket(),
            ];
        });
    }

    /** Takes the new files of a stream into the bucket, then imports what waits there (also what an earlier run left when it failed half way). @return array<string, mixed> */
    private function fetchAndImport(DigitalHatchFileEnum $stream, ImportBookingService|ImportApplicationLogService $importer, bool $force, bool $sync): array
    {
        $taken = $this->downloadFiles($stream, $force);

        return [
            'source' => $taken['source'],
            'fetched' => $taken['files'],
            'errors' => $taken['errors'],
            'imported' => config('app.import_json.enabled', true)
                ? $importer->importFromBucket($sync)
                : ['disabled' => true, 'busy' => false, 'files' => [], 'errors' => []],
        ];
    }
}
