<?php

namespace App\Services;

use App\Enums\DigitalHatchFileEnum;
use App\Traits\GuardsDigitalHatchRuns;
use App\Traits\HatchImportable;

/** Handles the b2r side of the digital hatch: fetches new files from the FTP into the bucket and imports them via the :bucket services. */
class DigitalHatchService
{
    use GuardsDigitalHatchRuns, HatchImportable;

    /** @param bool $force re-fetch already-taken files @param bool $sync run chunk jobs inline @return array{enabled: bool, busy?: bool, source?: string, fetched?: array<int, string>, errors?: array<int, string>, imported?: array<string, mixed>} */
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
