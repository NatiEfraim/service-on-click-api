<?php

namespace App\Console\Commands;

use App\Jobs\SendHatchBookingStatusJob;
use App\Services\BookingStatusSnapshotService;

class ExportBookingStatusJsonFtpCommand extends DigitalHatchSendCommand
{
    protected $signature = 'exportBookingStatusJson:ftp {--force} {--sync}';

    protected $description = 'Send the r2b booking status file to the digital hatch FTP.';

    protected function runService(bool $force): array
    {
        return app(BookingStatusSnapshotService::class)->export($force);
    }

    protected function jobClass(): string
    {
        return SendHatchBookingStatusJob::class;
    }
}
