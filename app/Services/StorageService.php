<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** The only place that talks to the bucket; STORAGE_SERVICE picks the disk ("minio" locally, "s3" on servers). */
class StorageService
{
    /** Uploads storage/logs/laravel.log to the bucket's logs folder; with $error the log is first reset to just that error. @return int HTTP status code */
    public function logToS3(\Throwable|string|null $error = null): int
    {
        try {

            $diskName = config('filesystems.storage_service');
            $isProd = (bool) config('app.prod_stage');

            $folder = config('filesystems.folder_name.log_folder_name', 'logs');
            $localPath = storage_path('logs/laravel.log');

            if (!is_null($error)) {
                $this->resetLogFileWithError($localPath, $error);
            }

            if (!is_file($localPath)) {
                return Response::HTTP_BAD_REQUEST;
            }

            $contents = file_get_contents($localPath);

            if ($contents === false) {
                return Response::HTTP_BAD_REQUEST;
            }

            $bucket = config("filesystems.disks.{$diskName}.bucket");

            $fileName = 'laravel.log';

            // Outside prod the bucket name is part of the object key.
            $prefix = !$isProd ? "{$bucket}/" : '';
            $objectPath = "{$prefix}{$folder}/{$fileName}";

            if (!Storage::disk($diskName)->put($objectPath, $contents)) {
                return Response::HTTP_INTERNAL_SERVER_ERROR;
            }

            return Response::HTTP_OK;
        } catch (\Exception $e) {
            Log::error('Error in StorageService: logToS3 function:' . $e->getMessage());
            return Response::HTTP_INTERNAL_SERVER_ERROR;
        }
    }

    /** Overwrites laravel.log with just this error so the next upload holds only it. */
    private function resetLogFileWithError(string $localPath, \Throwable|string $error): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        if ($error instanceof \Throwable) {
            $entry = sprintf(
                "[%s] %s: %s in %s:%d%s%s%s",
                $timestamp,
                get_class($error),
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
                PHP_EOL,
                $error->getTraceAsString(),
                PHP_EOL
            );
        } else {
            $entry = "[{$timestamp}] {$error}" . PHP_EOL;
        }

        // LOCK_EX so two failing requests cannot interleave half an entry each.
        if (file_put_contents($localPath, $entry, LOCK_EX) === false) {
            Log::error('Error in StorageService: resetLogFileWithError function: could not write the log file');
        }
    }

    public function deleteImage(string $path): bool
    {
        try {

            $disk = Storage::disk(config('filesystems.storage_service'));

            if (!$disk->exists($path)) {
                return false;
            }

            return $disk->delete($path);

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->logToS3();
            return false;
        }
    }

    public function deleteFile(string $path): bool
    {
        return $this->deleteImage($path);
    }

    /** Object key of a file in a bucket folder; outside prod the bucket name is part of the key (as in logToS3). */
    public function buildBucketKey(string $remoteDirectory, string $fileName = ''): string
    {
        $diskName = config('filesystems.storage_service');
        $prefixBucket = !config('app.prod_stage') ? config("filesystems.disks.{$diskName}.bucket") . '/' : '';

        return $prefixBucket . trim($remoteDirectory, '/') . ($fileName === '' ? '' : '/' . $fileName);
    }

    /** Names of .json files in a bucket folder starting with $fileNamePrefix, oldest first (the date in the name sorts the same). Throws when the disk refuses. @return array<int, string> */
    public function listJsonFilesInBucket(string $fileNamePrefix, string $remoteDirectory): array
    {
        $disk = Storage::disk(config('filesystems.storage_service'));

        return collect($disk->files($this->buildBucketKey($remoteDirectory)))
            ->map(fn (string $fileKey): string => basename($fileKey))
            ->filter(fn (string $fileName): bool => str_starts_with($fileName, $fileNamePrefix)
                && str_ends_with(strtolower($fileName), '.json'))
            ->sort(SORT_STRING)
            ->values()
            ->all();
    }

    /** Object keys of the files directly in a bucket folder (any type). Throws when the disk refuses. @return array<int, string> */
    public function listBucketFiles(string $remoteDirectory): array
    {
        return Storage::disk(config('filesystems.storage_service'))->files($this->buildBucketKey($remoteDirectory));
    }

    /** Reads and decodes a bucket JSON file; throws when it is missing, empty or invalid. */
    public function readJsonFromBucket(string $fileName, string $remoteDirectory): array
    {
        $disk = Storage::disk(config('filesystems.storage_service'));
        $fileKey = $this->buildBucketKey($remoteDirectory, $fileName);

        if (!$disk->exists($fileKey)) {
            throw new \RuntimeException("JSON file {$fileKey} not found in the bucket");
        }

        // Strip the UTF-8 BOM if present.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', (string) $disk->get($fileKey));

        if (trim($contents) === '') {
            throw new \RuntimeException("JSON file {$fileKey} is empty");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException("JSON file {$fileKey} is not a JSON object");
        }

        return $decoded;
    }

    /** Writes a JSON file into a bucket folder, replacing the one with that name. @return string the object key written */
    public function putJsonToBucket(string $fileName, string $remoteDirectory, string $contents): string
    {
        $fileKey = $this->buildBucketKey($remoteDirectory, $fileName);

        if (!Storage::disk(config('filesystems.storage_service'))->put($fileKey, $contents)) {
            throw new \RuntimeException("Could not write {$fileKey} to the bucket");
        }

        return $fileKey;
    }

    /** The bytes of a bucket file; null when it is not there. */
    public function bucketFileContents(string $fileName, string $remoteDirectory): ?string
    {
        $disk = Storage::disk(config('filesystems.storage_service'));
        $fileKey = $this->buildBucketKey($remoteDirectory, $fileName);

        return $disk->exists($fileKey) ? (string) $disk->get($fileKey) : null;
    }

    /** Moves a file to another bucket folder (copy, then delete). If the name exists there, the moved file gets the date and time before ".json". @return string the name in $toDirectory */
    public function moveBucketFile(string $fileName, string $fromDirectory, string $toDirectory): string
    {
        $disk = Storage::disk(config('filesystems.storage_service'));
        $from = $this->buildBucketKey($fromDirectory, $fileName);

        if (!$disk->exists($from)) {
            throw new \RuntimeException("File {$from} not found in the bucket");
        }

        $toFileName = $fileName;
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        for ($attempt = 1; $disk->exists($this->buildBucketKey($toDirectory, $toFileName)); $attempt++) {
            $toFileName = $name . '_' . now()->format('Y-m-d_H-i-s') . ($attempt > 1 ? "_{$attempt}" : '')
                . ($extension === '' ? '' : ".{$extension}");
        }

        if (!$disk->copy($from, $this->buildBucketKey($toDirectory, $toFileName))) {
            throw new \RuntimeException("Could not copy {$from} to {$toDirectory}");
        }

        if (!$disk->delete($from)) {
            Log::warning("Warning in StorageService: moveBucketFile: copied {$from} but could not delete it");
        }

        return $toFileName;
    }

    /** Signed URL for a stored image, valid TEMP_URL_LIFETIME minutes (the bucket is private). */
    public function generateImageTemporaryUrl(string $path): string
    {
        return $this->temporaryUrl($path);
    }

    /** Signed URL for a stored file, valid TEMP_URL_LIFETIME minutes (the bucket is private). */
    public function generateFileTemporaryUrl(string $path): string
    {
        return $this->temporaryUrl($path);
    }

    private function temporaryUrl(string $path): string
    {
        $diskName = config('filesystems.storage_service');
        $disk = Storage::disk($diskName);

        // Only some drivers (s3, minio) can sign a URL, and the Filesystem contract does not declare the method.
        if (!method_exists($disk, 'temporaryUrl')) {
            throw new \RuntimeException("The storage disk \"{$diskName}\" cannot create temporary URLs.");
        }

        return $disk->temporaryUrl(
            $path,
            now()->addMinutes((int) config('filesystems.temp_url_lifetime', 30))
        );
    }
}
