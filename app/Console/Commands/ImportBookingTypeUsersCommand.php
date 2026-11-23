<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;
use App\Services\ImportUsersXlsxService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Base for the four users:import-* commands (IMPORT_BOOKING_TYPE_USERS.md): each one imports its own xlsx from the
 * bucket's import-xlsx folder and gives everyone in it the user_{booking type id} permissions of its booking types
 * plus the common infosec + ICT ones (ImportUsersXlsxService::COMMON_BOOKING_TYPES). The file moves to loaded-xlsx
 * when done and stays put when the run failed.
 */
abstract class ImportBookingTypeUsersCommand extends Command
{
    /** Argument and options shared by every subclass; each subclass puts its own command name in front. */
    public const ARGS = ' {file? : Another file name in the bucket\'s import-xlsx folder (default: the file this command owns)}
        {--dry-run : Run and report, then roll everything back; the file stays in the inbox}
        {--revoke-missing : Remove the file\'s own permissions from users who are not in the file (common ones are never removed)}
        {--force : Do not ask before --revoke-missing, and import even when the exact same file is already in loaded-xlsx}';

    /** @return array<int, BookingTypeEnum> The booking types this file grants on top of the common ones. */
    abstract protected function bookingTypes(): array;

    /** The name of the xlsx this command owns in import-xlsx. */
    abstract protected function defaultFileName(): string;

    public function handle(ImportUsersXlsxService $service): int
    {
        $fileName = trim((string) $this->argument('file')) ?: $this->defaultFileName();
        $dryRun = (bool) $this->option('dry-run');
        $revokeMissing = (bool) $this->option('revoke-missing');
        $force = (bool) $this->option('force');

        if ($revokeMissing && !$dryRun && !$force) {
            $typeNames = implode(', ', array_map(fn (BookingTypeEnum $bookingType): string => $bookingType->name, $this->bookingTypes()));

            if (!$this->confirm("--revoke-missing removes the user permissions of {$typeNames} from everyone who is not in {$fileName}. Continue?")) {
                $this->warn('Stopped. Nothing was changed.');

                return self::FAILURE;
            }
        }

        try {
            $result = $service->importFile($fileName, $this->bookingTypes(), $dryRun, $revokeMissing, $force);

            foreach ($result['messages'] as $message) {
                $this->line($message);
            }

            $this->info($result['summary']);

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error(static::class . ': ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
