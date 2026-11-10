<?php

namespace App\Traits;

use App\Enums\DigitalHatchFileEnum;
use App\Models\DigitalHatchSentFile;
use App\Services\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Shared r2b export logic: writes bucket files to the FTP (or local test folder), deduplicates by sha256, records every send in digital_hatch_sent_files. */
trait HatchExportable
{
    /** Sends the newest bucket file of this stream to the FTP; skipped when the same content was already sent ($force overrides). Pass $directoryOverride to read from a different bucket folder than the stream's default (e.g. the FTP backup folder). @return array{file: ?string, errors: array<int, string>} */
    public function sendNewestFile(DigitalHatchFileEnum $stream, bool $force = false, ?string $directoryOverride = null): array
    {
        $result = ['file' => null, 'errors' => []];

        try {
            $storage = app(StorageService::class);
            $directory = $directoryOverride ?? $stream->bucketDirectory();
            $fileName = Arr::last($storage->listJsonFilesInBucket('', $directory));

            if ($fileName === null) {
                return $result;
            }

            $contents = $storage->bucketFileContents($fileName, $directory);

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

    /** Writes one file to the FTP (or local test folder) and records it in the ledger. @return bool false when this content was already sent and $force is off */
    protected function send(string $fileName, string $contents, bool $force = false): bool
    {
        $sha256 = hash('sha256', $contents);
        $destination = $this->localExportPath() !== '' ? DigitalHatchSentFile::DESTINATION_LOCAL : DigitalHatchSentFile::DESTINATION_FTP;

        if (!$force && DigitalHatchSentFile::wasSent($fileName, $sha256, $destination)) {
            return false;
        }

        [$disk, $folder] = $this->exportTarget();

        if (!$disk->put($folder . $fileName, $contents)) {
            throw new \RuntimeException("could not put {$fileName} in {$this->exportDestination()}");
        }

        DigitalHatchSentFile::record($fileName, $sha256, $destination);

        return true;
    }

    /** Notes in the ledger that this content of the file was written to the bucket (where the mobile side may read it), as the send notes the FTP. */
    protected function noteWrittenToBucket(string $fileName, string $contents): void
    {
        DigitalHatchSentFile::record($fileName, hash('sha256', $contents), DigitalHatchSentFile::DESTINATION_BUCKET);
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
