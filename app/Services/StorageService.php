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
