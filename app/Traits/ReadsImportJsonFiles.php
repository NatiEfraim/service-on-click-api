<?php

namespace App\Traits;

use App\Enums\JsonModelTypeEnum;
use App\Models\DigitalHatchFetchedFile;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared import step: reads the waiting files of this type in the bucket's import-json folder (only those with the system prefix, e.g.
 * SrvOnClick_b2r_export_bookings_), queues their rows as chunk jobs, moves the file to loaded-json and notes it in digital_hatch_fetched_files.
 * The using service needs a StorageService in $this->storageService.
 */
trait ReadsImportJsonFiles
{
    abstract protected function jsonModelType(): JsonModelTypeEnum;

    abstract protected function chunkSize(): int;

    abstract protected function makeChunkJob(array $rows, int $chunkIndex): object;

    /**
     * Why a file that was just saved in this process (not queued) must stay in its folder although no chunk failed; null when it can move on.
     * Rows that cannot be saved *yet* - a log whose booking is missing - are not an error of the file, but moving it would lose them.
     * @param array<string, int> $stats what the chunks returned, added up
     */
    protected function pendingReason(array $stats, string $directory): ?string
    {
        return null;
    }

    /**
     * Imports every waiting file of this type, oldest first. A file is moved only once all its chunks are queued
     * (or saved without error when $sync); an unreadable file stays in place.
     * @param bool $sync run the chunk jobs in this process instead of queueing them
     * @return array{busy: bool, files: array<int, array>, errors: array<int, array{file: string, message: string}>}
     */
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

        // Saved here, not queued, so what could not be saved yet is known now: such a file stays where it is, to be read again later.
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

    /**
     * Notes in the ledger of the digital hatch that this file was imported from the bucket (a file a ":ftp" import took there first keeps its row).
     * The ledger only records; the import does not depend on it.
     */
    private function noteImported(JsonModelTypeEnum $type, string $fileName): void
    {
        try {
            DigitalHatchFetchedFile::record($fileName, $type->hatchStream()->value, DigitalHatchFetchedFile::SOURCE_BUCKET);
        } catch (\Throwable $e) {
            Log::warning("Import of {$fileName} was saved but could not be noted in the ledger: " . $e->getMessage());
        }
    }
}
