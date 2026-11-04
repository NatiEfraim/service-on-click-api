<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Base for digital-hatch:send-booking-status and digital-hatch:send-users. */
abstract class DigitalHatchSendCommand extends Command
{
    /** @return array{enabled: bool, queued?: bool, result?: array<string, mixed>} */
    abstract protected function sendToHatch(bool $force, bool $sync): array;

    /** @param array<string, mixed> $built */
    abstract protected function describeBuilt(array $built): string;

    /** @return class-string */
    abstract protected function jobClass(): string;

    /** Override to describe how many rows were marked exported; null = no flag for this stream. */
    protected function describeMarked(int $marked): ?string
    {
        return null;
    }

    public function handle(): int
    {
        try {
            $outcome = $this->sendToHatch((bool) $this->option('force'), (bool) $this->option('sync'));

            if (!$outcome['enabled']) {
                $this->info('Nothing sent: the digital hatch is disabled (HATCH_ENABLED=false).');

                return self::SUCCESS;
            }

            if ($outcome['queued']) {
                $this->info(class_basename($this->jobClass()) . ' queued: a queue worker (php artisan queue:work) sends it. Add --sync to send it here and see the result.');

                return self::SUCCESS;
            }

            $result = $outcome['result'];

            if ($result['busy']) {
                $this->info('Nothing sent: another run is putting this file on the FTP.');

                return self::SUCCESS;
            }

            $this->info("Destination: {$result['destination']}");
            $this->info($this->describeBuilt($result['built']));
            $this->info($result['file'] !== null ? "Sent: {$result['file']}" : 'Nothing new to send (the same content was sent before; --force sends it again).');

            if (isset($result['marked']) && ($marked = $this->describeMarked($result['marked'])) !== null) {
                $this->info($marked);
            }

            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            Log::info(static::class . ': ' . ($result['file'] !== null ? '1 file sent' : 'nothing sent') . ', ' . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error(static::class . ': failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
