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


# Every command here only queues its job, so a queue worker must be running (php artisan queue:work).

# Reads the booking files waiting in import-json/booking-data, saves their bookings and moves the files to loaded-json - see ImportBookingJsonChunkJob.
Schedule::command(ImportBookingJsonCommand::class)
    ->cron(config('app.jobs.import_booking_json.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

# Same for the application logs in import-json/application-log; a minute after the bookings, because a log belongs to a booking - see ImportApplicationLogJsonChunkJob.
Schedule::command(ImportApplicationLogJsonCommand::class)
    ->cron(config('app.jobs.import_application_log_json.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

# Sends the decisions made here (and the corrected values) back to the mobile app as r2b files in the bucket - see BookingStatusExportService.
# It writes the files itself: no queue worker is needed. (The lock is held for 10 minutes at most: a run that was killed must not stop the
# schedule for the default 24 hours.)
Schedule::command(ExportBookingStatusJsonCommand::class)
    ->cron(config('app.export_json.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);

# Once a night: a row of the audit log that was changed or removed afterwards breaks its hash chain - see VerifyAuditLogCommand.
Schedule::command(VerifyAuditLogCommand::class)
    ->dailyAt('02:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(120);

# An Idempotency-Key only matters for the minutes after a double click; the keys of earlier days are removed.
Schedule::call(fn () => DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(2))->delete())
    ->name('idempotency-keys:prune')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);
