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

            return $ran ? $this->runFollowUps($sync) : self::SUCCESS;
        } catch (\Exception $e) {
            Log::error(static::class . ': ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }

    /**
     * Artisan commands a clean, inline (non-queued) run chains into, in order (the booking commands name importFile and then
     * importApplicationLogJson); the chain stops at the first failure. Empty = nothing follows.
     * @return array<int, string>
     */
    protected function followUpCommands(): array
    {
        return [];
    }

    /** Runs the follow-up commands one after the other; the first failing exit code becomes this command's, SUCCESS when all pass. */
    private function runFollowUps(bool $sync): int
    {
        foreach ($this->followUpCommands() as $followUp) {
            $this->info("running the next import ({$followUp})...");

            $exitCode = $this->call($followUp, $sync ? ['--sync' => true] : []);

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        return self::SUCCESS;
    }
}
