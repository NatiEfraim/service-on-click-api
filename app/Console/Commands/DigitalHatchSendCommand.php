<?php

namespace App\Console\Commands;

use App\Services\DigitalHatchService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DigitalHatchSendCommand extends Command
{
    protected $signature = 'digital-hatch:send';

    protected $description = 'Write today\'s users file to the bucket and put the newest r2b files (SrvOnClick_r2b_booking_status_*, SrvOnClick_r2b_users_*) on the digital hatch FTP';

    public function handle(DigitalHatchService $hatch): int
    {
        try {
            $result = $hatch->export();

            if (!$result['enabled']) {
                $this->info('Nothing sent: the digital hatch is disabled (HATCH_ENABLED=false).');

                return self::SUCCESS;
            }

            if ($result['busy']) {
                $this->info('Nothing sent: another run is putting the files on the FTP.');

                return self::SUCCESS;
            }

            $users = $result['users'];
            $this->info($users['written'] ? "Users file written ({$users['users']} users): {$users['file']}." : 'Users file: nothing written' . (isset($users['reason']) ? " ({$users['reason']})" : '') . '.');

            foreach ($result['files'] as $stream => $fileName) {
                $this->info("Sent {$stream}: {$fileName}");
            }

            if ($result['files'] === []) {
                $this->info('Nothing new to send.');
            }

            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            Log::info('DigitalHatchSendCommand: ' . count($result['files']) . ' files sent, ' . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('DigitalHatchSendCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
