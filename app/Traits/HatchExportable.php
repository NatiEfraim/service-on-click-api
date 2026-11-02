<?php

namespace App\Traits;

use App\Enums\DigitalHatchFileEnum;
use App\Models\DigitalHatchSentFile;
use App\Services\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sends an r2b file this system wrote to the bucket (booking status, users) to the digital hatch FTP (red_to_black_ftp_path),
 * under the name it has in the bucket: SrvOnClick_r2b_booking_status_<date>.json, SrvOnClick_r2b_users_<date>.json.
 * Each stream has its own service, job and command (UsersExportService, BookingStatusSnapshotService); this is what they share.
 * For local testing DIGITAL_HATCH_EXPORT_PATH names a folder that takes the place of the FTP: the files are written straight into it.
 */
trait HatchExportable
{
    /**
     * Puts the newest bucket file of ONE stream on the FTP, unless that exact content went already. Only the newest: the streams are
     * cumulative snapshots of one day, so the latest file holds everything an older one did (one that never went is not missed).
     * @param bool $force send even when this exact content was sent before (a person deleted it at the other end, or wants it again)
     * @return array{file: ?string, errors: array<int, string>} the file name sent (null: nothing new, or no file yet)
     */
    public function sendNewestFile(DigitalHatchFileEnum $stream, bool $force = false): array
    {
        $result = ['file' => null, 'errors' => []];

        try {
            $storage = app(StorageService::class);
            $directory = $stream->bucketDirectory();
            // The date in the name sorts the same as the age.
            $fileName = Arr::last($storage->listJsonFilesInBucket('', $directory));

            if ($fileName === null) {
                return $result;
            }

            $contents = $storage->bucketFileContents($fileName, $directory);

            // Moved or removed since it was listed.
            if ($contents === null) {
                return $result;
            }

            if ($this->send($fileName, $contents, $force)) {
                $result['file'] = $fileName;
            }
        } catch (\Throwable $e) {
            Log::error("HatchExportable@sendNewestFile {$stream->value} error: {$e->getMessage()}");
            $result['errors'][] = "{$stream->value}: {$e->getMessage()}";
        }

        return $result;
    }

    /** Where the files go, for the command to show: the local test folder or the red-to-black folder of the FTP. */
    public function exportDestination(): string
    {
        $local = $this->localExportPath();

        return $local !== '' ? "local folder {$local}" : 'FTP folder ' . $this->exportTarget()[1];
    }

    /**
     * Puts one file on the red-to-black folder of the FTP (or in the local test folder) and remembers what was sent.
     * @return bool false when this exact content had been sent before (and $force is off)
     */
    protected function send(string $fileName, string $contents, bool $force = false): bool
    {
        $sha256 = hash('sha256', $contents);

        if (!$force && DigitalHatchSentFile::where('file_name', $fileName)->where('sha256', $sha256)->exists()) {
            return false;
        }

        [$disk, $folder] = $this->exportTarget();

        if (!$disk->put($folder . $fileName, $contents)) {
            throw new \RuntimeException("could not put {$fileName} in {$this->exportDestination()}");
        }

        DigitalHatchSentFile::updateOrCreate(['file_name' => $fileName, 'sha256' => $sha256], ['sent_at' => now()]);

        return true;
    }

    /** @return array{0: Filesystem, 1: string} the disk and the folder (empty, or with one slash at the end) the files are written to */
    private function exportTarget(): array
    {
        $local = $this->localExportPath();

        if ($local !== '') {
            // A folder of its own: it is created when missing, and a write that fails throws.
            return [Storage::build(['driver' => 'local', 'root' => $local, 'throw' => true]), ''];
        }

        return [Storage::disk('digital-hatch'), rtrim((string) config('services.digital-hatch.red_to_black_ftp_path'), '/') . '/'];
    }

    private function localExportPath(): string
    {
        return trim((string) config('services.digital-hatch.local.export.path'));
    }
}
