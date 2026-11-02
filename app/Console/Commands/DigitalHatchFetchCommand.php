<?php

namespace App\Console\Commands;

use App\Services\DigitalHatchService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DigitalHatchFetchCommand extends Command
{
    protected $signature = 'digital-hatch:fetch';

    protected $description = 'Take the new b2r files (SrvOnClick_b2r_export_bookings_*, SrvOnClick_b2r_application_logs_*) from the digital hatch FTP into the bucket folders the import commands read';

    public function handle(DigitalHatchService $hatch): int
    {
        try {
            $result = $hatch->fetch();

            if (!$result['enabled']) {
                $this->info('Nothing fetched: the digital hatch is disabled (HATCH_ENABLED=false).');

                return self::SUCCESS;
            }

            if ($result['busy']) {
                $this->info('Nothing fetched: another run is reading the FTP.');

                return self::SUCCESS;
            }

            $count = 0;
            foreach ($result['files'] as $stream => $fileNames) {
                foreach ($fileNames as $fileName) {
                    $this->info("Fetched {$stream}: {$fileName}");
                    $count++;
                }
            }

            if ($count === 0) {
                $this->info('No new file on the FTP.');
            }

            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            Log::info("DigitalHatchFetchCommand: {$count} files fetched, " . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('DigitalHatchFetchCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
