<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;
use App\Services\ImportUsersXlsxService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Base for the four *:xlsx user imports (IMPORT_BOOKING_TYPE_USERS.md): importCarBooking:xlsx, importCarMaintenanceBooking:xlsx,
 * importInUnitFoodBooking:xlsx and importUser:xlsx. Each one imports its own file from the bucket's import-xlsx folder and makes it
 * the full list of its booking types: everyone in the file gets their user_{booking type id} permissions plus the common
 * infosec + ICT ones (ImportUsersXlsxService::COMMON_BOOKING_TYPES), and everyone else loses the file's own permissions
 * (never the common ones). The file moves to loaded-xlsx when done and stays put when the run failed.
 */
abstract class ImportBookingTypeUsersCommand extends Command
{
    /** Argument and options shared by every subclass; each subclass puts its own command name in front. */
    public const ARGS = ' {file? : Another file name in the bucket\'s import-xlsx folder (default: the file this command owns)}
        {--dry-run : Run and report, then roll everything back; the file stays in import-xlsx}
        {--force : Import even when the exact same file is already in loaded-xlsx}';

    /** @return array<int, BookingTypeEnum> The booking types only the people in this file keep, on top of the common ones. */
    abstract protected function bookingTypes(): array;

    /** The name of the xlsx this command owns in import-xlsx. */
    abstract protected function defaultFileName(): string;

    public function handle(ImportUsersXlsxService $service): int
    {
        $fileName = trim((string) $this->argument('file')) ?: $this->defaultFileName();

        try {
            $result = $service->importFile(
                $fileName,
                $this->bookingTypes(),
                (bool) $this->option('dry-run'),
                (bool) $this->option('force'),
            );

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
