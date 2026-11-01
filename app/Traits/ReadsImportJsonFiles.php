<?php

namespace App\Traits;

use App\Enums\JsonModelTypeEnum;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared import step: reads waiting bucket files, queues their rows as chunk jobs, moves the file to loaded-json.
 * The using service needs a StorageService in $this->storageService.
 */
trait ReadsImportJsonFiles
{
    abstract protected function jsonModelType(): JsonModelTypeEnum;

    abstract protected function chunkSize(): int;

    abstract protected function makeChunkJob(array $rows, int $chunkIndex): object;

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

        $movedTo = null;

        if ($errors === []) {
            $loadedDirectory = JsonModelTypeEnum::loadedRemoteDirectory();
            $movedTo = $loadedDirectory . '/' . $this->storageService->moveBucketFile($fileName, $directory, $loadedDirectory);
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
}
