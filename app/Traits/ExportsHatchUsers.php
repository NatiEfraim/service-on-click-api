<?php

namespace App\Traits;

use App\Jobs\SendHatchUsersJob;
use App\Services\UsersExportService;
use Illuminate\Support\Facades\Log;

trait ExportsHatchUsers
{
    /** Sends the users file to the digital hatch; queues SendHatchUsersJob or runs inline with $sync. @return array{enabled: bool, queued?: bool, result?: array<string, mixed>} */
    protected function exportHatchUsers(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Users file send is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => app(UsersExportService::class)->export($force)];
        }

        dispatch(new SendHatchUsersJob($force));

        return ['enabled' => true, 'queued' => true];
    }
}
