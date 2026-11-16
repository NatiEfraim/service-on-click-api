<?php

namespace App\Services;

use App\Enums\DigitalHatchFileEnum;
use App\Models\DigitalHatchFetchedFile;
use App\Models\File;
use App\Models\Image;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Copies received SrvOnClickFiles_* attachments from import-files to the live files folder, links them to their booking rows, moves them to loaded-files. */
class ImportFileService
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly AttachmentService $attachmentService,
    ) {
    }

    /** Imports all received files; each failure is logged, and the run throws at the end when any file could not be saved. */
    public function importFromBucket(): void
    {
        // The scheduler and a manual run may overlap; only one reads the folder at a time.
        $lock = Cache::lock('import-files', 600);

        if (!$lock->get()) {
            Log::info('Import of the files skipped: another run is reading the folder.');

            return;
        }

        $counts = ['in_bucket' => 0, 'linked' => 0, 'moved' => 0, 'unclaimed' => 0, 'failed' => 0];

        try {
            $inbox = (string) config('filesystems.folder_name.import_files');
            $received = $this->receivedFiles($inbox);

            $counts['in_bucket'] = array_sum(array_map('count', $received));

            // The rows are looked up by name, a query per five hundred names.
            foreach (array_chunk(array_keys($received), 500) as $names) {
                $rows = File::query()->whereIn('name', $names)->get()->concat(Image::query()->whereIn('name', $names)->get())->groupBy('name');

                foreach ($names as $name) {
                    $this->take($received[$name], $rows->get($name, new Collection()), $inbox, $counts);
                }
            }

            $waiting = $this->unlinked(File::query())->count() + $this->unlinked(Image::query())->count();
        } finally {
            $lock->release();
        }

        Log::info("Import of the files: {$counts['in_bucket']} received, {$counts['moved']} saved and moved, {$counts['linked']} rows linked now, "
            . "{$waiting} rows waiting for their bytes, {$counts['unclaimed']} received files no booking names yet.");

        if ($counts['failed'] > 0) {
            throw new \RuntimeException("{$counts['failed']} received files could not be saved, see the log.");
        }
    }

    /** Saves received files of one name, one per row that claims it; files no row takes are only counted (a booking may name them later). @param array<string, string> $files extension => file name @param array<string, int> $counts */
    private function take(array $files, Collection $rows, string $inbox, array &$counts): void
    {
        $takenBy = [];

        foreach ($rows as $row) {
            foreach (AttachmentService::extensionsFor($row) as $extension) {
                if (isset($files[$extension])) {
                    $takenBy[$extension][] = $row;
                    break;
                }
            }
        }

        // Not named by a booking yet, or named as the other kind (a pdf for a row that is an image).
        $counts['unclaimed'] += count(array_diff_key($files, $takenBy));

        foreach ($takenBy as $extension => $takers) {
            try {
                $this->save($files[$extension], $takers, $inbox, $counts);
            } catch (\Throwable $e) {
                Log::error("ImportFileService: {$files[$extension]} failed: " . $e->getMessage());
                $counts['failed']++;
            }
        }
    }

    /** The copy first, so the rows never point at bytes that are not there; the received file moves on only when they do. @param array<int, File|Image> $rows @param array<string, int> $counts */
    private function save(string $fileName, array $rows, string $inbox, array &$counts): void
    {
        $key = $this->storageService->copyBucketFile($fileName, $inbox, (string) config('filesystems.folder_name.attachments'));

        foreach ($rows as $row) {
            if ((string) $row->path !== $key) {
                $this->attachmentService->link($row, $key);
                $counts['linked']++;
            }
        }

        $this->storageService->moveBucketFile($fileName, $inbox, (string) config('filesystems.folder_name.loaded_files'));
        $counts['moved']++;

        // The ledger only says the file was imported; the import does not depend on it.
        try {
            DigitalHatchFetchedFile::record($fileName, DigitalHatchFetchedFile::STREAM_ATTACHMENTS, DigitalHatchFetchedFile::SOURCE_BUCKET);
        } catch (\Throwable $e) {
            Log::warning("ImportFileService: {$fileName} was saved but could not be noted in the ledger: " . $e->getMessage());
        }
    }

    /** The received files that can be attachments (the system prefix, a plain name, a type AttachmentService serves), as name => [extension => file name]. @return array<string, array<string, string>> */
    private function receivedFiles(string $inbox): array
    {
        $received = [];
        $prefix = DigitalHatchFileEnum::attachmentFileNamePrefix();

        foreach ($this->storageService->listBucketFiles($inbox) as $key) {
            $fileName = basename($key);
            $extension = AttachmentService::servedExtension($fileName);
            $name = pathinfo($fileName, PATHINFO_FILENAME);

            if ($extension !== null && str_starts_with($fileName, $prefix) && AttachmentService::isValidName($name)) {
                $received[$name][$extension] = $fileName;
            }
        }

        return $received;
    }

    /** The rows that do not know where their bytes are (the path has no extension of a served type). */
    private function unlinked(Builder $query): Builder
    {
        return $query->where(function (Builder $rows): void {
            $rows->whereNull('path')->orWhere(function (Builder $none): void {
                foreach (array_keys(AttachmentService::TYPES) as $extension) {
                    $none->where('path', 'not like', '%.' . $extension);
                }
            });
        });
    }
}
