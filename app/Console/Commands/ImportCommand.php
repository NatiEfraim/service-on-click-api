<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Base for the six import commands (importBookingJson, importApplicationLogJson, importFile × :bucket / :ftp).
 * Each command does its own step only - nothing chains into anything: the scheduler (routes/console.php) runs the :ftp
 * ones on their own clocks, bookings first, and a file or log whose booking has not arrived yet waits for its next run.
 */
abstract class ImportCommand extends Command
{
    /** Does the import; logs its own details and throws on any failure. @return bool the import ran to the end in this process (false = disabled, queued or another run working) */
    abstract protected function importFrom(bool $force, bool $sync): bool;

    public function handle(): int
    {
        try {
            $this->importFrom($this->hasOption('force') && (bool) $this->option('force'), (bool) $this->option('sync'));

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
