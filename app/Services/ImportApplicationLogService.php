<?php

namespace App\Services;

use App\Enums\ActionTypeEnum;
use App\Enums\JsonModelTypeEnum;
use App\Enums\StatusBookingEnum;
use App\Jobs\ImportApplicationLogJsonChunkJob;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Traits\ReadsImportJsonFiles;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Save the logs of the exported application log files (SrvOnClick_b2r_application_logs_<date>.json): what was done to a booking
 * (action_type_id), by whom (personal_id) and when. A log belongs to the booking with its serial number, so the booking must be saved
 * first - ImportBookingService. A log that is saved already is recognised by booking, action, person and time, so reading a file again adds nothing.
 * The file also carries the booking's status at that time: it is saved too (status_id), which makes the log the booking's timeline - how long each stage took.
 */
class ImportApplicationLogService
{
    use ReadsImportJsonFiles;

    public function __construct(
        private readonly StorageService $storageService,
        private readonly UserService $userService,
    ) {
    }

    protected function jsonModelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::APPLICATION_LOG;
    }

    protected function chunkSize(): int
    {
        return max(1, (int) config('app.import_json.application_log_chunk_size', 500));
    }

    protected function makeChunkJob(array $rows, int $chunkIndex): object
    {
        return new ImportApplicationLogJsonChunkJob($rows, $chunkIndex);
    }

    /**
     * Save one chunk of the file in one transaction. A row that cannot be a log is logged and skipped; a log whose booking is not saved yet is
     * counted in booking_not_found - the job looks again later (see ImportApplicationLogJsonChunkJob).
     * @param array $logsChunk, int $chunkIndex @return array<string, int> created, already_saved, skipped, booking_not_found
     */
    public function importChunk(array $logsChunk, int $chunkIndex): array
    {
        $stats = ['created' => 0, 'already_saved' => 0, 'skipped' => 0, 'booking_not_found' => 0];

        $valid = [];

        foreach ($logsChunk as $index => $logData) {
            $row = $this->prepareRow($logData);

            if (is_string($row)) {
                Log::warning("ImportApplicationLogService: chunk {$chunkIndex} row {$index} skipped: {$row}.");
                $stats['skipped']++;
                continue;
            }

            $valid[$index] = $row;
        }

        $bookings = Booking::whereIn('serial_number', array_values(array_unique(array_column($valid, 'serial_number'))))
            ->get(['id', 'serial_number', 'unit_id'])
            ->keyBy('serial_number');

        $unitIdByPersonalId = [];

        foreach ($valid as $row) {
            if ($bookings->has($row['serial_number'])) {
                $unitIdByPersonalId[$row['personal_id']] ??= $bookings->get($row['serial_number'])->unit_id;
            }
        }

        $userIds = $this->userService->idsByPersonalId($unitIdByPersonalId);
        $savedKeys = $this->savedLogKeys($bookings->pluck('id')->all());

        $inserts = [];

        foreach ($valid as $index => $row) {
            $booking = $bookings->get($row['serial_number']);

            if ($booking === null) {
                Log::warning("ImportApplicationLogService: chunk {$chunkIndex} row {$index}: booking {$row['serial_number']} is not saved (yet).");
                $stats['booking_not_found']++;
                continue;
            }

            $userId = $userIds[$row['personal_id']];
            $key = $this->logKey($booking->id, $row['action_type_id'], $userId, $row['created_at']->getTimestamp());

            if (isset($savedKeys[$key])) {
                $stats['already_saved']++;
                continue;
            }

            $savedKeys[$key] = true;
            $timestamp = $row['created_at']->format('Y-m-d H:i:s');

            $inserts[] = [
                'booking_id' => $booking->id,
                'action_type_id' => $row['action_type_id'],
                'status_id' => $row['status_id'],
                'source' => 'external',
                'user_id' => $userId,
                // The mobile app has these already - nothing here is sent back to it.
                'is_exported' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($inserts !== []) {
            DB::transaction(fn () => ApplicationLog::insert($inserts));
        }

        $stats['created'] = count($inserts);

        Log::info("ImportApplicationLogService: chunk {$chunkIndex} done - " . count($logsChunk) . " rows: {$stats['created']} created, {$stats['already_saved']} already saved, "
            . "{$stats['skipped']} skipped, {$stats['booking_not_found']} without a saved booking.");

        return $stats;
    }

    //----------------------------- Private Methods ------------------------//

    /** Why a row cannot be saved (a string), or the row ready to save. @return array|string */
    private function prepareRow(mixed $logData): array|string
    {
        if (!is_array($logData)) {
            return 'row is not an object';
        }

        if (!is_numeric($logData['serial_number'] ?? null) || (int) $logData['serial_number'] <= 0) {
            return 'missing or invalid serial_number';
        }

        if (!is_numeric($logData['action_type_id'] ?? null) || !ActionTypeEnum::tryFrom((int) $logData['action_type_id'])) {
            return 'missing or unknown action_type_id ' . json_encode($logData['action_type_id'] ?? null);
        }

        $personalId = UserService::normalizePersonalId($logData['personal_id'] ?? null);

        if ($personalId === null) {
            return 'missing or invalid personal_id';
        }

        $createdAt = $logData['created_at'] ?? null;

        if ((!is_int($createdAt) && !is_string($createdAt)) || !is_numeric($createdAt) || (int) $createdAt <= 0) {
            return 'missing or invalid created_at';
        }

        // A status the file names that this system does not know is left out; the log is saved all the same.
        $statusId = is_numeric($logData['status_id'] ?? null) && StatusBookingEnum::tryFrom((int) $logData['status_id']) !== null
            ? (int) $logData['status_id']
            : null;

        return [
            'serial_number' => (int) $logData['serial_number'],
            'action_type_id' => (int) $logData['action_type_id'],
            'status_id' => $statusId,
            'personal_id' => $personalId,
            'created_at' => Carbon::createFromTimestamp((int) $createdAt, config('app.timezone')),
        ];
    }

    /** The logs these bookings have already, as keys for logKey(). @param array<int, int> $bookingIds @return array<string, true> */
    private function savedLogKeys(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $keys = [];

        foreach (ApplicationLog::whereIn('booking_id', $bookingIds)->get(['booking_id', 'action_type_id', 'user_id', 'created_at']) as $log) {
            $keys[$this->logKey($log->booking_id, $log->action_type_id, $log->user_id, $log->created_at->getTimestamp())] = true;
        }

        return $keys;
    }

    private function logKey(int $bookingId, int $actionTypeId, int $userId, int $timestamp): string
    {
        return "{$bookingId}|{$actionTypeId}|{$userId}|{$timestamp}";
    }
}
