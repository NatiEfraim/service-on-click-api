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

/**
 * Saves the files and images of the bookings. The booking json only names an attachment ("SrvOnClickFiles_<stored name>", no extension) and
 * ImportBookingService saves its file / image row without a place for the bytes; the bytes arrive separately in one bucket folder
 * (IMPORT_FILES_FOLDER_NAME, "import-files") as SrvOnClickFiles_<stored name>.<ext>. For every received file that a booking names this
 *  - copies it to the folder the bookings serve their files and images from (ATTACHMENTS_FOLDER_NAME, "files": all of them together),
 *  - gives the row the key of that copy, extension included,
 *  - and moves the received file to loaded-files (LOADED_FILES_FOLDER_NAME), a backup like loaded-json.
 * A received file no booking names yet stays in import-files until its booking is saved; a row whose bytes have not arrived keeps waiting.
 * Only names with the system prefix (SrvOnClickFiles_) and a type AttachmentService serves are read. Reading again changes nothing.
 */
class ImportFileService
{
    /** At most this many names of the waiting rows and of the received files nobody names are listed for the command to show. */
    private const NAMES_SHOWN = 10;

    public function __construct(
        private readonly StorageService $storageService,
        private readonly AttachmentService $attachmentService,
    ) {
    }

    /**
     * @return array{busy: bool, in_bucket: int, linked: int, moved: int, already_linked: int, waiting: int, unclaimed: int, waiting_names: array<int, string>, unclaimed_names: array<int, string>, errors: array<int, string>}
     *         in_bucket = received files in import-files, linked = rows given their bytes now, moved = received files saved and moved to loaded-files,
     *         already_linked = rows that had them, waiting = rows whose bytes have not arrived, unclaimed = received files no booking takes
     */
    public function importFromBucket(): array
    {
        $result = [
            'busy' => false, 'in_bucket' => 0, 'linked' => 0, 'moved' => 0, 'already_linked' => 0, 'waiting' => 0, 'unclaimed' => 0,
            'waiting_names' => [], 'unclaimed_names' => [], 'errors' => [],
        ];

        // The scheduler and a manual run may overlap; only one reads the folder at a time.
        $lock = Cache::lock('import-files', 600);

        if (!$lock->get()) {
            Log::info('Import of the files skipped: another run is reading the folder.');
            $result['busy'] = true;

            return $result;
        }

        try {
            $inbox = (string) config('filesystems.folder_name.import_files');
            $received = $this->receivedFiles($inbox);
            $unclaimed = [];

            $result['in_bucket'] = array_sum(array_map('count', $received));

            // The rows are looked up by name, a query per five hundred names.
            foreach (array_chunk(array_keys($received), 500) as $names) {
                $rows = File::query()->whereIn('name', $names)->get()->concat(Image::query()->whereIn('name', $names)->get())->groupBy('name');

                foreach ($names as $name) {
                    $this->take($received[$name], $rows->get($name, new Collection()), $inbox, $result, $unclaimed);
                }
            }

            $waiting = $this->unlinked(File::query())->count() + $this->unlinked(Image::query())->count();

            $result['waiting'] = $waiting;
            $result['waiting_names'] = array_slice(
                [...$this->unlinked(File::query())->limit(self::NAMES_SHOWN)->pluck('name')->all(), ...$this->unlinked(Image::query())->limit(self::NAMES_SHOWN)->pluck('name')->all()],
                0,
                self::NAMES_SHOWN,
            );
            $result['already_linked'] = max(0, File::query()->count() + Image::query()->count() - $waiting - $result['linked']);
            $result['unclaimed'] = count($unclaimed);
            $result['unclaimed_names'] = array_slice($unclaimed, 0, self::NAMES_SHOWN);
        } finally {
            $lock->release();
        }

        Log::info("Import of the files: {$result['in_bucket']} received, {$result['moved']} saved and moved, {$result['linked']} rows linked now, "
            . "{$result['already_linked']} linked before, {$result['waiting']} rows waiting for their bytes, {$result['unclaimed']} received files no booking names yet.");

        return $result;
    }

    /**
     * Saves the received files of one name: each goes with the rows that take it (the first extension of the row's kind that was received).
     * @param array<string, string> $files extension => received file name @param Collection<int, File|Image> $rows the rows with this name
     * @param array<int, string> $unclaimed filled with the received files no row takes
     */
    private function take(array $files, Collection $rows, string $inbox, array &$result, array &$unclaimed): void
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
        foreach (array_diff_key($files, $takenBy) as $fileName) {
            $unclaimed[] = $fileName;
        }

        foreach ($takenBy as $extension => $takers) {
            try {
                $this->save($files[$extension], $takers, $inbox, $result);
            } catch (\Throwable $e) {
                Log::error("ImportFileService: {$files[$extension]} failed: " . $e->getMessage());
                $result['errors'][] = "{$files[$extension]}: {$e->getMessage()}";
            }
        }
    }

    /** The copy first, so the rows never point at bytes that are not there; the received file moves on only when they do. @param array<int, File|Image> $rows */
    private function save(string $fileName, array $rows, string $inbox, array &$result): void
    {
        $key = $this->storageService->copyBucketFile($fileName, $inbox, (string) config('filesystems.folder_name.attachments'));

        foreach ($rows as $row) {
            if ((string) $row->path !== $key) {
                $this->attachmentService->link($row, $key);
                $result['linked']++;
            }
        }

        $this->storageService->moveBucketFile($fileName, $inbox, (string) config('filesystems.folder_name.loaded_files'));
        $result['moved']++;

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
