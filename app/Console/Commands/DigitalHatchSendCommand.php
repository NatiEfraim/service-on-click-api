<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Base for the r2b FTP send commands (exportUsersJson:ftp, exportBookingStatusJson:ftp, exportSysRefJson:ftp). */
abstract class DigitalHatchSendCommand extends Command
{
    /** Calls the service and returns its result. */
    abstract protected function runService(bool $force): array;

    /** @return class-string */
    abstract protected function jobClass(): string;

    public function handle(): int
    {
        try {
            if (!config('services.digital-hatch.enabled')) {
                $this->info('Nothing sent: the digital hatch is disabled (HATCH_ENABLED=false).');

                return self::SUCCESS;
            }

            $jobClass = $this->jobClass();
            $force = (bool) $this->option('force');

            if (!(bool) $this->option('sync')) {
                dispatch(new $jobClass($force));
                $this->info(class_basename($jobClass) . ' queued.');

                return self::SUCCESS;
            }

            $result = $this->runService($force);

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    Log::error(static::class . ': ' . $error);
                    $this->error($error);
                }

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error(static::class . ': ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
