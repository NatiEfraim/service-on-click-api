<?php

namespace App\Traits;

use App\Enums\JsonModelTypeEnum;
use App\Models\DigitalHatchFetchedFile;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Reads waiting import-json files, dispatches chunk jobs, moves each to loaded-json, records in digital_hatch_fetched_files. Requires $this->storageService. */
trait ReadsImportJsonFiles
{
    abstract protected function jsonModelType(): JsonModelTypeEnum;

    abstract protected function chunkSize(): int;

    abstract protected function makeChunkJob(array $rows, int $chunkIndex): object;

    /** Override to return a reason a sync-imported file must stay in place despite no errors (e.g. logs waiting for bookings); null = move it. @param array<string, int> $stats */
    protected function pendingReason(array $stats, string $directory): ?string
    {
        return null;
    }

    /** Imports all waiting files oldest-first; each failure is logged, and the run throws at the end when any file failed or still waits. @param bool $sync run chunks inline */
    public function importFromBucket(bool $sync = false): void
    {
        $type = $this->jsonModelType();

        // The scheduler and a manual run may overlap; only one reads the folder at a time.
        $lock = Cache::lock('import-json:' . $type->name, 600);

        if (!$lock->get()) {
            Log::info("Import of the {$type->name} files skipped: another run is reading the folder.");

            return;
        }

        $total = 0;
        $failed = 0;

        try {
            $directory = $type->importRemoteDirectory();

            foreach ($this->storageService->listJsonFilesInBucket($type->importFileNamePrefix(), $directory) as $fileName) {
                $total++;

                try {
                    $this->importFile($type, $fileName, $directory, $sync);
                } catch (\Exception $e) {
                    Log::error("Import of {$fileName} failed: " . $e->getMessage());
                    $failed++;
                }
            }
        } finally {
            $lock->release();
        }

        if ($failed > 0) {
            throw new \RuntimeException("{$failed} of {$total} {$type->name} files failed or still wait, see the log.");
        }
    }

    /** Imports one file and moves it to loaded-json; throws when a chunk failed or rows still wait (the file then stays in place for the next run). */
    private function importFile(JsonModelTypeEnum $type, string $fileName, string $directory, bool $sync): void
    {
        $payload = $this->storageService->readJsonFromBucket($fileName, $directory);
        $rows = $payload[$type->payloadKey()] ?? null;

        if (!is_array($rows)) {
            throw new \InvalidArgumentException("Invalid {$fileName}: missing the {$type->payloadKey()} array");
        }

        // Keys are preserved so logs can name the row.
        $chunks = array_chunk($rows, $this->chunkSize(), true);
        $stats = [];
        $failedChunks = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            $job = $this->makeChunkJob($chunk, $chunkIndex);

            if (!$sync) {
                dispatch($job);
                continue;
            }

            try {
                foreach ((array) app(Dispatcher::class)->dispatchNow($job) as $name => $count) {
                    $stats[$name] = ($stats[$name] ?? 0) + $count;
                }
            } catch (\Exception $e) {
                Log::error("Import of {$fileName}: chunk {$chunkIndex} failed: " . $e->getMessage());
                $failedChunks++;
            }
        }

        // Sync only: a file with pending rows stays in place.
        $pending = $sync && $failedChunks === 0 ? $this->pendingReason($stats, $directory) : null;

        if ($failedChunks > 0 || $pending !== null) {
            Log::info("Import of {$fileName}: " . count($rows) . ' rows in ' . count($chunks) . ' chunks, the file stays in place.');

            throw new \RuntimeException($failedChunks > 0 ? "{$failedChunks} of " . count($chunks) . ' chunks failed' : $pending);
        }

        $loadedDirectory = JsonModelTypeEnum::loadedRemoteDirectory();
        $movedTo = $loadedDirectory . '/' . $this->storageService->moveBucketFile($fileName, $directory, $loadedDirectory);
        $this->noteImported($type, $fileName);

        Log::info("Import of {$fileName}: " . count($rows) . ' rows in ' . count($chunks) . ' chunks ' . ($sync ? 'saved' : 'queued') . ", file moved to {$movedTo}.");
    }

    /** Notes in the digital hatch ledger that this bucket file was imported; non-fatal (the import does not depend on it). */
    private function noteImported(JsonModelTypeEnum $type, string $fileName): void
    {
        try {
            DigitalHatchFetchedFile::record($fileName, $type->hatchStream()->value, DigitalHatchFetchedFile::SOURCE_BUCKET);
        } catch (\Throwable $e) {
            Log::warning("Import of {$fileName} was saved but could not be noted in the ledger: " . $e->getMessage());
        }
    }
}
