<?php

namespace App\Traits;

use App\Models\DigitalHatchSentFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Shared r2b export logic: reads a built file from the bucket and sends it to the FTP, keeping the digital_hatch_sent_files ledger. */
trait HatchExportable
{
    /**
     * Reads $bucketKey from the bucket (minio) and writes it to the digital-hatch FTP at red_to_black_ftp_path. The ledger
     * (digital_hatch_sent_files, content sha256 per destination) skips a file whose exact content already went, so a manual
     * run and the scheduled one never send the same thing twice; $force sends it anyway. Throws on write failure.
     * @return bool the file went now (false = this content was already at the destination and was not sent again)
     */
    protected function send(string $bucketKey, bool $force = false): bool
    {
        $fileContent = (string) Storage::disk(config('filesystems.storage_service'))->get($bucketKey);
        $fileName = basename($bucketKey);
        $destination = $this->sendDestination();
        $sha256 = hash('sha256', $fileContent);

        if (!$force && DigitalHatchSentFile::wasSent($fileName, $sha256, $destination)) {
            Log::info("Digital hatch send of {$fileName} skipped: this exact content already went to the {$destination}.");

            return false;
        }

        $ftpPath = rtrim((string) config('services.digital-hatch.red_to_black_ftp_path'), '/') . '/' . $fileName;

        if (!Storage::disk('digital-hatch')->put($ftpPath, $fileContent)) {
            throw new \RuntimeException("could not put {$fileName} to FTP at {$ftpPath}");
        }

        DigitalHatchSentFile::record($fileName, $sha256, $destination);

        return true;
    }

    /** Remembers in the ledger that this content was written to the bucket for the mobile side. */
    protected function noteBucketWrite(string $fileName, string $json): void
    {
        DigitalHatchSentFile::record($fileName, hash('sha256', $json), DigitalHatchSentFile::DESTINATION_BUCKET);
    }

    /** ftp for a real hatch, local when the digital-hatch disk is a folder (dev / tests). */
    private function sendDestination(): string
    {
        return config('filesystems.disks.digital-hatch.driver') === 'ftp'
            ? DigitalHatchSentFile::DESTINATION_FTP
            : DigitalHatchSentFile::DESTINATION_LOCAL;
    }

    public function exportDestination(): string
    {
        return 'FTP folder ' . rtrim((string) config('services.digital-hatch.red_to_black_ftp_path'), '/') . '/';
    }
}
