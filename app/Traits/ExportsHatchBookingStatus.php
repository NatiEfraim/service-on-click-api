<?php

namespace App\Traits;

use App\Jobs\SendHatchBookingStatusJob;
use App\Services\BookingStatusSnapshotService;
use Illuminate\Support\Facades\Log;

trait ExportsHatchBookingStatus
{
    /** Sends the booking status file to the digital hatch; queues SendHatchBookingStatusJob or runs inline with $sync. @return array{enabled: bool, queued?: bool, result?: array<string, mixed>} */
    protected function exportHatchBookingStatus(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Booking status file send is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => app(BookingStatusSnapshotService::class)->export($force)];
        }

        dispatch(new SendHatchBookingStatusJob($force));

        return ['enabled' => true, 'queued' => true];
    }
}
