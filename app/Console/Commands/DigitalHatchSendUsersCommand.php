<?php

namespace App\Console\Commands;

use App\Jobs\SendHatchUsersJob;
use App\Traits\ExportsHatchUsers;

class DigitalHatchSendUsersCommand extends DigitalHatchSendCommand
{
    use ExportsHatchUsers;

    protected $signature = 'digital-hatch:send-users
        {--force : Send the file again even if this exact content was sent before}
        {--sync : Send it here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Write today\'s users file (people with their roles and booking types, SrvOnClick_r2b_users_<date>.json) to the bucket and put it on the digital hatch FTP, or in DIGITAL_HATCH_EXPORT_PATH when it is set';

    protected function sendToHatch(bool $force, bool $sync): array
    {
        return $this->exportHatchUsers($force, $sync);
    }

    protected function describeBuilt(array $built): string
    {
        return $built['written']
            ? "Users file written ({$built['users']} users): {$built['file']}."
            : 'Users file: nothing written' . (isset($built['reason']) ? " ({$built['reason']})" : '') . '.';
    }

    protected function jobClass(): string
    {
        return SendHatchUsersJob::class;
    }
}
