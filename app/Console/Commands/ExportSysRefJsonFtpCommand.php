<?php

namespace App\Console\Commands;

use App\Jobs\SendHatchSystemReferencesJob;
use App\Services\SystemReferencesExportService;

class ExportSysRefJsonFtpCommand extends DigitalHatchSendCommand
{
    protected $signature = 'digital-hatch:send-sys-ref {--force} {--sync}';

    protected $description = 'Send the r2b system references file to the digital hatch FTP.';

    protected function runService(bool $force): array
    {
        return app(SystemReferencesExportService::class)->export($force);
    }

    protected function jobClass(): string
    {
        return SendHatchSystemReferencesJob::class;
    }
}
