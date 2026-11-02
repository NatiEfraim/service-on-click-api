<?php

namespace App\Jobs;

use App\Services\DigitalHatchService;

/** importFile:ftp - takes the new files and images from the digital hatch into the bucket folder, then links them like importFile:bucket. */
class ImportFileFtpJob extends ImportJob
{
    /** @param bool $force take the files again even when they were taken before */
    public function __construct(public readonly bool $force = false)
    {
        parent::__construct();
    }

    protected function import(): array
    {
        return app(DigitalHatchService::class)->importFiles($this->force);
    }
}
