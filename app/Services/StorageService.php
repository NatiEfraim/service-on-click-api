<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only place that talks to the bucket. The disk is chosen by STORAGE_SERVICE:
 * "minio" on local, "s3" on the servers (see config/filesystems.php).
 */
class StorageService
{
    /** Upload storage/logs/laravel.log to the bucket's logs folder; with $error the log file is first reset to just that error. @param \Throwable|string|null $error @return int HTTP status code */
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

            // Outside prod the bucket name is part of the object key itself.
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

    /** Overwrite laravel.log with just this error so the next upload has only it. @param string $localPath, \Throwable|string $error */
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

    /** Delete a previously uploaded image. @param string $path @return bool */
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

    /** Delete a previously uploaded file (works for any file type). @param string $path @return bool */
    public function deleteFile(string $path): bool
    {
        return $this->deleteImage($path);
    }

    /** The object key of a file in a bucket folder - outside prod the bucket name is part of the key itself (same rule as logToS3). @param string $remoteDirectory, string $fileName @return string */
    public function buildBucketKey(string $remoteDirectory, string $fileName = ''): string
    {
        $diskName = config('filesystems.storage_service');
        $prefixBucket = !config('app.prod_stage') ? config("filesystems.disks.{$diskName}.bucket") . '/' : '';

        return $prefixBucket . trim($remoteDirectory, '/') . ($fileName === '' ? '' : '/' . $fileName);
    }

    /** Names of the .json files in a bucket folder that start with $fileNamePrefix, oldest first (the date in the name sorts the same way). Throws when the disk refuses the listing. @param string $fileNamePrefix, string $remoteDirectory @return array<int, string> */
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

    /** Read and decode a bucket JSON file; throws if it is missing, empty or not valid JSON. @param string $fileName, string $remoteDirectory @return array */
    public function readJsonFromBucket(string $fileName, string $remoteDirectory): array
    {
        $disk = Storage::disk(config('filesystems.storage_service'));
        $fileKey = $this->buildBucketKey($remoteDirectory, $fileName);

        if (!$disk->exists($fileKey)) {
            throw new \RuntimeException("JSON file {$fileKey} not found in the bucket");
        }

        // Remove the UTF-8 BOM if the uploaded JSON file contains it.
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

    /** Write a JSON file into a bucket folder, replacing the one with that name. Throws when the disk refuses. @param string $fileName, string $remoteDirectory, string $contents @return string the object key written */
    public function putJsonToBucket(string $fileName, string $remoteDirectory, string $contents): string
    {
        $fileKey = $this->buildBucketKey($remoteDirectory, $fileName);

        if (!Storage::disk(config('filesystems.storage_service'))->put($fileKey, $contents)) {
            throw new \RuntimeException("Could not write {$fileKey} to the bucket");
        }

        return $fileKey;
    }

    /** Move a file to another bucket folder (copy, then delete the original). A file with that name already there stays: the moved one gets the date and time before ".json". @param string $fileName, string $fromDirectory, string $toDirectory @return string the name the file has in $toDirectory */
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

    /** A signed URL for a stored image that expires after TEMP_URL_LIFETIME minutes (the bucket is private). @param string $path @return string */
    public function generateImageTemporaryUrl(string $path): string
    {
        return $this->temporaryUrl($path);
    }

    /** A signed URL for a stored file that expires after TEMP_URL_LIFETIME minutes (the bucket is private). @param string $path @return string */
    public function generateFileTemporaryUrl(string $path): string
    {
        return $this->temporaryUrl($path);
    }

    private function temporaryUrl(string $path): string
    {
        return Storage::disk(config('filesystems.storage_service'))->temporaryUrl(
            $path,
            now()->addMinutes((int) config('filesystems.temp_url_lifetime', 30))
        );
    }
}
