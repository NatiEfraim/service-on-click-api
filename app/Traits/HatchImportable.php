<?php

namespace App\Traits;

use App\Enums\DigitalHatchFileEnum;
use App\Enums\DigitalHatchFormatEnum;
use App\Models\DigitalHatchFetchedFile;
use App\Services\AttachmentService;
use App\Services\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Takes the b2r files the mobile side put on the digital hatch and lands them in the bucket, where the importers (importBookingJson:bucket,
 * importApplicationLogJson:bucket, importFile:bucket) read them as if the mobile app had written them there: the json files in import-json, the
 * files and images in import-files. The hatch is the FTP; for local testing the folders DIGITAL_HATCH_IMPORT_PATH (the json files) and
 * DIGITAL_HATCH_IMPORT_FILES_PATH (the files and images) take its place. Only names with the system prefixes are taken (SrvOnClick_b2r_...,
 * SrvOnClickFiles_...). What was taken is remembered by its name in digital_hatch_fetched_files, with its stream and where it came from, so
 * nothing is taken twice (unless $force); the source is never written to or cleaned.
 */
trait HatchImportable
{
    use ResolvesAttachmentStoragePath;

    /**
     * Takes the new files of ONE b2r json stream - SrvOnClick_b2r_export_bookings_<date>.json or SrvOnClick_b2r_application_logs_<date>.json - into
     * the bucket folder its importer reads. A file that is not valid json yet (still being uploaded) is left for the next run.
     * @param bool $force take the files again even when they were taken before
     * @return array{source: string, files: array<int, string>, errors: array<int, string>} where they came from, the names landed
     */
    public function downloadFiles(DigitalHatchFileEnum $stream, bool $force = false): array
    {
        $result = ['source' => '', 'files' => [], 'errors' => []];

        try {
            $source = $this->hatchSource('black_to_red_ftp_path', 'path');
        } catch (\Throwable $e) {
            return $this->hatchFailed($result, 'source', $e);
        }

        $result['source'] = $source['label'];

        try {
            $names = $this->hatchFileNames($source['disk'], $source['folder']);
        } catch (\Throwable $e) {
            return $this->hatchFailed($result, "listing {$source['label']}", $e);
        }

        $prefix = $stream->fileNamePrefix();
        $extension = '.' . DigitalHatchFormatEnum::current()->extension();

        // The prefix is matched exactly (as the importers do), the case of the extension is not.
        $names = array_values(array_filter($names, fn (string $fileName): bool => str_starts_with($fileName, $prefix)
            && Str::endsWith(Str::lower($fileName), $extension)));

        $fetched = $force ? [] : $this->alreadyFetched($names);
        $storage = app(StorageService::class);

        // No order is applied: the importers read the bucket folder oldest name first, whatever order the files landed in.
        foreach ($names as $fileName) {
            if (isset($fetched[$fileName])) {
                continue;
            }

            try {
                $content = (string) $source['disk']->get($source['folder'] . $fileName);

                if (!is_array(json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $content), true))) {
                    $result['errors'][] = "{$fileName}: not valid json (yet), left in the source for the next run";
                    continue;
                }

                // The ledger first: when it cannot be written (the migration was not run) nothing lands, so a file is never taken twice.
                static::markProcessed($fileName, $stream->value, $source['kind']);

                try {
                    $storage->putJsonToBucket($fileName, $stream->bucketDirectory(), $content);
                } catch (\Throwable $e) {
                    // Not landed: the next run takes it again.
                    DigitalHatchFetchedFile::where('file_name', $fileName)->delete();

                    throw $e;
                }

                $result['files'][] = $fileName;
            } catch (\Throwable $e) {
                $this->hatchFailed($result, $fileName, $e);
            }
        }

        return $result;
    }

    /**
     * Takes the new attachments of the bookings - SrvOnClickFiles_<stored name>.<ext> (pdf, docx, ... images) - into the bucket folder the received
     * files wait in (import-files). Only the types AttachmentService serves are taken; they land under their own name.
     * @param bool $force take the files again even when they were taken before
     * @return array{source: string, files: array<int, string>, errors: array<int, string>} where they came from, the names landed
     */
    public function downloadAttachments(bool $force = false): array
    {
        $result = ['source' => '', 'files' => [], 'errors' => []];

        try {
            $source = $this->hatchSource('black_to_red_ftp_attachments_path', 'files_path');
        } catch (\Throwable $e) {
            return $this->hatchFailed($result, 'source', $e);
        }

        $result['source'] = $source['label'];

        try {
            $names = array_values(array_filter(
                $this->hatchFileNames($source['disk'], $source['folder']),
                fn (string $fileName): bool => $this->isAttachmentName($fileName),
            ));
        } catch (\Throwable $e) {
            return $this->hatchFailed($result, "listing {$source['label']}", $e);
        }

        $fetched = $force ? [] : $this->alreadyFetched($names);
        $disk = Storage::disk(config('filesystems.storage_service'));

        foreach ($names as $fileName) {
            if (isset($fetched[$fileName])) {
                continue;
            }

            try {
                $content = $source['disk']->get($source['folder'] . $fileName);

                if ($content === null || $content === '') {
                    $result['errors'][] = "{$fileName}: empty, left in the source for the next run";
                    continue;
                }

                // The ledger first (see downloadFiles).
                static::markProcessed($fileName, DigitalHatchFetchedFile::STREAM_ATTACHMENTS, $source['kind']);

                try {
                    if (!$disk->put($this->attachmentStoragePathFor($fileName), $content)) {
                        throw new \RuntimeException('could not write it to the bucket');
                    }
                } catch (\Throwable $e) {
                    DigitalHatchFetchedFile::where('file_name', $fileName)->delete();

                    throw $e;
                }

                $result['files'][] = $fileName;
            } catch (\Throwable $e) {
                $this->hatchFailed($result, $fileName, $e);
            }
        }

        return $result;
    }

    /**
     * Remembers a file of the source as taken, with its stream and where it came from, so the next run skips it (a file taken again on purpose,
     * --force, has its row refreshed). Throws when the ledger cannot be written.
     */
    public static function markProcessed(string $fileName, string $stream, string $source): void
    {
        DigitalHatchFetchedFile::record($fileName, $stream, $source, replace: true);
    }

    /**
     * Where the files of a b2r folder are: the local test folder when it is set ($localKey under services.digital-hatch.local.import), else the FTP.
     * @return array{disk: Filesystem, folder: string, label: string, kind: string} folder is '' or ends with one slash
     */
    private function hatchSource(string $ftpFolderKey, string $localKey): array
    {
        $local = trim((string) config("services.digital-hatch.local.import.{$localKey}"));

        if ($local !== '') {
            // Not created when missing (unlike the export folder): a mistyped path must not leave an empty folder behind.
            if (!is_dir($local)) {
                throw new \RuntimeException("the local folder {$local} does not exist");
            }

            return [
                'disk' => Storage::build(['driver' => 'local', 'root' => $local, 'throw' => true]),
                'folder' => '',
                'label' => "local folder {$local}",
                'kind' => DigitalHatchFetchedFile::SOURCE_LOCAL,
            ];
        }

        $folder = $this->hatchFolder($ftpFolderKey);

        return ['disk' => Storage::disk('digital-hatch'), 'folder' => $folder, 'label' => "FTP folder {$folder}", 'kind' => DigitalHatchFetchedFile::SOURCE_FTP];
    }

    /** The folder as configured, with exactly one slash at the end. */
    private function hatchFolder(string $configKey): string
    {
        return rtrim((string) config("services.digital-hatch.{$configKey}"), '/') . '/';
    }

    /** Names (not paths) of the files in a folder of the source, oldest name first. Throws when the source cannot be read. @return array<int, string> */
    private function hatchFileNames(Filesystem $disk, string $folder): array
    {
        return collect($disk->files($folder))
            ->map(fn (string $file): string => basename($file))
            ->unique()
            ->sort(SORT_STRING)
            ->values()
            ->all();
    }

    /** A stored attachment name AttachmentService can find and serve; anything else in the source is left alone. */
    private function isAttachmentName(string $fileName): bool
    {
        return str_starts_with($fileName, DigitalHatchFileEnum::attachmentFileNamePrefix())
            && AttachmentService::isValidName($fileName)
            && AttachmentService::servedExtension($fileName) !== null;
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
