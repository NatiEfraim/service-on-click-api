<?php

use App\Console\Commands\ImportApplicationLogJsonCommand;
use App\Console\Commands\ImportBookingJsonCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
