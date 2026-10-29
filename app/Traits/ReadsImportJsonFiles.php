<?php

namespace App\Traits;

use App\Enums\JsonModelTypeEnum;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What both import services do before they save anything: find the files of their type waiting in the bucket,
 * queue the rows of each as chunk jobs and move the file to loaded-json.
 * The using service needs a StorageService in $this->storageService.
 */
trait ReadsImportJsonFiles
{
    /** The kind of file the service reads. */
    abstract protected function jsonModelType(): JsonModelTypeEnum;

    /** Rows in one chunk job. */
    abstract protected function chunkSize(): int;

    /** The chunk job for one slice of the file's rows. @param array $rows, int $chunkIndex */
    abstract protected function makeChunkJob(array $rows, int $chunkIndex): object;

    /**
     * Read every file of this type waiting in import-json/<folder>, oldest first, queue its rows chunk by chunk and move the file to loaded-json.
     * A file is moved only once all its chunks are queued (or, with $sync, were saved without an error); a file that cannot be read stays where it is.
     * @param bool $sync run the chunk jobs right now, in this process, and add up what they did - the queue worker is not needed
     * @return array{busy: bool, files: array<int, array>, errors: array<int, array{file: string, message: string}>}
     */
    public function importFromBucket(bool $sync = false): array
    {
        $type = $this->jsonModelType();
        $result = ['busy' => false, 'files' => [], 'errors' => []];

        // The scheduler and GET /api/test-import may both come by: only one of them reads the folder at a time.
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

    /** Queue (or run) the chunks of one file, then move it. @return array{file: string, rows: int, chunks: int, moved_to: ?string, stats: array<string, int>, errors: array<int, string>} */
    private function importFile(JsonModelTypeEnum $type, string $fileName, string $directory, bool $sync): array
    {
        $payload = $this->storageService->readJsonFromBucket($fileName, $directory);
        $rows = $payload[$type->payloadKey()] ?? null;

        if (!is_array($rows)) {
            throw new \InvalidArgumentException("Invalid {$fileName}: missing the {$type->payloadKey()} array");
        }

        // The keys stay, so a log line can say which row of the file it means.
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
