<?php

namespace App\Console\Commands;

use App\Services\BookingStatusExportService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportBookingStatusJsonBucketCommand extends Command
{
    protected $signature = 'exportBookingStatusJson:bucket';

    protected $description = 'Send the decisions made here, the corrected values and the changes of the configuration back to the mobile app: r2b files in the bucket (export-json/booking-status, booking-values, system-config)';

    public function handle(BookingStatusExportService $exportService): int
    {
        try {
            $result = $exportService->run();

            if (!$result['enabled']) {
                $this->info('Nothing sent: the export is disabled (EXPORT_JSON_ENABLED=false).');

                return self::SUCCESS;
            }

            if ($result['busy'] ?? false) {
                $this->info('Nothing sent: another run is writing the files.');

                return self::SUCCESS;
            }

            $status = $result['status'];
            $this->info($status['sent'] ? "Booking status file sent (file {$status['file_id']})." : 'Booking status: nothing to send' . (isset($status['reason']) ? " ({$status['reason']})" : (isset($status['error']) ? ": {$status['error']}" : '')) . '.');
            $this->info("Booking value files sent: {$result['values']['sent']}.");

            $config = $result['config'];
            $this->info("Configuration files sent: {$config['sent']}.");

            // Waiting out a back-off is normal; blocked, failed or out-of-attempts files need a look.
            $needsLook = in_array($config['reason'] ?? null, ['blocked', 'failed', 'needs_manual_retry'], true);

            if (isset($config['reason'])) {
                $this->{$needsLook ? 'error' : 'info'}("Configuration files stopped at file {$config['stopped_at']}: {$config['reason']}" . (($config['error'] ?? '') !== '' ? " ({$config['error']})" : '') . '.');
            }

            Log::info('ExportBookingStatusJsonBucketCommand: status ' . json_encode($status) . ', value files ' . $result['values']['sent'] . ', config ' . json_encode($config) . '.');

            return isset($status['error']) || $needsLook ? self::FAILURE : self::SUCCESS;
        } catch (\Exception $e) {
            Log::error('ExportBookingStatusJsonBucketCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}
