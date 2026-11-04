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

    protected $description = 'Put the people whose roles were not sent yet (users.is_exported_role = 0: new, or granted or stripped of a role here) with their booking types in the day\'s file (SrvOnClick_r2b_users_<date>.json), send it to the digital hatch FTP, or to DIGITAL_HATCH_EXPORT_PATH when it is set, and mark them sent (is_exported_role = 1)';

    protected function sendToHatch(bool $force, bool $sync): array
    {
        return $this->exportHatchUsers($force, $sync);
    }

    protected function describeBuilt(array $built): string
    {
        if ($built['written']) {
            return "Users file written ({$built['users']} waiting, {$built['in_file']} in the day's file): {$built['file']}.";
        }

        return match ($built['reason'] ?? null) {
            'nothing_waiting' => 'Users file: no person is waiting (none has is_exported_role = 0 and a role, an ID number and is not deleted).',
            'unchanged' => "Users file: {$built['users']} waiting, already in the day's file as they are.",
            default => 'Users file: nothing written.',
        };
    }

    protected function describeMarked(int $marked): ?string
    {
        return "Marked as exported (is_exported_role = 1): {$marked} users.";
    }

    protected function jobClass(): string
    {
        return SendHatchUsersJob::class;
    }
}
