<?php

namespace App\Jobs;

use App\Services\DigitalHatchService;

/** importApplicationLogJson:ftp - takes the new application log files from the digital hatch into the bucket, then imports them like importApplicationLogJson:bucket. */
class ImportApplicationLogJsonFtpJob extends ImportJob
{
    /** @param bool $force take the files again even when they were taken before */
    public function __construct(public readonly bool $force = false)
    {
        parent::__construct();
    }

    protected function import(): array
    {
        return app(DigitalHatchService::class)->importApplicationLogs($this->force);
    }
}
