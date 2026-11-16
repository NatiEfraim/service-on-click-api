<?php

namespace App\Services;

use App\Enums\DigitalHatchFileEnum;
use App\Traits\GuardsDigitalHatchRuns;
use App\Traits\HatchImportable;
use Illuminate\Support\Facades\Log;

/** Handles the b2r side of the digital hatch: fetches new files from the FTP into the bucket and imports them via the :bucket services. */
class DigitalHatchService
{
    use GuardsDigitalHatchRuns, HatchImportable;

    /** @param bool $force re-fetch already-taken files @param bool $sync run chunk jobs inline @return bool the run happened (false = hatch disabled or another run working); throws when anything failed */
    public function importBookings(bool $force = false, bool $sync = false): bool
    {
        return $this->lockedRun('import-bookings', fn () => $this->fetchAndImport(
            DigitalHatchFileEnum::EXPORT_BOOKINGS, app(ImportBookingService::class), $force, $sync,
        ));
    }

    /** @param bool $force re-fetch already-taken files @param bool $sync run chunk jobs inline @return bool the run happened (false = hatch disabled or another run working); throws when anything failed */
    public function importApplicationLogs(bool $force = false, bool $sync = false): bool
    {
        return $this->lockedRun('import-application-logs', fn () => $this->fetchAndImport(
            DigitalHatchFileEnum::APPLICATION_LOGS, app(ImportApplicationLogService::class), $force, $sync,
        ));
    }

    /** @param bool $force re-fetch already-taken files @return bool the run happened (false = hatch disabled or another run working); throws when anything failed */
    public function importFiles(bool $force = false): bool
    {
        return $this->lockedRun('import-files', function () use ($force): void {
            $failed = $this->downloadAttachments($force);

            // What landed (also what an earlier run left behind) is imported even when some files failed to land.
            app(ImportFileService::class)->importFromBucket();

            if ($failed > 0) {
                throw new \RuntimeException("{$failed} attachment files could not be taken from the hatch, see the log.");
            }
        });
    }

    /** Takes the new files of a stream into the bucket, then imports what waits there (also what an earlier run left when it failed half way). Throws when anything failed. */
    private function fetchAndImport(DigitalHatchFileEnum $stream, ImportBookingService|ImportApplicationLogService $importer, bool $force, bool $sync): void
    {
        $failed = $this->downloadFiles($stream, $force);

        if (config('app.import_json.enabled', true)) {
            $importer->importFromBucket($sync);
        } else {
            Log::info("Import of the {$stream->value} files skipped (IMPORT_JSON_ENABLED=false); the files only landed in the bucket.");
        }

        if ($failed > 0) {
            throw new \RuntimeException("{$failed} {$stream->value} files could not be taken from the hatch, see the log.");
        }
    }
}
