<?php

namespace App\Traits;

use Illuminate\Support\Facades\Storage;

/** Shared r2b export logic: reads a built file from the bucket and sends it to the FTP. */
trait HatchExportable
{
    /** Reads $bucketKey from the bucket (minio) and writes it to the digital-hatch FTP at red_to_black_ftp_path. Throws on write failure. */
    protected function send(string $bucketKey): void
    {
        $fileContent = Storage::disk(config('filesystems.storage_service'))->get($bucketKey);
        $fileName = basename($bucketKey);
        $ftpPath = rtrim((string) config('services.digital-hatch.red_to_black_ftp_path'), '/') . '/' . $fileName;

        if (!Storage::disk('digital-hatch')->put($ftpPath, $fileContent)) {
            throw new \RuntimeException("could not put {$fileName} to FTP at {$ftpPath}");
        }
    }

    public function exportDestination(): string
    {
        return 'FTP folder ' . rtrim((string) config('services.digital-hatch.red_to_black_ftp_path'), '/') . '/';
    }
}
