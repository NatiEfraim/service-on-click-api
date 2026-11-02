<?php

namespace App\Services;

use App\Traits\HatchExportable;
use App\Traits\HatchImportable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The digital hatch: the FTP the mobile side exchanges files through. The bucket stays this system's working area -
 *  - export (r2b): today's users file is written to the bucket, then the newest booking status and users files are put on the FTP;
 *  - fetch (b2r): new booking / application log files are taken from the FTP into the bucket folders the importers read;
 *  - fetch attachments: the files and images named by the bookings are taken into the folder AttachmentService reads.
 * Each run is serialized by a lock, so the scheduler and a manual run cannot overlap. HATCH_ENABLED=false turns all three off.
 */
class DigitalHatchService
{
    use HatchExportable, HatchImportable;

    public function __construct(private readonly UsersExportService $usersExport)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('services.digital-hatch.enabled');
    }

    /**
     * @return array{enabled: bool, busy?: bool, users?: array<string, mixed>, files?: array<string, string>, errors?: array<int, string>}
     *         users = what the users file step did, files = the name sent by stream
     */
    public function export(): array
    {
        return $this->locked('export', function (): array {
            $errors = [];

            // A failing users file must not hold back the status file.
            try {
                $users = $this->usersExport->run();
            } catch (\Throwable $e) {
                Log::error("DigitalHatchService@export users file error: {$e->getMessage()}");
                $users = ['written' => false];
                $errors[] = "users file: {$e->getMessage()}";
            }

            $sent = $this->sendNewFiles();

            return ['users' => $users, 'files' => $sent['files'], 'errors' => [...$errors, ...$sent['errors']]];
        });
    }

    /** @return array{enabled: bool, busy?: bool, files?: array<string, array<int, string>>, errors?: array<int, string>} the names landed, by stream */
    public function fetch(): array
    {
        return $this->locked('fetch', fn (): array => $this->downloadFiles());
    }

    /** @return array{enabled: bool, busy?: bool, files?: array<int, string>, errors?: array<int, string>} the names landed */
    public function fetchAttachments(): array
    {
        return $this->locked('fetch-attachments', fn (): array => $this->downloadAttachments());
    }

    /** @param callable(): array<string, mixed> $work @return array<string, mixed> */
    private function locked(string $name, callable $work): array
    {
        if (!$this->enabled()) {
            return ['enabled' => false];
        }

        $lock = Cache::lock("digital-hatch:{$name}", 600);

        if (!$lock->get()) {
            Log::info("Digital hatch {$name} skipped: another run is working.");

            return ['enabled' => true, 'busy' => true];
        }

        try {
            return ['enabled' => true, 'busy' => false, ...$work()];
        } finally {
            $lock->release();
        }
    }
}
