<?php

namespace App\Traits;

/** The sentences the two system references commands (digital-hatch:send-system-references and exportSystemReferencesJson:bucket) print about the day's system references file. */
trait DescribesSystemReferencesFileRun
{
    /** @param array<string, mixed> $built what SystemReferencesExportService::run() returned */
    protected function describeSystemReferencesFile(array $built): string
    {
        if ($built['written']) {
            return "System references file written ({$built['records']} waiting, {$built['in_file']} in the day's file): {$built['file']}.";
        }

        return match ($built['reason'] ?? null) {
            'nothing_waiting' => 'System references file: no record is waiting (none has is_exported = 0).',
            'unchanged' => "System references file: {$built['records']} waiting, already in the day's file as they are.",
            default => 'System references file: nothing written.',
        };
    }

    protected function describeSystemReferencesMarked(int $marked): string
    {
        return "Marked as exported (is_exported = 1): {$marked} system references.";
    }
}
