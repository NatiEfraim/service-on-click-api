<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Jobs\ImportApplicationLogJsonChunkJob;
use App\Jobs\ImportBookingJsonChunkJob;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * What the six import commands share: importBookingJson, importApplicationLogJson and importFile, each with the place the files come from after
 * the colon - ":bucket" reads the bucket folder, ":ftp" first takes the new files from the digital hatch (the FTP, or the local test folders)
 * into it.
 *  - The json imports (bookings, application logs): the command runs its trait, the trait the service; the service takes and reads the file,
 *    queues its rows as chunk jobs (ImportBookingJsonChunkJob, ImportApplicationLogJsonChunkJob) that a queue worker saves in the database, moves
 *    the file to loaded-json and notes it in digital_hatch_fetched_files. --sync saves the rows here instead, so no worker is needed.
 *  - The files and images: by default the command queues its own job (copying files takes a while); --sync does it here.
 * Either way the command shows what happened, so each import can be tested alone.
 */
abstract class ImportCommand extends Command
{
    /**
     * Does the import (a json import reads the files here and queues its chunk jobs; the files import queues its job), or with $sync does all of it here.
     * @return array{enabled: bool, switch?: string, queued?: bool, job?: class-string, result?: array<string, mixed>}
     */
    abstract protected function importFrom(bool $force, bool $sync): array;

    /** The kind of json file this command imports; null for the files and images. */
    protected function modelType(): ?JsonModelTypeEnum
    {
        return null;
    }

    public function handle(): int
    {
        try {
            $sync = (bool) $this->option('sync');
            $outcome = $this->importFrom($this->hasOption('force') && (bool) $this->option('force'), $sync);

            if (!$outcome['enabled']) {
                $this->info("Nothing imported: switched off ({$outcome['switch']}).");

                return self::SUCCESS;
            }

            if ($outcome['queued']) {
                $this->info(class_basename($outcome['job']) . ' queued: a queue worker (php artisan queue:work) imports it. Add --sync to import here and see the result.');

                return self::SUCCESS;
            }

            $result = $outcome['result'];

            if ($result['busy'] ?? false) {
                $this->info('Nothing imported: another run is working on these files.');

                return self::SUCCESS;
            }

            $errors = [...$this->describeTaken($result), ...$this->describeImported($result['imported'], $sync)];

            foreach ($errors as $error) {
                $this->error($error);
            }

            Log::info(static::class . ': done, ' . count($errors) . ' failed.');

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error(static::class . ': failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }

    /** The ":ftp" step: where the files were and which of them went into the bucket. @param array<string, mixed> $result @return array<int, string> what went wrong */
    private function describeTaken(array $result): array
    {
        // ":bucket" has no such step.
        if (!isset($result['source'])) {
            return [];
        }

        // An empty source means it could not even be found (a mistyped local folder): the error says why.
        if ($result['source'] !== '') {
            $this->info("Source: {$result['source']}");

            foreach ($result['fetched'] as $fileName) {
                $this->info("Taken into the bucket: {$fileName}");
            }

            if ($result['fetched'] === [] && $result['errors'] === []) {
                $this->info('No new file in the source (a file taken before is skipped, --force takes it again).');
            }
        }

        return $result['errors'];
    }

    /** The bucket step: what was saved from the files there. @param array<string, mixed> $imported @return array<int, string> what went wrong */
    private function describeImported(array $imported, bool $sync): array
    {
        return isset($imported['in_bucket']) ? $this->describeFiles($imported) : $this->describeJsonFiles($imported, $sync);
    }

    /** @param array<string, mixed> $imported ImportBookingService / ImportApplicationLogService::importFromBucket @return array<int, string> */
    private function describeJsonFiles(array $imported, bool $sync): array
    {
        $type = $this->modelType();
        $folder = $type->importRemoteDirectory();

        if ($imported['disabled'] ?? false) {
            $this->info("The import is switched off (IMPORT_JSON_ENABLED=false): the files stay in {$folder}.");

            return [];
        }

        if ($imported['busy']) {
            $this->info("Nothing imported: another run is reading {$folder}.");

            return [];
        }

        $rows = $type === JsonModelTypeEnum::EXPORT_BOOKING ? 'bookings' : 'logs';
        $errors = [];

        foreach ($imported['files'] as $file) {
            $line = "{$file['file']}: {$file['rows']} {$rows} in {$file['chunks']} chunks " . ($sync ? 'saved' : 'queued');

            if ($sync && $file['stats'] !== []) {
                $line .= ' (' . $this->statsLine($file['stats']) . ')';
            }

            $this->info($line . ($file['moved_to'] !== null ? ", file moved to {$file['moved_to']}" : ', file left in place because of errors'));

            foreach ($file['errors'] as $error) {
                $errors[] = "{$file['file']}: {$error}";
            }
        }

        foreach ($imported['errors'] as $error) {
            $errors[] = "{$error['file']}: {$error['message']}";
        }

        if ($imported['files'] === [] && $imported['errors'] === []) {
            $this->info("Nothing to import: no file is waiting in {$folder}.");
        }

        $this->describeQueuedChunks($imported, $type, $sync);

        return $errors;
    }

    /** After a run that queued the rows: which jobs they are and what a worker does with them. @param array<string, mixed> $imported */
    private function describeQueuedChunks(array $imported, JsonModelTypeEnum $type, bool $sync): void
    {
        $chunks = array_sum(array_column($imported['files'], 'chunks'));

        if ($sync || $chunks === 0) {
            return;
        }

        $job = class_basename($type === JsonModelTypeEnum::EXPORT_BOOKING ? ImportBookingJsonChunkJob::class : ImportApplicationLogJsonChunkJob::class);
        $line = "{$chunks} {$job} jobs queued: a queue worker (php artisan queue:work) saves them in the database.";

        if ($type === JsonModelTypeEnum::APPLICATION_LOG) {
            $line .= ' A log waits for its booking (looks again every ' . config('app.jobs.import_application_log_json.backoff') . ' seconds, '
                . config('app.jobs.import_application_log_json.tries') . ' tries), so import the bookings first; a chunk that still has no booking fails and `php artisan queue:retry all` saves it later.';
        }

        $this->info($line . ' Add --sync to save them here and see the result.');
    }

    /** @param array<string, mixed> $imported ImportFileService::importFromBucket @return array<int, string> */
    private function describeFiles(array $imported): array
    {
        $inbox = (string) config('filesystems.folder_name.import_files');
        $live = (string) config('filesystems.folder_name.attachments');
        $loaded = (string) config('filesystems.folder_name.loaded_files');

        if ($imported['busy']) {
            $this->info("Nothing imported: another run is reading {$inbox}.");

            return [];
        }

        $this->info("Files received in {$inbox}: {$imported['in_bucket']}. Saved now: {$imported['moved']} (copied to {$live}, the received files moved to {$loaded}), "
            . "{$imported['linked']} file / image rows linked.");
        $this->info("Linked before: {$imported['already_linked']}. Named by a booking but not received yet: {$imported['waiting']}. "
            . "Received but not named by a booking yet: {$imported['unclaimed']} (they stay in {$inbox}).");

        foreach ([['waiting_names', 'waiting', 'Not received yet'], ['unclaimed_names', 'unclaimed', "Waiting in {$inbox} for their booking"]] as [$names, $total, $label]) {
            if ($imported[$names] !== []) {
                $this->info("{$label}: " . implode(', ', $imported[$names]) . ($imported[$total] > count($imported[$names]) ? ', ...' : ''));
            }
        }

        return $imported['errors'];
    }

    /** "540 created, 3 values skipped": what a chunk saved, without the zeros. @param array<string, int> $stats */
    private function statsLine(array $stats): string
    {
        $parts = [];

        foreach ($stats as $name => $count) {
            if ($count > 0) {
                $parts[] = $count . ' ' . str_replace('_', ' ', $name);
            }
        }

        return $parts === [] ? 'nothing changed' : implode(', ', $parts);
    }
}
