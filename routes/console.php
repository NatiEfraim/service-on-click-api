<?php

use App\Console\Commands\AdvanceWorkflowCommand;
use App\Console\Commands\ExportBookingStatusJsonFtpCommand;
use App\Console\Commands\ExportUsersJsonFtpCommand;
use App\Console\Commands\ImportApplicationLogJsonFtpCommand;
use App\Console\Commands\ImportBookingJsonFtpCommand;
use App\Console\Commands\ImportFileFtpCommand;
use Illuminate\Support\Facades\Schedule;

// Route types (the clearance grant): a request whose interview moment has passed moves on, and - when CLEARANCE_AUTO_CLOSE is on - a request
// the clearance registry confirms is closed. Runs in the process itself (no queue worker needed).
Schedule::command(AdvanceWorkflowCommand::class)
    ->everyFiveMinutes()
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping(10);



// r2b: the users file (people with their roles) goes to the FTP - every 15 minutes.
Schedule::command(ExportUsersJsonFtpCommand::class)
    ->everyFifteenMinutes()
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping(15);

// r2b: the booking status file goes to the FTP - every 30 minutes.
Schedule::command(ExportBookingStatusJsonFtpCommand::class)
    ->everyThirtyMinutes()
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping(15);

// b2r: each import runs on its own clock, nothing chains. The bookings come most often; the files and the logs
// belong to bookings, so their 20-minute slots make sure the bookings are already loaded and saved, and one that
// arrives before its booking simply waits for its next run.

// b2r: the booking json files are taken from the FTP into the bucket and imported - every 15 minutes.
Schedule::command(ImportBookingJsonFtpCommand::class)
    ->everyFifteenMinutes()
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping(15);

// b2r: the attachment files - every 20 minutes (defined before the logs: in a shared minute the files run first).
Schedule::command(ImportFileFtpCommand::class)
    ->cron('*/20 * * * *')
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping(15);

// b2r: the application logs - every 20 minutes, after the files; a log belongs to a booking that is already saved.
Schedule::command(ImportApplicationLogJsonFtpCommand::class)
    ->cron('*/20 * * * *')
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping(15);
