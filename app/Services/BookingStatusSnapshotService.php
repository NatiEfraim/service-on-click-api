<?php

namespace App\Services;

use App\Domain\Sync\ExportFileNames;
use App\Enums\DigitalHatchFileEnum;
use App\Models\Booking;
use App\Models\SyncConflict;
use App\Traits\GuardsDigitalHatchRuns;
use App\Traits\HatchExportable;

/** r2b booking status stream: writes a cumulative daily snapshot to the bucket and sends it to the FTP; driven by bookings.is_status_exported. */
class BookingStatusSnapshotService
{
    use GuardsDigitalHatchRuns, HatchExportable;

    public function __construct(
        private readonly StorageService $storageService,
        private readonly BookingStatusExportService $statusExport,
    ) {
    }

    /**
     * @param bool $force re-send even if this content was already sent
     * @param int|null $bookingTypeId optional filter (manual web trigger)
     * @param int|null $statusId optional filter (manual web trigger)
     * @return array{enabled: bool, busy?: bool, destination?: string, built?: array<string, mixed>, file?: ?string, marked?: int, errors?: array<int, string>}
     */
    public function export(bool $force = false, ?int $bookingTypeId = null, ?int $statusId = null): array
    {
        return $this->locked('send-booking-status', function () use ($force, $bookingTypeId, $statusId): array {
            $errors = [];
            $waiting = [];

            $built = $this->building('booking status file', function () use (&$waiting, $bookingTypeId, $statusId): array {
                return $this->run($waiting, $bookingTypeId, $statusId);
            }, $errors);
            $sent = $this->sendNewestFile(DigitalHatchFileEnum::BOOKING_STATUS, $force);
            $errors = [...$errors, ...$sent['errors']];

            // Only what really went is marked: after a failed build or send the bookings stay waiting for the next run.
            $marked = $errors === [] ? $this->markExported($waiting) : 0;

            return ['destination' => $this->exportDestination(), 'built' => $built, 'file' => $sent['file'], 'marked' => $marked, 'errors' => $errors];
        });
    }

    /**
     * Builds the day's booking status file in the bucket, merging with what earlier runs put there.
     * @param array<int, int> $waiting booking id => version
     * @return array{written: bool, file?: string, bookings?: int, in_file?: int, reason?: string}
     */
    public function run(array &$waiting = [], ?int $bookingTypeId = null, ?int $statusId = null): array
    {
        [$rows, $waiting] = $this->waitingRows($bookingTypeId, $statusId);

        if ($rows === []) {
            return ['written' => false, 'reason' => 'nothing_waiting'];
        }

        // The day is counted in the timezone the mobile importer uses for its own "today".
        $fileName = ExportFileNames::bookingStatusFile(now(config('app.export_json.timezone')), (string) config('app.export_json.booking_status_prefix'));
        $directory = DigitalHatchFileEnum::BOOKING_STATUS->bucketDirectory();
        $existing = $this->storageService->bucketFileContents($fileName, $directory);

        $inFile = [];
        foreach ((json_decode((string) $existing, true)['bookings'] ?? []) as $row) {
            if (is_array($row) && isset($row['serial_number'])) {
                $inFile[(int) $row['serial_number']] = $row;
            }
        }

        foreach ($rows as $serial => $row) {
            $inFile[$serial] = $row;
        }

        $json = json_encode(['bookings' => array_values($inFile)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $result = ['file' => $fileName, 'bookings' => count($rows), 'in_file' => count($inFile)];

        if ($existing === $json) {
            return ['written' => false, ...$result, 'reason' => 'unchanged'];
        }

        $this->storageService->putJsonToBucket($fileName, $directory, $json);
        $this->noteWrittenToBucket($fileName, $json);

        return ['written' => true, ...$result];
    }

    /**
     * @param int|null $bookingTypeId optional filter
     * @param int|null $statusId optional filter
     * @return array{0: array<int, array{serial_number: int, status_id: int, personal_id: ?string, updated_at: string}>, 1: array<int, int>}
     */
    private function waitingRows(?int $bookingTypeId = null, ?int $statusId = null): array
    {
        $rows = [];
        $waiting = [];

        // In chunks: the deciders are looked up with one query per chunk, not per booking.
        Booking::query()
            ->whereNotNull('serial_number')
            ->where('is_status_exported', false)
            ->when($bookingTypeId !== null, fn ($q) => $q->where('booking_type_id', $bookingTypeId))
            ->when($statusId !== null, fn ($q) => $q->where('status_id', $statusId))
            ->whereDoesntHave('syncConflicts', fn ($conflicts) => $conflicts->where('state', SyncConflict::STATE_OPEN))
            ->orderBy('id')
            ->chunkById(2000, function ($bookings) use (&$rows, &$waiting): void {
                $deciders = $this->statusExport->deciders($bookings->modelKeys());

                foreach ($bookings as $booking) {
                    $rows[(int) $booking->serial_number] = [
                        'serial_number' => (int) $booking->serial_number,
                        'status_id' => (int) $booking->status_id,
                        'personal_id' => isset($deciders[$booking->id]) ? preg_replace('/\D/', '', $deciders[$booking->id]) : null,
                        'updated_at' => (string) $booking->statusSince()->getTimestamp(),
                    ];
                    $waiting[$booking->id] = (int) $booking->version;
                }
            });

        return [$rows, $waiting];
    }

    /** Sets is_status_exported = 1 on sent bookings; a newer version stays waiting. @param array<int, int> $waiting booking id => version @return int */
    private function markExported(array $waiting): int
    {
        $idsByVersion = [];

        foreach ($waiting as $id => $version) {
            $idsByVersion[$version][] = $id;
        }

        $marked = 0;

        foreach ($idsByVersion as $version => $ids) {
            foreach (array_chunk($ids, 2000) as $chunk) {
                // Not touching updated_at: the flag is bookkeeping, not an edit of the booking.
                $marked += Booking::query()->whereIn('id', $chunk)->where('version', $version)->toBase()->update(['is_status_exported' => true]);
            }
        }

        return $marked;
    }
}
