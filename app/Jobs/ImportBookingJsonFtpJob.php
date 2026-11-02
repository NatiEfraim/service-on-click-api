<?php

namespace App\Jobs;

use App\Services\DigitalHatchService;

/** importBookingJson:ftp - takes the new booking files from the digital hatch into the bucket, then imports them like importBookingJson:bucket. */
class ImportBookingJsonFtpJob extends ImportJob
{
    /** @param bool $force take the files again even when they were taken before */
    public function __construct(public readonly bool $force = false)
    {
        parent::__construct();
    }

    protected function import(): array
    {
        return app(DigitalHatchService::class)->importBookings($this->force);
    }
}
