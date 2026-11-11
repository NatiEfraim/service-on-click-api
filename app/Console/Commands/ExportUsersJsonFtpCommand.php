<?php

namespace App\Console\Commands;

use App\Jobs\SendHatchUsersJob;
use App\Services\UsersExportService;

class ExportUsersJsonFtpCommand extends DigitalHatchSendCommand
{
    protected $signature = 'digital-hatch:send-users {--force} {--sync}';

    protected $description = 'Send the r2b users file to the digital hatch FTP.';

    protected function runService(bool $force): array
    {
        return app(UsersExportService::class)->export($force);
    }

    protected function jobClass(): string
    {
        return SendHatchUsersJob::class;
    }
}
