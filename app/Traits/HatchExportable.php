<?php

namespace App\Traits;

use App\Enums\DigitalHatchFileEnum;
use App\Models\DigitalHatchSentFile;
use App\Services\StorageService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sends the r2b files this system wrote to the bucket (booking status, users) to the digital hatch FTP (red_to_black_ftp_path),
 * under the name they have in the bucket: SrvOnClick_r2b_booking_status_<date>.json, SrvOnClick_r2b_users_<date>.json.
 */
trait HatchExportable
{
    /**
     * Puts the newest bucket file of every exportable stream on the FTP, unless that exact content went already. Only the newest:
     * both are cumulative snapshots of one day, so the latest file holds everything an older one did (one that never went is not missed).
     * @return array{files: array<string, string>, errors: array<int, string>} the file name sent, by stream
     */
    public function sendNewFiles(): array
    {
        $result = ['files' => [], 'errors' => []];
        $storage = app(StorageService::class);

        foreach (DigitalHatchFileEnum::exportable() as $stream) {
            try {
                $directory = $stream->bucketDirectory();
                // The date in the name sorts the same as the age.
                $fileName = Arr::last($storage->listJsonFilesInBucket('', $directory));

                if ($fileName === null) {
                    continue;
                }

                $contents = $storage->bucketFileContents($fileName, $directory);

                // Moved or removed since it was listed.
                if ($contents === null) {
                    continue;
                }

                if ($this->send($fileName, $contents)) {
                    $result['files'][$stream->value] = $fileName;
                }
            } catch (\Throwable $e) {
                Log::error("HatchExportable@sendNewFiles {$stream->value} error: {$e->getMessage()}");
                $result['errors'][] = "{$stream->value}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /**
     * Puts one file on the red-to-black folder of the FTP and remembers what was sent.
     * @return bool false when this exact content had been sent before
     */
    protected function send(string $fileName, string $contents): bool
    {
        $sha256 = hash('sha256', $contents);

        if (DigitalHatchSentFile::where('file_name', $fileName)->where('sha256', $sha256)->exists()) {
            return false;
        }

        $path = rtrim((string) config('services.digital-hatch.red_to_black_ftp_path'), '/') . '/';

        if (!Storage::disk('digital-hatch')->put($path . $fileName, $contents)) {
            throw new \RuntimeException("could not put {$fileName} on the FTP");
        }

        DigitalHatchSentFile::create(['file_name' => $fileName, 'sha256' => $sha256, 'sent_at' => now()]);

        return true;
    }
}
