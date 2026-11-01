<?php

use App\Console\Commands\ExportBookingStatusJsonCommand;
use App\Console\Commands\ImportApplicationLogJsonCommand;
use App\Console\Commands\ImportBookingJsonCommand;
use App\Console\Commands\VerifyAuditLogCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// These commands only queue jobs, so a queue worker must be running.

// Booking files in import-json/booking-data (ImportBookingJsonChunkJob).
Schedule::command(ImportBookingJsonCommand::class)
    ->cron(config('app.jobs.import_booking_json.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

// Application logs; a minute after the bookings because a log belongs to a booking.
Schedule::command(ImportApplicationLogJsonCommand::class)
    ->cron(config('app.jobs.import_application_log_json.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

// Sends decisions back to the mobile app as r2b files (BookingStatusExportService).
// Writes files itself (no queue worker); the lock expires after 10 minutes so a killed run cannot block the schedule.
Schedule::command(ExportBookingStatusJsonCommand::class)
    ->cron(config('app.export_json.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);

// Nightly: a changed or removed audit row breaks its hash chain.
Schedule::command(VerifyAuditLogCommand::class)
    ->dailyAt('02:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(120);

// Only the minutes after a double click matter; older keys are removed.
Schedule::call(fn () => DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(2))->delete())
    ->name('idempotency-keys:prune')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);
