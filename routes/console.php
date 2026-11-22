<?php

use App\Console\Commands\AdvanceWorkflowCommand;
use App\Console\Commands\ExportBookingStatusJsonFtpCommand;
use App\Console\Commands\ExportUsersJsonFtpCommand;
use App\Console\Commands\ImportBookingJsonFtpCommand;
use Illuminate\Support\Facades\Schedule;

// Route types (the clearance grant): a request whose interview moment has passed moves on, and - when CLEARANCE_AUTO_CLOSE is on - a request
// the clearance registry confirms is closed. Runs in the process itself (no queue worker needed).
Schedule::command(AdvanceWorkflowCommand::class)
    ->everyFiveMinutes()
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping(10);

// Digital hatch. Every command below does nothing while HATCH_ENABLED=false, and a queue worker must be running:
// the r2b sends only queue a job, and the b2r import queues its chunk jobs.

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
    ->withoutOverlapping(30);

// b2r: the booking json files are taken from the FTP into the bucket and imported - every 15 minutes.
// A clean run chains, in this order: importBookingJson:ftp -> importFile:ftp -> importApplicationLogJson:ftp
// (ImportBookingJsonFtpCommand::followUpCommands), so the files and the logs are not scheduled on their own.
Schedule::command(ImportBookingJsonFtpCommand::class)
    ->everyFifteenMinutes()
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->withoutOverlapping(15);
