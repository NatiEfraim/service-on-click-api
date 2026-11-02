<?php

namespace App\Console\Commands;

use App\Services\DigitalHatchService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DigitalHatchFetchAttachmentsCommand extends Command
{
    protected $signature = 'digital-hatch:fetch-attachments';

    protected $description = 'Take the new attachments (SrvOnClickFiles_<stored name>.<ext>) from the digital hatch FTP into the bucket folder the bookings read their files and images from';

    public function handle(DigitalHatchService $hatch): int
    {
        try {
            $result = $hatch->fetchAttachments();

            if (!$result['enabled']) {
                $this->info('Nothing fetched: the digital hatch is disabled (HATCH_ENABLED=false).');

                return self::SUCCESS;
            }

            if ($result['busy']) {
                $this->info('Nothing fetched: another run is reading the FTP.');

                return self::SUCCESS;
            }

            foreach ($result['files'] as $fileName) {
                $this->info("Fetched {$fileName}");
            }

            if ($result['files'] === []) {
                $this->info('No new attachment on the FTP.');
            }

            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            Log::info('DigitalHatchFetchAttachmentsCommand: ' . count($result['files']) . ' files fetched, ' . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('DigitalHatchFetchAttachmentsCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
