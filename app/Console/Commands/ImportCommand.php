<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Base for the six import commands (importBookingJson, importApplicationLogJson, importFile × :bucket / :ftp). */
abstract class ImportCommand extends Command
{
    /** @return array{enabled: bool, switch?: string, queued?: bool, job?: class-string, result?: array<string, mixed>} */
    abstract protected function importFrom(bool $force, bool $sync): array;

    protected function modelType(): ?JsonModelTypeEnum
    {
        return null;
    }

    public function handle(): int
    {
        try {
            $sync = (bool) $this->option('sync');
            $outcome = $this->importFrom($this->hasOption('force') && (bool) $this->option('force'), $sync);

            if (!$outcome['enabled'] || ($outcome['queued'] ?? false)) {
                return self::SUCCESS;
            }

            $result = $outcome['result'];

            if ($result['busy'] ?? false) {
                return self::SUCCESS;
            }

            $errors = $this->collectErrors($result);

            foreach ($errors as $error) {
                Log::error(static::class . ': ' . $error);
                $this->error($error);
            }

            if ($errors !== []) {
                return self::FAILURE;
            }

            return $this->runFollowUp($sync);
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

    /** @return array<int, string> */
    private function collectErrors(array $result): array
    {
        $errors = isset($result['source']) ? ($result['errors'] ?? []) : [];

        $imported = $result['imported'] ?? null;
        if ($imported === null) {
            return $errors;
        }

        if (isset($imported['in_bucket'])) {
            return [...$errors, ...$imported['errors']];
        }

        foreach ($imported['files'] ?? [] as $file) {
            foreach ($file['errors'] as $error) {
                $errors[] = "{$file['file']}: {$error}";
            }
        }

        foreach ($imported['errors'] ?? [] as $error) {
            $errors[] = "{$error['file']}: {$error['message']}";
        }

        return $errors;
    }
}
