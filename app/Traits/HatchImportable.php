<?php

namespace App\Traits;

use App\Enums\DigitalHatchFileEnum;
use App\Enums\DigitalHatchFormatEnum;
use App\Models\DigitalHatchFetchedFile;
use App\Services\AttachmentService;
use App\Services\StorageService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fetches the b2r files the mobile side put on the digital hatch FTP and lands them in the bucket, where the importers
 * (importBookingJson:mysql, importApplicationLogJson:mysql, AttachmentService) read them as if the mobile app had written them there.
 * What was fetched is remembered by its name on the FTP, so nothing is fetched twice; the FTP is never written to or cleaned.
 */
trait HatchImportable
{
    use ResolvesAttachmentStoragePath;

    /**
     * Fetch every new booking / application log file in one FTP listing: SrvOnClick_b2r_export_bookings_<date>.json and
     * SrvOnClick_b2r_application_logs_<date>.json. A file that is not valid json yet (still being uploaded) is left for the next run.
     * @return array{files: array<string, array<int, string>>, errors: array<int, string>} the names landed, by stream
     */
    public function downloadFiles(): array
    {
        $result = ['files' => [], 'errors' => []];

        $path = $this->hatchFolder('black_to_red_ftp_path');
        $extension = '.' . DigitalHatchFormatEnum::current()->extension();

        try {
            $names = $this->hatchFileNames($path);
        } catch (\Throwable $e) {
            return $this->hatchFailed($result, "listing {$path}", $e);
        }

        $byStream = [];
        foreach ($names as $fileName) {
            $stream = $this->importableStreamOf($fileName);

            if ($stream && Str::endsWith(Str::lower($fileName), $extension)) {
                $byStream[$stream->value][] = $fileName;
            }
        }

        $fetched = $this->alreadyFetched(Arr::flatten($byStream));
        $storage = app(StorageService::class);

        // No order is applied: the importers read the bucket folder oldest name first, whatever order the files landed in.
        foreach ($byStream as $streamValue => $fileNames) {
            $stream = DigitalHatchFileEnum::from($streamValue);

            foreach ($fileNames as $fileName) {
                if (isset($fetched[$fileName])) {
                    continue;
                }

                try {
                    $content = Storage::disk('digital-hatch')->get($path . $fileName);

                    if (!is_array(json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $content), true))) {
                        $result['errors'][] = "{$fileName}: not valid json (yet), left on the FTP for the next run";
                        continue;
                    }

                    $storage->putJsonToBucket($fileName, $stream->bucketDirectory(), $content);
                    static::markProcessed($fileName);
                    $result['files'][$stream->value][] = $fileName;
                } catch (\Throwable $e) {
                    $this->hatchFailed($result, $fileName, $e);
                }
            }
        }

        return $result;
    }

    /**
     * Fetch the attachments of the bookings: SrvOnClickFiles_<stored name>.<ext> (pdf, docx, ... images) from the attachments folder.
     * Only the types AttachmentService serves are taken; they land under their own name in the bucket folder it reads.
     * @return array{files: array<int, string>, errors: array<int, string>} the names landed
     */
    public function downloadAttachments(): array
    {
        $result = ['files' => [], 'errors' => []];

        $path = $this->hatchFolder('black_to_red_ftp_attachments_path');

        try {
            $names = array_values(array_filter($this->hatchFileNames($path), fn (string $fileName): bool => $this->isAttachmentName($fileName)));
        } catch (\Throwable $e) {
            return $this->hatchFailed($result, "listing {$path}", $e);
        }

        $fetched = $this->alreadyFetched($names);
        $disk = Storage::disk(config('filesystems.storage_service'));

        foreach ($names as $fileName) {
            if (isset($fetched[$fileName])) {
                continue;
            }

            try {
                $content = Storage::disk('digital-hatch')->get($path . $fileName);

                if ($content === null || $content === '') {
                    $result['errors'][] = "{$fileName}: empty, left on the FTP for the next run";
                    continue;
                }

                if (!$disk->put($this->attachmentStoragePathFor($fileName), $content)) {
                    throw new \RuntimeException('could not write it to the bucket');
                }

                static::markProcessed($fileName);
                $result['files'][] = $fileName;
            } catch (\Throwable $e) {
                $this->hatchFailed($result, $fileName, $e);
            }
        }

        return $result;
    }

    /** Remembers a file of the FTP as taken, so the next run skips it. */
    public static function markProcessed(string $fileName): void
    {
        try {
            DigitalHatchFetchedFile::firstOrCreate(['file_name' => $fileName]);
        } catch (\Exception $e) {
            Log::error("HatchImportable@markProcessed error: {$e->getMessage()}");
        }
    }

    /** The folder as configured, with exactly one slash at the end. */
    private function hatchFolder(string $configKey): string
    {
        return rtrim((string) config("services.digital-hatch.{$configKey}"), '/') . '/';
    }

    /** Names (not paths) of the files in an FTP folder, oldest name first. Throws when the FTP cannot be read. @return array<int, string> */
    private function hatchFileNames(string $path): array
    {
        return collect(Storage::disk('digital-hatch')->files($path))
            ->map(fn (string $file): string => basename($file))
            ->unique()
            ->sort(SORT_STRING)
            ->values()
            ->all();
    }

    /** The stream a b2r file name belongs to: the prefix is matched exactly (as the importers do), the case of the extension is not. */
    private function importableStreamOf(string $fileName): ?DigitalHatchFileEnum
    {
        foreach (DigitalHatchFileEnum::importable() as $stream) {
            if (str_starts_with($fileName, $stream->fileNamePrefix())) {
                return $stream;
            }
        }

        return null;
    }

    /** A stored attachment name AttachmentService can find and serve; anything else on the FTP is left alone. */
    private function isAttachmentName(string $fileName): bool
    {
        return str_starts_with($fileName, DigitalHatchFileEnum::attachmentFileNamePrefix())
            && AttachmentService::isValidName($fileName)
            && isset(AttachmentService::TYPES[strtolower(pathinfo($fileName, PATHINFO_EXTENSION))]);
    }

    /** Which of these names were fetched before, in a query per thousand names instead of one per file. @param array<int, string> $names @return array<string, true> */
    private function alreadyFetched(array $names): array
    {
        $fetched = [];

        foreach (array_chunk($names, 1000) as $chunk) {
            foreach (DigitalHatchFetchedFile::whereIn('file_name', $chunk)->pluck('file_name') as $fileName) {
                $fetched[$fileName] = true;
            }
        }

        return $fetched;
    }

    /** Logs a failure and adds it to the result the command shows. @param array{files: array, errors: array<int, string>} $result @return array{files: array, errors: array<int, string>} */
    private function hatchFailed(array &$result, string $what, \Throwable $e): array
    {
        Log::error("HatchImportable error ({$what}): {$e->getMessage()}");
        $result['errors'][] = "{$what}: {$e->getMessage()}";

        return $result;
    }
}
