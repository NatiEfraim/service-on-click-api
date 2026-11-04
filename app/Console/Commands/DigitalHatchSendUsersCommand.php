<?php

namespace App\Console\Commands;

use App\Jobs\SendHatchUsersJob;
use App\Traits\DescribesUsersFileRun;
use App\Traits\ExportsHatchUsers;

class DigitalHatchSendUsersCommand extends DigitalHatchSendCommand
{
    use DescribesUsersFileRun;
    use ExportsHatchUsers;

    protected $signature = 'digital-hatch:send-users
        {--force : Send the file again even if this exact content was sent before}
        {--sync : Send it here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Put the people whose roles were not sent yet (users.is_exported_role = 0: new, or granted or stripped of a role here) with their booking types in the day\'s file (SrvOnClick_r2b_users_YYYY-MM-DD.json), send it to the digital hatch FTP, or to DIGITAL_HATCH_EXPORT_PATH when it is set, and mark them sent (is_exported_role = 1)';

    protected function sendToHatch(bool $force, bool $sync): array
    {
        return $this->exportHatchUsers($force, $sync);
    }

    protected function describeBuilt(array $built): string
    {
        return $this->describeUsersFile($built);
    }

    protected function describeMarked(int $marked): ?string
    {
        return $this->describeUsersMarked($marked);
    }

    protected function jobClass(): string
    {
        return SendHatchUsersJob::class;
    }
}
