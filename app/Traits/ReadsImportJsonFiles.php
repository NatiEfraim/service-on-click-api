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

    /** Imports all waiting files oldest-first; moves each to loaded-json when all chunks succeed. @param bool $sync run chunks inline @return array{busy: bool, files: array<int, array>, errors: array<int, array{file: string, message: string}>} */
    public function importFromBucket(bool $sync = false): array
    {
        $type = $this->jsonModelType();
        $result = ['busy' => false, 'files' => [], 'errors' => []];

        // The scheduler and /api/test-import may overlap; only one reads the folder at a time.
        $lock = Cache::lock('import-json:' . $type->name, 600);

        if (!$lock->get()) {
            Log::info("Import of the {$type->name} files skipped: another run is reading the folder.");
            $result['busy'] = true;

            return $result;
        }

        try {
            $directory = $type->importRemoteDirectory();

            foreach ($this->storageService->listJsonFilesInBucket($type->importFileNamePrefix(), $directory) as $fileName) {
                try {
                    $result['files'][] = $this->importFile($type, $fileName, $directory, $sync);
                } catch (\Exception $e) {
                    Log::error("Import of {$fileName} failed: " . $e->getMessage());
                    $result['errors'][] = ['file' => $fileName, 'message' => $e->getMessage()];
                }
            }
        } finally {
            $lock->release();
        }

        return $result;
    }

    /** @return array{file: string, rows: int, chunks: int, moved_to: ?string, stats: array<string, int>, errors: array<int, string>} */
    private function importFile(JsonModelTypeEnum $type, string $fileName, string $directory, bool $sync): array
    {
        $payload = $this->storageService->readJsonFromBucket($fileName, $directory);
        $rows = $payload[$type->payloadKey()] ?? null;

        if (!is_array($rows)) {
            throw new \InvalidArgumentException("Invalid {$fileName}: missing the {$type->payloadKey()} array");
        }

        // Keys are preserved so logs can name the row.
        $chunks = array_chunk($rows, $this->chunkSize(), true);
        $stats = [];
        $errors = [];

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
                $errors[] = "chunk {$chunkIndex}: " . $e->getMessage();
            }
        }

        // Sync only: a file with pending rows stays in place.
        if ($sync && $errors === [] && ($pending = $this->pendingReason($stats, $directory)) !== null) {
            $errors[] = $pending;
        }

        $movedTo = null;

        if ($errors === []) {
            $loadedDirectory = JsonModelTypeEnum::loadedRemoteDirectory();
            $movedTo = $loadedDirectory . '/' . $this->storageService->moveBucketFile($fileName, $directory, $loadedDirectory);
            $this->noteImported($type, $fileName);
        }

        Log::info("Import of {$fileName}: " . count($rows) . ' rows in ' . count($chunks) . ' chunks ' . ($sync ? 'saved' : 'queued')
            . ($movedTo ? ", file moved to {$movedTo}." : ', file left in place because of errors.'));

        return [
            'file' => $fileName,
            'rows' => count($rows),
            'chunks' => count($chunks),
            'moved_to' => $movedTo,
            'stats' => $stats,
            'errors' => $errors,
        ];
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
