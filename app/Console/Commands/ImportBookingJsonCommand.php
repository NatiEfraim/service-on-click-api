<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use App\Traits\ImportsBookingJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportBookingJsonCommand extends Command
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:mysql';

    protected $description = 'Read the booking files waiting in the bucket (import-json/booking-data), queue their rows chunk by chunk to save the bookings, and move the files to loaded-json';

    public function handle(): int
    {
        try {
            $result = $this->importBookingJson();

            foreach ($result['files'] as $file) {
                $this->info("{$file['file']}: {$file['rows']} bookings in {$file['chunks']} chunks queued, file moved to {$file['moved_to']}");
            }

            foreach ($result['errors'] as $error) {
                $this->error("{$error['file']}: {$error['message']}");
            }

            if (($result['disabled'] ?? false) || $result['busy'] || ($result['files'] === [] && $result['errors'] === [])) {
                $this->info('Nothing to import (disabled, already running, or no booking file is waiting).');
            }

            Log::info('ImportBookingJsonCommand: ' . count($result['files']) . ' files queued, ' . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('ImportBookingJsonCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
