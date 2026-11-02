<?php

namespace App\Services;

use App\Domain\Sync\ExportFileNames;
use App\Enums\DigitalHatchFileEnum;
use App\Models\Booking;
use App\Models\SyncConflict;
use App\Traits\GuardsDigitalHatchRuns;
use App\Traits\HatchExportable;

/**
 * The booking status stream of the digital hatch (r2b), driven by the flag bookings.is_status_exported:
 *  - changeStatus (BookingStatusRecorder) sets the new status_id and is_status_exported = 0 together, so the booking is waiting to be sent;
 *  - export() puts every waiting booking in the day's file - { "bookings": [ { serial_number, status_id, personal_id, updated_at } ] }, the shape
 *    of the file exportBookingStatusJson:mysql writes - sends the file, and only then sets is_status_exported = 1 on those bookings.
 * The day's file (SrvOnClick_r2b_booking_status_<Y-m-d>.json, the mobile importer reads one file per day by its exact name) keeps what earlier
 * runs of the day put in it, so a later run never takes a booking out of it; a booking decided again replaces its row.
 * personal_id = the ID number of whoever made the latest decision here (null when there is none); updated_at = unix seconds as a string.
 * A booking with an open sync conflict is held back until the conflict is settled. Queued by SendHatchBookingStatusJob (command
 * digital-hatch:send-booking-status). While the hatch is on, BookingStatusExportService leaves the status to this stream: one sender per flag.
 */
class BookingStatusSnapshotService
{
    use GuardsDigitalHatchRuns, HatchExportable;

    public function __construct(
        private readonly StorageService $storageService,
        private readonly BookingStatusExportService $statusExport,
    ) {
    }

    /**
     * @param bool $force send the day's file again even when this exact content was sent before
     * @return array{enabled: bool, busy?: bool, destination?: string, built?: array<string, mixed>, file?: ?string, marked?: int, errors?: array<int, string>}
     *         built = what run() did, file = the name sent (null: nothing new), marked = bookings set to is_status_exported = 1
     */
    public function export(bool $force = false): array
    {
        return $this->locked('send-booking-status', function () use ($force): array {
            $errors = [];
            $waiting = [];

            $built = $this->building('booking status file', function () use (&$waiting): array {
                return $this->run($waiting);
            }, $errors);
            $sent = $this->sendNewestFile(DigitalHatchFileEnum::BOOKING_STATUS, $force);
            $errors = [...$errors, ...$sent['errors']];

            // Only what really went is marked: after a failed build or send the bookings stay waiting for the next run.
            $marked = $errors === [] ? $this->markExported($waiting) : 0;

            return ['destination' => $this->exportDestination(), 'built' => $built, 'file' => $sent['file'], 'marked' => $marked, 'errors' => $errors];
        });
    }

    /**
     * Puts the waiting bookings in the bucket file of the day (export-json/booking-status), next to what earlier runs of the day put there.
     * @param array<int, int> $waiting filled with the bookings that are in the file now: booking id => version
     * @return array{written: bool, file?: string, bookings?: int, in_file?: int, reason?: string} reason: nothing_waiting | unchanged
     */
    public function run(array &$waiting = []): array
    {
        [$rows, $waiting] = $this->waitingRows();

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

        // A booking decided again replaces its row where it stood.
        foreach ($rows as $serial => $row) {
            $inFile[$serial] = $row;
        }

        $json = json_encode(['bookings' => array_values($inFile)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $result = ['file' => $fileName, 'bookings' => count($rows), 'in_file' => count($inFile)];

        if ($existing === $json) {
            return ['written' => false, ...$result, 'reason' => 'unchanged'];
        }

        $this->storageService->putJsonToBucket($fileName, $directory, $json);

        return ['written' => true, ...$result];
    }

    /**
     * The bookings waiting to be sent, as the rows of the file, and the version each had when it was read.
     * @return array{0: array<int, array{serial_number: int, status_id: int, personal_id: ?string, updated_at: string}>, 1: array<int, int>} rows by serial number; booking id => version
     */
    private function waitingRows(): array
    {
        $rows = [];
        $waiting = [];

        // In chunks: the deciders are looked up with one query per chunk, not per booking.
        Booking::query()
            ->whereNotNull('serial_number')
            ->where('is_status_exported', false)
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

    /**
     * Sets is_status_exported = 1 on the bookings that went out. A booking decided again while the file was written has a newer version
     * than the file holds: it stays waiting and goes in the next file.
     * @param array<int, int> $waiting booking id => version @return int the bookings marked
     */
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
