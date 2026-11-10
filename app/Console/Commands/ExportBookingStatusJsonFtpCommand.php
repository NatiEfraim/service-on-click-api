<?php

namespace App\Console\Commands;

use App\Jobs\SendHatchBookingStatusJob;
use App\Traits\ExportsHatchBookingStatus;

class ExportBookingStatusJsonFtpCommand extends DigitalHatchSendCommand
{
    use ExportsHatchBookingStatus;

    protected $signature = 'exportBookingStatusJson:ftp
        {--force : Send the file again even if this exact content was sent before}
        {--sync : Send it here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Put the bookings whose status was changed here and not sent yet (bookings.is_status_exported = 0) in the day\'s file (SrvOnClick_r2b_booking_status_<date>.json), send it to the digital hatch FTP, or to DIGITAL_HATCH_EXPORT_PATH when it is set, and mark them sent (is_status_exported = 1)';

    protected function sendToHatch(bool $force, bool $sync): array
    {
        return $this->exportHatchBookingStatus($force, $sync);
    }

    protected function describeBuilt(array $built): string
    {
        if ($built['written']) {
            return "Booking status file written ({$built['bookings']} waiting, {$built['in_file']} in the day's file): {$built['file']}.";
        }

        return match ($built['reason'] ?? null) {
            'nothing_waiting' => 'Booking status file: no booking is waiting (none has is_status_exported = 0).',
            'unchanged' => "Booking status file: {$built['bookings']} waiting, already in the day's file as it is.",
            default => 'Booking status file: nothing written.',
        };
    }

    protected function describeMarked(int $marked): ?string
    {
        return "Marked as exported (is_status_exported = 1): {$marked} bookings.";
    }

    protected function jobClass(): string
    {
        return SendHatchBookingStatusJob::class;
    }
}
