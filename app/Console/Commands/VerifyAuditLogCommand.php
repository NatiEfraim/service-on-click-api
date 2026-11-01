<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyAuditLogCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Check the hash chain of the audit log: a row that was changed or removed afterwards breaks it';

    public function handle(AuditService $auditService): int
    {
        try {
            $result = $auditService->verify();

            if ($result['valid']) {
                $this->info("The audit log is intact: {$result['checked']} rows checked.");

                return self::SUCCESS;
            }

            $this->error("The audit log is BROKEN at row {$result['broken_at']} ({$result['checked']} rows checked).");
            Log::critical("AuditLog: the hash chain is broken at row {$result['broken_at']}.");
            app(StorageService::class)->logToS3();

            return self::FAILURE;
        } catch (\Exception $e) {
            Log::error('VerifyAuditLogCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
