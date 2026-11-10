<?php

namespace App\Console\Commands;

use App\Jobs\SendHatchSystemReferencesJob;
use App\Traits\DescribesSystemReferencesFileRun;
use App\Traits\ExportsHatchSystemReferences;

class DigitalHatchSendSystemReferencesCommand extends DigitalHatchSendCommand
{
    use DescribesSystemReferencesFileRun;
    use ExportsHatchSystemReferences;

    protected $signature = 'digital-hatch:send-system-references
        {--force : Send the file again even if this exact content was sent before}
        {--sync : Send it here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Put the system references whose values were not sent yet (system_references.is_exported = 0: new, or updated) in the day\'s file (SrvOnClick_r2b_system_references_YYYY-MM-DD.json), send it to the digital hatch FTP, or to DIGITAL_HATCH_EXPORT_PATH when it is set, and mark them sent (is_exported = 1)';

    protected function sendToHatch(bool $force, bool $sync): array
    {
        return $this->exportHatchSystemReferences($force, $sync);
    }

    protected function describeBuilt(array $built): string
    {
        return $this->describeSystemReferencesFile($built);
    }

    protected function describeMarked(int $marked): ?string
    {
        return $this->describeSystemReferencesMarked($marked);
    }

    protected function jobClass(): string
    {
        return SendHatchSystemReferencesJob::class;
    }
}
