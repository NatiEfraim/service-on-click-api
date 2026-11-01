<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use App\Traits\ImportsApplicationLogJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportApplicationLogJsonCommand extends Command
{
    use ImportsApplicationLogJson;

    protected $signature = 'importApplicationLogJson:mysql';

    protected $description = 'Read the application log files waiting in the bucket (import-json/application-log), queue their rows chunk by chunk to save the logs, and move the files to loaded-json';

    public function handle(): int
    {
        try {
            $result = $this->importApplicationLogJson();

            foreach ($result['files'] as $file) {
                $this->info("{$file['file']}: {$file['rows']} logs in {$file['chunks']} chunks queued, file moved to {$file['moved_to']}");
            }

            foreach ($result['errors'] as $error) {
                $this->error("{$error['file']}: {$error['message']}");
            }

            if (($result['disabled'] ?? false) || $result['busy'] || ($result['files'] === [] && $result['errors'] === [])) {
                $this->info('Nothing to import (disabled, already running, or no application log file is waiting).');
            }

            Log::info('ImportApplicationLogJsonCommand: ' . count($result['files']) . ' files queued, ' . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('ImportApplicationLogJsonCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
