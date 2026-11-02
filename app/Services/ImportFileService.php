<?php

namespace App\Services;

use App\Models\File;
use App\Models\Image;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Links the files and images the bookings name to their bytes. The booking json only names an attachment ("SrvOnClickFiles_<stored name>", no
 * extension) and ImportBookingService saves the row without knowing where its bytes are; the bytes arrive separately in one bucket folder
 * (ATTACHMENTS_FOLDER_NAME, "import-files") as SrvOnClickFiles_<stored name>.<ext>. One listing of that folder is enough to give every row the key
 * of its object (with the extension), the same thing AttachmentService::resolve does one row at a time when somebody opens a booking.
 * Nothing is created, moved or deleted: a row keeps waiting until its bytes arrive, and bytes no booking names yet stay where they are.
 */
class ImportFileService
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly AttachmentService $attachmentService,
    ) {
    }

    /**
     * @return array{busy: bool, in_bucket: int, linked: int, already_linked: int, waiting: int, unclaimed: int, errors: array<int, string>}
     *         in_bucket = files in the folder, linked = rows given their bytes now, already_linked = rows that had them, waiting = rows whose
     *         bytes are not in the folder, unclaimed = names in the folder no row has
     */
    public function importFromBucket(): array
    {
        $result = ['busy' => false, 'in_bucket' => 0, 'linked' => 0, 'already_linked' => 0, 'waiting' => 0, 'unclaimed' => 0, 'errors' => []];

        // The scheduler and a manual run may overlap; only one reads the folder at a time.
        $lock = Cache::lock('import-files', 600);

        if (!$lock->get()) {
            Log::info('Import of the files skipped: another run is reading the folder.');
            $result['busy'] = true;

            return $result;
        }

        try {
            $keysByName = $this->keysByName();
            $result['in_bucket'] = array_sum(array_map('count', $keysByName));
            $claimed = [];

            foreach ([File::class, Image::class] as $model) {
                $model::query()->chunkById(500, function ($rows) use ($keysByName, &$claimed, &$result): void {
                    foreach ($rows as $row) {
                        $this->linkRow($row, $keysByName, $claimed, $result);
                    }
                });
            }

            $result['unclaimed'] = count(array_diff_key($keysByName, $claimed));
        } finally {
            $lock->release();
        }

        Log::info("Import of the files: {$result['in_bucket']} in the bucket, {$result['linked']} linked now, {$result['already_linked']} linked before, "
            . "{$result['waiting']} waiting for their bytes, {$result['unclaimed']} not named by a booking yet.");

        return $result;
    }

    /** Gives one row the key of its bytes when the folder has them. */
    private function linkRow(File|Image $row, array $keysByName, array &$claimed, array &$result): void
    {
        $name = (string) $row->name;
        $keys = $keysByName[$name] ?? null;

        if ($keys !== null) {
            $claimed[$name] = true;
        }

        if (AttachmentService::isLinked($row)) {
            $result['already_linked']++;

            return;
        }

        $key = null;

        foreach (AttachmentService::extensionsFor($row) as $extension) {
            if (isset($keys[$extension])) {
                $key = $keys[$extension];
                break;
            }
        }

        if ($key === null) {
            $result['waiting']++;

            return;
        }

        try {
            $this->attachmentService->link($row, $key);
            $result['linked']++;
        } catch (\Exception $e) {
            Log::error("ImportFileService: could not link {$name}: " . $e->getMessage());
            $result['errors'][] = "{$name}: {$e->getMessage()}";
        }
    }

    /** The folder's files that can be attachments (a plain name and a type AttachmentService serves), as name => [extension => bucket key]. @return array<string, array<string, string>> */
    private function keysByName(): array
    {
        $keysByName = [];

        foreach ($this->storageService->listBucketFiles((string) config('filesystems.folder_name.attachments')) as $key) {
            $extension = AttachmentService::servedExtension($key);
            $name = pathinfo($key, PATHINFO_FILENAME);

            if ($extension !== null && AttachmentService::isValidName($name)) {
                $keysByName[$name][$extension] = $key;
            }
        }

        return $keysByName;
    }
}
