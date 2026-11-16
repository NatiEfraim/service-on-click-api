<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Base for the six import commands (importBookingJson, importApplicationLogJson, importFile × :bucket / :ftp). */
abstract class ImportCommand extends Command
{
    /** Does the import; logs its own details and throws on any failure. @return bool the import ran to the end in this process (false = disabled, queued or another run working) */
    abstract protected function importFrom(bool $force, bool $sync): bool;

    public function handle(): int
    {
        try {
            $sync = (bool) $this->option('sync');
            $ran = $this->importFrom($this->hasOption('force') && (bool) $this->option('force'), $sync);

            return $ran ? $this->runFollowUp($sync) : self::SUCCESS;
        } catch (\Exception $e) {
            Log::error(static::class . ': ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }

    /** Artisan command a clean, inline (non-queued) run chains into (the booking commands name importFile); null = nothing follows. */
    protected function followUpCommand(): ?string
    {
        return null;
    }

    /** Runs the follow-up command when one is declared; its exit code becomes this command's. */
    private function runFollowUp(bool $sync): int
    {
        $followUp = $this->followUpCommand();

        if ($followUp === null) {
            return self::SUCCESS;
        }

        $this->info("importing the attachment files ({$followUp})...");

        return $this->call($followUp, $sync ? ['--sync' => true] : []);
    }
}
