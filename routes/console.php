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

// r2b: today's users file (people with their roles) goes to the FTP — every 20 minutes.
// Schedule::command(DigitalHatchSendUsersCommand::class)
//     ->everyFiveMinutes()
//     ->timezone(config('app.timezone'))
//     ->onOneServer()
//     ->withoutOverlapping(10);

// r2b: the booking status file goes to the FTP — every 30 minutes.
// Schedule::command(DigitalHatchSendBookingStatusCommand::class)
//     ->everyThirtyMinutes()
//     ->timezone(config('app.timezone'))
//        ->onOneServer()
//     ->withoutOverlapping(10);

// b2r: new booking files are taken from the FTP into the bucket and saved — every 30 seconds.
// withoutOverlapping(1): cron syntax is minute-level; 1 minute is the shortest usable lock.
// Schedule::command(ImportBookingJsonFtpCommand::class)
//     ->everyThirtySeconds()
//     ->timezone(config('app.timezone'))
//        ->onOneServer()
//     ->withoutOverlapping(1);

// b2r: application logs, after the bookings because a log belongs to a booking — every 30 seconds.
// Schedule::command(ImportApplicationLogJsonFtpCommand::class)
//     ->everyThirtySeconds()
//     ->timezone(config('app.timezone'))
//        ->onOneServer()
//     ->withoutOverlapping(1);

// b2r: the files and images the bookings name are taken into the bucket and linked — every 30 seconds.
// Schedule::command(ImportFileFtpCommand::class)
//     ->everyThirtySeconds()
//     ->timezone(config('app.timezone'))
//     ->onOneServer()
//     ->withoutOverlapping(1);



