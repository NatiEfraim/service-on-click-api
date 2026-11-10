<?php

namespace App\Services;

use App\Domain\Sync\ExportFileNames;
use App\Enums\DigitalHatchFileEnum;
use App\Enums\SystemReferenceEnum;
use App\Models\SystemReference;
use App\Traits\GuardsDigitalHatchRuns;
use App\Traits\HatchExportable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** r2b system-references stream: writes a cumulative daily delta file to the bucket and sends it to the FTP; driven by system_references.is_exported. */
class SystemReferencesExportService
{
    use GuardsDigitalHatchRuns, HatchExportable;

    public function __construct(private readonly StorageService $storageService)
    {
    }

    /** Builds today's system references file and sends it to the FTP. @param bool $force re-send even if this content was already sent @return array{enabled: bool, busy?: bool, destination?: string, built?: array<string, mixed>, file?: ?string, marked?: int, errors?: array<int, string>} */
    public function export(bool $force = false): array
    {
        return $this->locked('send-system-references', function () use ($force): array {
            $errors = [];
            $waiting = [];

            $ftpDir = DigitalHatchFileEnum::SYSTEM_REFERENCES->ftpBucketDirectory();

            $built = $this->building('system references file', function () use (&$waiting, $ftpDir): array {
                return $this->run($waiting, $ftpDir);
            }, $errors);
            $sent = $this->sendNewestFile(DigitalHatchFileEnum::SYSTEM_REFERENCES, $force, $ftpDir);
            $errors = [...$errors, ...$sent['errors']];

            $marked = $errors === [] ? SystemReference::markExported($waiting) : 0;

            return ['destination' => $this->exportDestination(), 'built' => $built, 'file' => $sent['file'], 'marked' => $marked, 'errors' => $errors];
        });
    }

    /** Writes the day's file to the bucket; use exportSysRefJson:ftp to also send it to the FTP. @return array{skipped?: string, built?: array<string, mixed>, marked?: int, errors?: array<int, string>} */
    public function exportToBucket(): array
    {
        if (!config('app.export_json.enabled', true)) {
            return ['skipped' => 'export_json_disabled'];
        }

        $lock = Cache::lock('digital-hatch:send-system-references', 600);

        if (!$lock->get()) {
            Log::info('Digital hatch system references export to the bucket skipped: another run is working.');

            return ['skipped' => 'busy'];
        }

        try {
            $errors = [];
            $waiting = [];

            $built = $this->building('system references file', function () use (&$waiting): array {
                return $this->run($waiting);
            }, $errors);

            $marked = $errors === [] ? SystemReference::markExported($waiting) : 0;

            return ['built' => $built, 'marked' => $marked, 'errors' => $errors];
        } finally {
            $lock->release();
        }
    }

    /** Builds the day's system references file in the bucket, merging with what earlier runs put there. @param array<int, ?string> $waiting filled: record id => updated_at @param ?string $directory bucket folder to use; null = the stream's default bucket directory @return array{written: bool, file?: string, records?: int, in_file?: int, reason?: string} */
    public function run(array &$waiting = [], ?string $directory = null): array
    {
        $rows = $this->payload($waiting);

        if ($rows === []) {
            return ['written' => false, 'reason' => 'nothing_waiting'];
        }

        $fileName = ExportFileNames::systemReferencesFile(now(config('app.export_json.timezone')), DigitalHatchFileEnum::SYSTEM_REFERENCES->fileNamePrefix());
        $directory ??= DigitalHatchFileEnum::SYSTEM_REFERENCES->bucketDirectory();
        $existing = $this->storageService->bucketFileContents($fileName, $directory);

        $inFile = [];
        foreach ((json_decode((string) $existing, true)['system_references'] ?? []) as $row) {
            if (is_array($row) && isset($row['booking_type_id'], $row['name'])) {
                $unitKey = $row['unit_id'] ?? 0;
                $inFile[$row['booking_type_id'] . '_' . $unitKey . '_' . $row['name']] = $row;
            }
        }

        // A record updated again replaces its row.
        foreach ($rows as $row) {
            $unitKey = $row['unit_id'] ?? 0;
            $inFile[$row['booking_type_id'] . '_' . $unitKey . '_' . $row['name']] = $row;
        }

        $refs = array_values($inFile);
        usort($refs, fn (array $a, array $b): int => $a['booking_type_id'] <=> $b['booking_type_id'] ?: strcmp($a['name'], $b['name']));

        $json = json_encode(['system_references' => $refs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $result = ['file' => $fileName, 'records' => count($rows), 'in_file' => count($refs)];

        if ($existing === $json) {
            return ['written' => false, ...$result, 'reason' => 'unchanged'];
        }

        $this->storageService->putJsonToBucket($fileName, $directory, $json);
        $this->noteWrittenToBucket($fileName, $json);

        return ['written' => true, ...$result];
    }

    /** Waiting records (is_exported=0), mapped to the JSON payload. Each value carries ALL keys defined for that booking type — null for keys not set on this specific record — so the mobile importer always sees a stable, uniform schema. @param array<int, ?string> $waiting filled: record id => updated_at @return array<int, array{booking_type_id: int, unit_id: int|null, id: int, name: string, value: array<string, mixed>}> */
    public function payload(array &$waiting = []): array
    {
        $records = SystemReference::query()
            ->where('is_exported', false)
            ->get(['id', 'booking_type_id', 'unit_id', 'name', 'value', 'updated_at']);

        if ($records->isEmpty()) {
            $waiting = [];

            return [];
        }

        // Build the full value-key schema per booking type from ALL records (not just those waiting), so the unified format is complete even when only some records changed today.
        $typeIds = $records->pluck('booking_type_id')->unique()->values()->all();
        $keysByType = [];
        foreach (
            SystemReference::query()
                ->whereIn('booking_type_id', $typeIds)
                ->get(['id', 'booking_type_id', 'value']) as $r
        ) {
            foreach (array_keys($r->decodedValue()) as $key) {
                $keysByType[(int) $r->booking_type_id][$key] = true;
            }
        }

        $rows = [];
        $waiting = [];

        foreach ($records as $record) {
            $typeId = (int) $record->booking_type_id;
            $isRestrictionDates = SystemReferenceEnum::isRestrictionDatesReference($typeId, (int) $record->id);

            $ownValue = $isRestrictionDates
                ? ['dates' => SystemReferenceEnum::getBookingRestrictionDates($typeId)]
                : $record->decodedValue();

            // All keys for this booking type, null by default; this record's own values override their own keys.
            $allKeys = array_keys($keysByType[$typeId] ?? []);
            $value = array_merge(array_fill_keys($allKeys, null), $ownValue);

            $rows[] = [
                'booking_type_id' => $typeId,
                'unit_id' => $record->unit_id !== null ? (int) $record->unit_id : null,
                'id' => (int) $record->id,
                'name' => (string) $record->name,
                'value' => $value,
            ];
            $waiting[$record->id] = $record->getRawOriginal('updated_at');
        }

        usort($rows, fn (array $a, array $b): int => $a['booking_type_id'] <=> $b['booking_type_id'] ?: strcmp($a['name'], $b['name']));

        return $rows;
    }
}
