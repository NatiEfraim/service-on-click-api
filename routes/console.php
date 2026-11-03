<?php

use App\Console\Commands\DigitalHatchSendBookingStatusCommand;
use App\Console\Commands\DigitalHatchSendUsersCommand;
use App\Console\Commands\ExportBookingStatusJsonCommand;
use App\Console\Commands\ImportApplicationLogJsonBucketCommand;
use App\Console\Commands\ImportApplicationLogJsonFtpCommand;
use App\Console\Commands\ImportBookingJsonBucketCommand;
use App\Console\Commands\ImportBookingJsonFtpCommand;
use App\Console\Commands\ImportFileBucketCommand;
use App\Console\Commands\ImportFileFtpCommand;
use App\Console\Commands\VerifyAuditLogCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// These commands only queue jobs, so a queue worker must be running.

// r2b: today's users file (people with their roles) goes to the FTP.
Schedule::command(DigitalHatchSendUsersCommand::class)
    ->cron(config('app.jobs.digital_hatch_send_users.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);

// r2b: the booking status file goes to the FTP (a minute after the export wrote it).
Schedule::command(DigitalHatchSendBookingStatusCommand::class)
    ->cron(config('app.jobs.digital_hatch_send_booking_status.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);

// b2r: new booking files are taken from the FTP into the bucket and saved (their rows are queued as chunk jobs).
Schedule::command(ImportBookingJsonFtpCommand::class)
    ->cron(config('app.jobs.digital_hatch_import_booking.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);

// b2r: the same for the application logs, a minute after the bookings because a log belongs to a booking.
Schedule::command(ImportApplicationLogJsonFtpCommand::class)
    ->cron(config('app.jobs.digital_hatch_import_application_log.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);

// b2r: the files and images the bookings name are taken into the bucket and linked to them.
Schedule::command(ImportFileFtpCommand::class)
    ->cron(config('app.jobs.digital_hatch_import_file.cron'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);



