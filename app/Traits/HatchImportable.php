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

/** Takes b2r files from the digital hatch FTP into the bucket; remembers every fetch in digital_hatch_fetched_files so nothing is taken twice. */
trait HatchImportable
{
    use ResolvesAttachmentStoragePath;

    /** Lands new b2r json files from the FTP into the bucket; skips invalid JSON (still uploading) and already-fetched names. Every failure is logged. @param bool $force re-fetch even if already taken @return int files that could not be taken (0 = all landed) */
    public function downloadFiles(DigitalHatchFileEnum $stream, bool $force = false): int
    {
        try {
            $source = $this->hatchSource('black_to_red_ftp_path', 'path');
        } catch (\Throwable $e) {
            return $this->hatchFailed('source', $e);
        }

        try {
            $names = $this->hatchFileNames($source['disk'], $source['folder']);
        } catch (\Throwable $e) {
            return $this->hatchFailed("listing {$source['label']}", $e);
        }

        $prefix = $stream->fileNamePrefix();
        $extension = '.' . DigitalHatchFormatEnum::current()->extension();

        $names = array_values(array_filter($names, fn (string $fileName): bool => str_starts_with($fileName, $prefix)
            && Str::endsWith(Str::lower($fileName), $extension)));

        $fetched = $force ? [] : $this->alreadyFetched($names);
        $storage = app(StorageService::class);
        $landed = 0;
        $failed = 0;

        foreach ($names as $fileName) {
            if (isset($fetched[$fileName])) {
                continue;
            }

            try {
                $content = (string) $source['disk']->get($source['folder'] . $fileName);

                if (!is_array(json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $content), true))) {
                    Log::warning("HatchImportable: {$fileName} is not valid json (yet), left in the source for the next run.");
                    $failed++;
                    continue;
                }

                // The ledger first: when it cannot be written (the migration was not run) nothing lands, so a file is never taken twice.
                static::markProcessed($fileName, $stream->value, $source['kind']);

                try {
                    $storage->putJsonToBucket($fileName, $stream->bucketDirectory(), $content);
                } catch (\Throwable $e) {
                    DigitalHatchFetchedFile::where('file_name', $fileName)->delete();

                    throw $e;
                }

                $landed++;
            } catch (\Throwable $e) {
                $failed += $this->hatchFailed($fileName, $e);
            }
        }

        Log::info("Digital hatch {$stream->value}: {$landed} files landed from {$source['label']}" . ($failed > 0 ? ", {$failed} failed" : '') . '.');

        return $failed;
    }

    /** Lands new SrvOnClickFiles_* attachments from the FTP into the import-files bucket folder; only types AttachmentService serves are taken. Files without a served extension have their type detected (and TIFF converted to PNG) from the bytes. Every failure is logged. @param bool $force re-fetch even if already taken @return int files that could not be taken (0 = all landed) */
    public function downloadAttachments(bool $force = false): int
    {
        try {
            $source = $this->hatchSource('black_to_red_ftp_attachments_path', 'files_path');
        } catch (\Throwable $e) {
            return $this->hatchFailed('source', $e);
        }

        try {
            $names = array_values(array_filter(
                $this->hatchFileNames($source['disk'], $source['folder']),
                // Accept names with a known extension and also names without one: the mobile
                // exports files without an extension or as .tiff; the type comes from the bytes below.
                fn (string $fileName): bool => $this->isAttachmentName($fileName)
                    || $this->isAttachmentBaseName($fileName),
            ));
        } catch (\Throwable $e) {
            return $this->hatchFailed("listing {$source['label']}", $e);
        }

        $fetched = $force ? [] : $this->alreadyFetched($names);
        $disk = Storage::disk(config('filesystems.storage_service'));
        $landed = 0;
        $failed = 0;

        foreach ($names as $fileName) {
            if (isset($fetched[$fileName])) {
                continue;
            }

            try {
                $content = $source['disk']->get($source['folder'] . $fileName);

                if ($content === null || $content === '') {
                    Log::warning("HatchImportable: {$fileName} is empty, left in the source for the next run.");
                    $failed++;
                    continue;
                }

                // For extension-less files or unrecognised extensions (e.g. .tiff from the mobile):
                // detect the type from bytes and, if needed, convert (TIFF → PNG via Imagick).
                $saveFileName = $fileName;
                $saveContent = $content;
                if (AttachmentService::servedExtension($fileName) === null) {
                    $detected = $this->detectOrConvert($content);

                    if ($detected === null) {
                        Log::error("HatchImportable: {$fileName}: could not determine the file type from the bytes, skipped.");
                        $failed++;
                        continue;
                    }

                    // Strip any existing non-served extension (e.g. ".tiff") before appending the target one.
                    $stem = pathinfo($fileName, PATHINFO_FILENAME);
                    $saveFileName = "{$stem}.{$detected['extension']}";
                    $saveContent = $detected['content'];
                }

                // The ledger first (see downloadFiles). Ledger key is the FTP name (may differ from $saveFileName).
                static::markProcessed($fileName, DigitalHatchFetchedFile::STREAM_ATTACHMENTS, $source['kind']);

                try {
                    if (!$disk->put($this->attachmentStoragePathFor($saveFileName), $saveContent)) {
                        throw new \RuntimeException('could not write it to the bucket');
                    }
                } catch (\Throwable $e) {
                    DigitalHatchFetchedFile::where('file_name', $fileName)->delete();

                    throw $e;
                }

                $landed++;
            } catch (\Throwable $e) {
                $failed += $this->hatchFailed($fileName, $e);
            }
        }

        Log::info("Digital hatch attachments: {$landed} files landed from {$source['label']}" . ($failed > 0 ? ", {$failed} failed" : '') . '.');

        return $failed;
    }

    /** Records a file as fetched in the ledger so the next run skips it; replaces the row on --force. Throws when the ledger cannot be written. */
    public static function markProcessed(string $fileName, string $stream, string $source): void
    {
        DigitalHatchFetchedFile::record($fileName, $stream, $source, replace: true);
    }

    /** Returns the source disk and folder for a b2r stream; local test folder if configured, otherwise the FTP. @return array{disk: Filesystem, folder: string, label: string, kind: string} */
    private function hatchSource(string $ftpFolderKey, string $localKey): array
    {
        $local = trim((string) config("services.digital-hatch.local.import.{$localKey}"));

        // HATCH_FORCE_FTP=true overrides any local path so the server always uses the real FTP.
        if ($local !== '' && !config('services.digital-hatch.force_ftp', false)) {
            // Not created when missing (unlike the export folder): a mistyped path must not leave an empty folder behind.
            if (!is_dir($local)) {
                $envKey = $localKey === 'path' ? 'DIGITAL_HATCH_IMPORT_PATH' : 'DIGITAL_HATCH_IMPORT_FILES_PATH';
                throw new \RuntimeException(
                    "the local folder {$local} does not exist " .
                    "(set by {$envKey} — clear it in .env to use the FTP instead)"
                );
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

    /** An attachment name without a served extension: either a bare name (no extension) or one with a non-served extension (e.g. .tiff); type is detected and converted from bytes. */
    private function isAttachmentBaseName(string $fileName): bool
    {
        return str_starts_with($fileName, DigitalHatchFileEnum::attachmentFileNamePrefix())
            && AttachmentService::isValidName($fileName)
            && AttachmentService::servedExtension($fileName) === null;
    }

    /**
     * Detects the served file type from bytes; converts non-served image types (e.g. TIFF) to PNG via Imagick.
     * Returns ['extension' => string, 'content' => string] or null when the type cannot be determined or converted.
     * @return array{extension: string, content: string}|null
     */
    private function detectOrConvert(string $bytes): ?array
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);

        $direct = [
            'image/jpeg'                                                                  => 'jpg',
            'image/png'                                                                   => 'png',
            'image/gif'                                                                   => 'gif',
            'image/webp'                                                                  => 'webp',
            'application/pdf'                                                             => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        ];

        if (isset($direct[$mime])) {
            return ['extension' => $direct[$mime], 'content' => $bytes];
        }

        // Convert TIFF and other Imagick-readable raster formats to PNG.
        if (class_exists(\Imagick::class)) {
            try {
                $imagick = new \Imagick();
                $imagick->readImageBlob($bytes);
                $imagick->setImageFormat('png');

                return ['extension' => 'png', 'content' => $imagick->getImageBlob()];
            } catch (\Throwable) {
                // fall through — Imagick could not read the bytes
            }
        }

        return null;
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

    /** Logs a failure; returns 1 so callers can count it. */
    private function hatchFailed(string $what, \Throwable $e): int
    {
        Log::error("HatchImportable error ({$what}): {$e->getMessage()}");

        return 1;
    }
}
