<?php

namespace App\Traits;

/** The sentences the two users commands (digital-hatch:send-users and exportUsersJson:bucket) print about the day's users file. */
trait DescribesUsersFileRun
{
    /** @param array<string, mixed> $built what UsersExportService::run() returned */
    protected function describeUsersFile(array $built): string
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

    protected function describeUsersMarked(int $marked): string
    {
        return "Marked as exported (is_exported_role = 1): {$marked} users.";
    }
}
