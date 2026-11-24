<?php

namespace App\Services;

use App\Domain\Sync\StatusConflictResolver;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\JsonModelTypeEnum;
use App\Enums\StatusBookingEnum;
use App\Enums\UnitEnum;
use App\Jobs\ImportBookingJsonChunkJob;
use App\Models\ApplicationLog;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\BookingValue;
use App\Models\Comment;
use App\Models\File;
use App\Models\FormField;
use App\Models\Image;
use App\Models\OptionField;
use App\Models\SyncConflict;
use App\Models\UnitHasBookingType;
use App\Models\User;
use App\Traits\ReadsImportJsonFiles;
use App\Traits\ResolvesBarberSchedule;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saves exported booking files, matching by serial number so a re-read changes nothing.
 * A record without booking_type_id only brings the new status, DATE/TIME values and comments.
 */
class ImportBookingService
{
    use ReadsImportJsonFiles, ResolvesBarberSchedule;

    /** @var array<int, ?int> unit id by booking type id */
    private array $unitIdByBookingType = [];

    private ?int $barberSlotMinutes = null;

    /** @var array<string, int> field_name => id of the custom fields in the current chunk */
    private array $customFieldIds = [];

    /** @var array<string, int> "<form field id>|<option name>" => option id */
    private array $customOptionIds = [];

    public function __construct(
        private readonly StorageService $storageService,
        private readonly UserService $userService,
        private readonly AttachmentService $attachmentService,
    ) {
    }

    protected function jsonModelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::EXPORT_BOOKING;
    }

    protected function chunkSize(): int
    {
        return max(1, (int) config('app.import_json.booking_chunk_size', 100));
    }

    protected function makeChunkJob(array $rows, int $chunkIndex): object
    {
        return new ImportBookingJsonChunkJob($rows, $chunkIndex);
    }

    /**
     * Saves one chunk. Failed rows are logged and the chunk throws at the end so the job retries (saving is idempotent).
     * @return array<string, int> counters: created, updated, unchanged, skipped, failed, values_skipped, comments_added, attachments_added
     */
    public function importChunk(array $bookingsChunk, int $chunkIndex): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'values_skipped' => 0, 'comments_added' => 0, 'attachments_added' => 0];

        $serials = [];

        foreach ($bookingsChunk as $bookingData) {
            if (is_array($bookingData) && is_numeric($bookingData['serial_number'] ?? null)) {
                $serials[] = (int) $bookingData['serial_number'];
            }
        }

        $existingBookings = Booking::whereIn('serial_number', $serials)->get()->keyBy('serial_number')->all();
        $bookingTypeIds = array_flip(BookingType::pluck('id')->map(fn ($id) => (int) $id)->all());
        [$formFields, $optionFields] = $this->loadFormFields($bookingsChunk);

        $prepared = [];

        foreach ($bookingsChunk as $index => $bookingData) {
            $row = $this->prepareRow($bookingData, $existingBookings, $bookingTypeIds, $formFields, $optionFields);

            if (is_string($row)) {
                Log::warning("ImportBookingService: chunk {$chunkIndex} row {$index} skipped: {$row}.");
                $stats['skipped']++;
                continue;
            }

            $stats['values_skipped'] += $row['values_skipped'];
            $prepared[$index] = $row;
        }

        // Owners and comment authors need user rows before the bookings are saved.
        $unitIdByPersonalId = [];

        foreach ($prepared as $row) {
            foreach ([$row['owner_personal_id'], ...array_column($row['comments'], 'personal_id')] as $personalId) {
                if ($personalId !== null) {
                    $unitIdByPersonalId[$personalId] ??= $row['unit_id'];
                }
            }
        }

        $userIds = $this->userService->idsByPersonalId($unitIdByPersonalId);

        $failures = [];

        foreach ($prepared as $index => $row) {
            try {
                $saved = $this->saveRow($row, $userIds);
                $stats[$saved['outcome']]++;
                $stats['comments_added'] += $saved['comments_added'];
                $stats['attachments_added'] += $saved['attachments_added'];
            } catch (\Exception $e) {
                $stats['failed']++;
                $failures[] = "row {$index} (booking {$row['serial_number']}): " . $e->getMessage();
                Log::error("ImportBookingService: chunk {$chunkIndex} row {$index} failed: " . $e->getMessage());
            }
        }

        Log::info("ImportBookingService: chunk {$chunkIndex} done - " . count($bookingsChunk) . " rows: {$stats['created']} created, {$stats['updated']} updated, "
            . "{$stats['unchanged']} unchanged, {$stats['skipped']} skipped, {$stats['failed']} failed; {$stats['values_skipped']} values skipped, {$stats['comments_added']} comments added, {$stats['attachments_added']} files and images linked.");

        if ($failures !== []) {
            throw new \RuntimeException(count($failures) . ' of ' . count($bookingsChunk) . ' bookings could not be saved, the first: ' . $failures[0]);
        }

        return $stats;
    }

    /** Imported data for review: totals, counts per type and status, and one page of bookings. */
    public function importedData(int $perPage = 10, ?int $bookingTypeId = null, ?string $serialNumber = null): array
    {
        $page = Booking::query()
            ->with([
                'bookingType', 'status', 'user', 'comments.user', 'files', 'images',
                'bookingValue.formField', 'bookingValue.optionField',
                'applicationLog.actionType', 'applicationLog.user',
            ])
            ->when($bookingTypeId, fn ($query) => $query->where('booking_type_id', $bookingTypeId))
            ->when($serialNumber, fn ($query) => $query->where('serial_number', 'like', $serialNumber . '%'))
            ->orderBy('id')
            ->paginate($perPage);

        $barberSlots = BarberBooking::whereIn('booking_id', $page->getCollection()->pluck('id'))->get()->keyBy('booking_id');

        $page->through(fn (Booking $booking): array => [
            'serial_number' => $booking->serial_number,
            'uuid' => $booking->uuid,
            'booking_type' => ['id' => $booking->booking_type_id, 'name' => $booking->bookingType?->name, 'display_name' => $booking->bookingType?->display_name],
            'status' => ['id' => $booking->status_id, 'name' => $booking->status?->name, 'display_name' => $booking->status?->display_name],
            'unit_id' => $booking->unit_id,
            'owner' => ['personal_id' => $booking->user?->personal_id, 'personal_number' => $booking->user?->personal_number, 'full_name' => $booking->user?->full_name],
            'created_at' => Carbon::parse($booking->getRawOriginal('created_at'))->toDateTimeString(),
            'updated_at' => Carbon::parse($booking->getRawOriginal('updated_at'))->toDateTimeString(),
            'values' => $booking->bookingValue->map(fn (BookingValue $value): array => [
                'form_field_id' => $value->form_field_id,
                'field' => $value->formField?->field_display_name,
                'option_field_id' => $value->option_field_id,
                'option' => $value->optionField?->name,
                'value' => $value->value,
            ])->values()->all(),
            'comments' => $booking->comments->map(fn (Comment $comment): array => [
                'personal_id' => $comment->user?->personal_id,
                'comment' => $comment->comment,
            ])->values()->all(),
            'files' => $booking->files->pluck('name')->values()->all(),
            'images' => $booking->images->pluck('name')->values()->all(),
            'application_logs' => $booking->applicationLog->map(fn (ApplicationLog $log): array => [
                'action_type_id' => $log->action_type_id,
                'action' => $log->actionType?->type,
                'personal_id' => $log->user?->personal_id,
                'created_at' => Carbon::parse($log->getRawOriginal('created_at'))->toDateTimeString(),
            ])->values()->all(),
            'barber_slot' => $barberSlots->has($booking->id) ? $barberSlots->get($booking->id)->only(['date', 'start_time', 'end_time', 'is_deleted']) : null,
        ]);

        return [
            'totals' => [
                'bookings' => Booking::count(),
                'booking_values' => BookingValue::count(),
                'comments' => Comment::count(),
                'files' => File::count(),
                'images' => Image::count(),
                'application_logs' => ApplicationLog::count(),
                'barber_bookings' => BarberBooking::count(),
                'users' => User::count(),
            ],
            'bookings_by_type' => Booking::query()->selectRaw('booking_type_id, COUNT(*) as total')->groupBy('booking_type_id')->orderBy('booking_type_id')->pluck('total', 'booking_type_id')->all(),
            'bookings_by_status' => Booking::query()->selectRaw('status_id, COUNT(*) as total')->groupBy('status_id')->orderBy('status_id')->pluck('total', 'status_id')->all(),
            'application_logs_by_action' => ApplicationLog::query()->selectRaw('action_type_id, COUNT(*) as total')->groupBy('action_type_id')->orderBy('action_type_id')->pluck('total', 'action_type_id')->all(),
            'bookings' => $page,
        ];
    }

    /** @return array|string the reason a row is unusable, or the row ready to save */
    private function prepareRow(mixed $bookingData, array $existingBookings, array $bookingTypeIds, array $formFields, array $optionFields): array|string
    {
        if (!is_array($bookingData)) {
            return 'row is not an object';
        }

        if (!is_numeric($bookingData['serial_number'] ?? null) || (int) $bookingData['serial_number'] <= 0) {
            return 'missing or invalid serial_number';
        }

        $serialNumber = (int) $bookingData['serial_number'];

        if (!is_numeric($bookingData['status_id'] ?? null) || !StatusBookingEnum::tryFrom((int) $bookingData['status_id'])) {
            return 'missing or unknown status_id ' . json_encode($bookingData['status_id'] ?? null);
        }

        $booking = $existingBookings[$serialNumber] ?? null;
        $bookingTypeId = is_numeric($bookingData['booking_type_id'] ?? null) ? (int) $bookingData['booking_type_id'] : null;
        $ownerPersonalId = null;

        if ($booking === null) {
            // Only a full record can create a booking.
            if ($bookingTypeId === null) {
                return "booking {$serialNumber} is not saved here and the row is only a change record";
            }

            if (!isset($bookingTypeIds[$bookingTypeId])) {
                return "unknown booking_type_id {$bookingTypeId}";
            }

            $ownerPersonalId = UserService::normalizePersonalId($bookingData['booking_owner_personal_id'] ?? null);

            if ($ownerPersonalId === null) {
                return 'missing or invalid booking_owner_personal_id';
            }
        } else {
            if ($bookingTypeId !== null && $bookingTypeId !== (int) $booking->booking_type_id) {
                return "booking {$serialNumber} is saved with booking type {$booking->booking_type_id}, the row says {$bookingTypeId}";
            }

            $bookingTypeId = (int) $booking->booking_type_id;
        }

        $createdAt = $this->timeFromUnix($bookingData['created_at'] ?? null);
        $updatedAt = $this->timeFromUnix($bookingData['updated_at'] ?? null) ?? $createdAt ?? now();
        $createdAt ??= $updatedAt;

        [$values, $valuesSkipped] = $this->mapValues($bookingData['booking_values'] ?? null, $bookingTypeId, $serialNumber, $formFields, $optionFields);

        $unitId = $booking ? (int) $booking->unit_id : $this->unitIdOfNewBooking($bookingTypeId, $values, $formFields);

        if (!$unitId) {
            return "no unit found for booking type {$bookingTypeId}";
        }

        $comments = [];

        foreach ($this->listOf($bookingData['comments'] ?? null) as $comment) {
            $personalId = UserService::normalizePersonalId($comment['personal_id'] ?? null);

            if ($personalId === null || !is_string($comment['comment'] ?? null) || trim($comment['comment']) === '') {
                Log::warning("ImportBookingService: booking {$serialNumber}: a comment without text or with an invalid personal_id was skipped.");
                continue;
            }

            $comments[] = ['personal_id' => $personalId, 'comment' => $comment['comment']];
        }

        return [
            'booking' => $booking,
            'serial_number' => $serialNumber,
            'booking_type_id' => $bookingTypeId,
            'owner_personal_id' => $ownerPersonalId,
            'unit_id' => $unitId,
            'status_id' => (int) $bookingData['status_id'],
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'values' => $values,
            'values_skipped' => $valuesSkipped,
            'comments' => $comments,
            'files' => $this->attachmentNames($bookingData['files'] ?? null, $serialNumber),
            'images' => $this->attachmentNames($bookingData['images'] ?? null, $serialNumber),
            'barber' => $this->barberDateAndTime($values, $formFields),
        ];
    }

    /**
     * Stored names of the files/images a row lists; names that are not plain are logged and skipped, never used for bucket lookups.
     * The mobile may include a file extension in the path (e.g. "SrvOnClickFiles_xxx.jpeg"); we strip it so the DB always
     * holds the base name, and AttachmentService::resolve() probes extensions to find the bytes.
     * @return array<int, string>
     */
    private function attachmentNames(mixed $list, int $serialNumber): array
    {
        $names = [];

        foreach ($this->listOf($list) as $entry) {
            $name = is_array($entry) ? ($entry['path'] ?? null) : null;

            if (!AttachmentService::isValidName($name)) {
                Log::warning("ImportBookingService: booking {$serialNumber}: a file or image without a valid name was skipped.");
                continue;
            }

            // Strip a served extension the mobile may include; store only the base name.
            if (AttachmentService::servedExtension($name) !== null) {
                $base = pathinfo($name, PATHINFO_FILENAME);
                if (AttachmentService::isValidName($base)) {
                    $name = $base;
                }
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    /**
     * Maps export nodes to booking_values leaves: one row per selected option without a selected child, per value, or per plain choice;
     * category nodes get no row and nodes outside the booking type are skipped.
     * @return array{0: array<int, array>, 1: int} rows and skipped count
     */
    private function mapValues(mixed $nodes, int $bookingTypeId, int $serialNumber, array $formFields, array $optionFields): array
    {
        $nodes = $this->listOf($nodes);
        $categoryFieldIds = [];

        foreach ($nodes as $node) {
            if (!empty($node['form_field_id'])) {
                $categoryFieldIds[(int) $node['form_field_id']] = true;
            }
        }

        $rows = [];
        $skipped = 0;

        $skip = function (string $reason) use (&$skipped, $serialNumber): void {
            $skipped++;
            Log::warning("ImportBookingService: booking {$serialNumber}: value skipped: {$reason}.");
        };

        foreach ($nodes as $node) {
            $fieldId = (int) ($node['field_id'] ?? 0);

            // Custom fields are matched by field_name; the mobile app's id means nothing here.
            $isCustom = is_string($node['field_name'] ?? null) && isset($this->customFieldIds[$node['field_name']]);

            if ($isCustom) {
                $fieldId = $this->customFieldIds[$node['field_name']];
            }

            // Every other field - the mobile-owned "גורם מטפל" ones (106/107) included - resolves by its raw id:
            // the shared-id contract (FormField::MOBILE_SHARED_MAX) keeps both systems' ids identical.
            $formField = $formFields[$fieldId] ?? null;

            if ($formField === null || (int) $formField->booking_type_id !== $bookingTypeId) {
                $skip("form field {$fieldId} is not a field of booking type {$bookingTypeId}");
                continue;
            }

            // Uploads live in booking_has_files / booking_has_images, not in booking_values.
            if (FieldTypeEnum::isUploadFieldTypeId((int) $formField->field_type_id)) {
                $skip("form field {$fieldId} is an upload field");
                continue;
            }

            $options = $this->listOf($node['option_fields'] ?? null);

            if ($options !== []) {
                $parentOptionIds = [];

                foreach ($options as $option) {
                    if (!empty($option['parent_option_id'])) {
                        $parentOptionIds[(int) $option['parent_option_id']] = true;
                    }
                }

                foreach ($options as $option) {
                    $optionId = (int) ($option['option_id'] ?? 0);

                    // Options of custom fields are matched by name (custom ids differ between systems by design).
                    if ($isCustom && is_string($option['display_name'] ?? null)) {
                        $optionId = $this->customOptionIds[$fieldId . '|' . trim($option['display_name'])] ?? 0;
                    }

                    // An option with a selected child is not saved; the web finds it through the child.
                    if (isset($parentOptionIds[$optionId])) {
                        continue;
                    }

                    $optionField = $optionFields[$optionId] ?? null;
                    $optionFormField = $optionField ? ($formFields[(int) $optionField->form_field_id] ?? null) : null;

                    if ($optionFormField === null || (int) $optionFormField->booking_type_id !== $bookingTypeId) {
                        $skip("option {$optionId} is not an option of booking type {$bookingTypeId}");
                        continue;
                    }

                    $rows["{$optionFormField->id}|{$optionId}"] = [
                        'form_field_id' => (int) $optionFormField->id,
                        'option_field_id' => $optionId,
                        'value' => $this->encodeValue($option['value'] ?? null),
                    ];
                }

                continue;
            }

            if (($node['value'] ?? null) !== null) {
                $rows["{$fieldId}|"] = ['form_field_id' => $fieldId, 'option_field_id' => null, 'value' => $this->encodeValue($node['value'])];
                continue;
            }

            if (!isset($categoryFieldIds[$fieldId])) {
                $rows["{$fieldId}|"] = ['form_field_id' => $fieldId, 'option_field_id' => null, 'value' => null];
            }
        }

        return [array_values($rows), $skipped];
    }

    /** Encodes a value for the JSON column: scalars as JSON strings, lists as JSON lists, none as NULL. */
    private function encodeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(is_scalar($value) ? (string) $value : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Loads the form fields and options a chunk mentions; custom fields (field_name "int_...") are found by name, their options by display_name.
     * @return array{0: array<int, FormField>, 1: array<int, OptionField>} by id
     */
    private function loadFormFields(array $bookingsChunk): array
    {
        $formFieldIds = [];
        $optionFieldIds = [];
        $customNames = [];

        foreach ($bookingsChunk as $bookingData) {
            if (!is_array($bookingData)) {
                continue;
            }

            foreach ($this->listOf($bookingData['booking_values'] ?? null) as $node) {
                $formFieldIds[] = (int) ($node['field_id'] ?? 0);

                if (is_string($node['field_name'] ?? null) && str_starts_with($node['field_name'], FormField::CUSTOM_PREFIX)) {
                    $customNames[$node['field_name']] = true;
                }

                foreach ($this->listOf($node['option_fields'] ?? null) as $option) {
                    $optionFieldIds[] = (int) ($option['option_id'] ?? 0);
                }
            }
        }

        $this->customFieldIds = [];
        $this->customOptionIds = [];

        $optionFields = OptionField::whereIn('id', array_values(array_unique(array_filter($optionFieldIds))))->get(['id', 'form_field_id'])->keyBy('id');
        $formFieldIds = array_merge($formFieldIds, $optionFields->pluck('form_field_id')->all());

        $formFields = FormField::whereIn('id', array_values(array_unique(array_filter($formFieldIds))))
            ->get(['id', 'unit_id', 'booking_type_id', 'field_type_id', 'form_field_id'])
            ->keyBy('id');

        if ($customNames !== []) {
            $custom = FormField::whereIn('field_name', array_keys($customNames))->get(['id', 'unit_id', 'booking_type_id', 'field_type_id', 'form_field_id', 'field_name']);

            foreach ($custom as $field) {
                $this->customFieldIds[$field->field_name] = (int) $field->id;
                $formFields->put($field->id, $field);
            }

            foreach (OptionField::whereIn('form_field_id', $custom->modelKeys())->where('is_active', true)->get(['id', 'form_field_id', 'name']) as $option) {
                $this->customOptionIds[$option->form_field_id . '|' . $option->name] = (int) $option->id;
                $optionFields->put($option->id, $option);
            }
        }

        return [$formFields->all(), $optionFields->all()];
    }

    /** Unit of the form fields of the new booking, else the unit offering its booking type. */
    private function unitIdOfNewBooking(int $bookingTypeId, array $values, array $formFields): ?int
    {
        foreach ($values as $value) {
            $unitId = $formFields[$value['form_field_id']]->unit_id ?? null;

            if ($unitId) {
                return (int) $unitId;
            }
        }

        if (!array_key_exists($bookingTypeId, $this->unitIdByBookingType)) {
            $this->unitIdByBookingType[$bookingTypeId] = UnitHasBookingType::where('booking_type_id', $bookingTypeId)->value('unit_id')
                ?? UnitEnum::H->getUnitId();
        }

        return $this->unitIdByBookingType[$bookingTypeId];
    }

    /** Saves one prepared row in a transaction. @return array{outcome: string, comments_added: int, attachments_added: int} */
    private function saveRow(array $row, array $userIds): array
    {
        try {
            return DB::transaction(fn () => $this->saveBooking($row, $this->lockedBooking($row['booking']), $userIds));
        } catch (UniqueConstraintViolationException $e) {
            // Another job saved this booking meanwhile: update it instead.
            $booking = Booking::where('serial_number', $row['serial_number'])->first();

            if ($booking === null) {
                throw $e;
            }

            return DB::transaction(fn () => $this->saveBooking($row, $this->lockedBooking($booking), $userIds));
        }
    }

    /** Reloads the booking locked, so the status merge sees decisions made since the chunk was read. */
    private function lockedBooking(?Booking $booking): ?Booking
    {
        return $booking === null ? null : (Booking::query()->whereKey($booking->id)->lockForUpdate()->first() ?? $booking);
    }

    /** @return array{outcome: string, comments_added: int, attachments_added: int} */
    private function saveBooking(array $row, ?Booking $booking, array $userIds): array
    {
        $isNew = $booking === null;
        $statusChanged = false;

        if ($isNew) {
            $booking = new Booking();
            $booking->forceFill([
                'serial_number' => $row['serial_number'],
                'unit_id' => $row['unit_id'],
                'booking_type_id' => $row['booking_type_id'],
                'user_id' => $userIds[$row['owner_personal_id']],
                'status_id' => $row['status_id'],
                'ext_status_id' => $row['status_id'],
                'status_source' => 'external',
                'status_changed_at' => $row['updated_at'],
                // Nothing is sent back to the mobile app.
                'is_exported' => true,
                'is_status_exported' => true,
                'is_updated_value' => false,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ])->save();
        } else {
            $statusChanged = $this->updateStatus($booking, $row['status_id'], $row['updated_at']);
        }

        $valuesWritten = $this->saveValues($booking, $row['values'], $isNew, $row['updated_at']);

        // Changed values count as a booking change, so open editors see the version move.
        if (!$isNew && $valuesWritten > 0) {
            $this->saveWithoutTouchingTimestamps($booking, ['version' => (int) $booking->version + 1]);
        }

        $commentsAdded = $this->saveComments($booking, $row['comments'], $userIds, $isNew, $row['updated_at']);

        $attachmentsAdded = $this->attachmentService->register($booking, $row['files'], $row['images']);

        if ((int) $booking->booking_type_id === BookingTypeEnum::BARBER_BOOKING->getBookingTypeId()) {
            $this->saveBarberSlot($booking, $row['barber']);
        }

        $outcome = $isNew ? 'created' : (($statusChanged || $valuesWritten > 0 || $commentsAdded > 0 || $attachmentsAdded > 0) ? 'updated' : 'unchanged');

        return ['outcome' => $outcome, 'comments_added' => $commentsAdded, 'attachments_added' => $attachmentsAdded];
    }

    /**
     * Takes the row's status and time unless the saved one is newer. While a decision awaits mobile confirmation the status is
     * merged three-way (StatusConflictResolver) and a crossing change opens a sync conflict instead of being applied.
     */
    private function updateStatus(Booking $booking, int $statusId, Carbon $updatedAt): bool
    {
        // status_changed_at, not updated_at, which a value correction also touches.
        $storedAt = $booking->statusSince();

        $decisionWaiting = $booking->status_source !== 'external'
            && $booking->ext_status_id !== null
            && (int) $booking->ext_status_id !== (int) $booking->status_id;

        $outcome = $decisionWaiting
            ? StatusConflictResolver::resolve((int) $booking->ext_status_id, (int) $booking->status_id, $statusId, $updatedAt->getTimestamp(), $storedAt->getTimestamp(), (string) $booking->status_source)
            : StatusConflictResolver::APPLY;

        switch ($outcome) {
            case StatusConflictResolver::CONFIRM:
                // The mobile app shows our decision: nothing waits any more and any open conflict is closed.
                $this->saveWithoutTouchingTimestamps($booking, ['ext_status_id' => $statusId, 'status_source' => 'external', 'is_status_exported' => true]);
                $this->settleOpenConflict($booking, SyncConflict::CONFIRMED_BY_MOBILE, 'האפליקציה הציגה את הסטטוס שנקבע כאן', $statusId);

                return false;

            case StatusConflictResolver::CONFLICT:
                $this->openConflict($booking, $statusId, $updatedAt);

                return true;

            case StatusConflictResolver::APPLY:
                return $this->applyIncomingStatus($booking, $statusId, $updatedAt, $storedAt, $decisionWaiting);

            default:
                // noop or stale: our decision stands.
                return false;
        }
    }

    /**
     * Applies the row's status unless it is older than the saved one, except when it overrides a decision waiting to be sent.
     * A newer record with the same status does not restart the stage clock.
     */
    private function applyIncomingStatus(Booking $booking, int $statusId, Carbon $updatedAt, Carbon $storedAt, bool $decisionWaiting): bool
    {
        $moves = (int) $booking->status_id !== $statusId;

        if (!$decisionWaiting && ($updatedAt->lt($storedAt) || (!$moves && $updatedAt->eq($storedAt)))) {
            return false;
        }

        if (!$moves) {
            $booking->forceFill(['updated_at' => $updatedAt, 'ext_status_id' => $statusId])->save();

            return true;
        }

        $internalStatusId = (int) $booking->status_id;

        $booking->forceFill([
            'status_id' => $statusId,
            // Where a booking on hold is parked follows the status it has, as for a decision made here: another hold keeps it, any other clears it.
            'parked_status_id' => $booking->parkedStatusFor(StatusBookingEnum::from($statusId)),
            'updated_at' => $updatedAt,
            'ext_status_id' => $statusId,
            'status_source' => 'external',
            'status_changed_at' => $updatedAt,
            'is_status_exported' => true,
            'version' => (int) $booking->version + 1,
        ])->save();

        if ($decisionWaiting) {
            $this->settleOpenConflict($booking, SyncConflict::SUPERSEDED_BY_MOBILE, 'הסטטוס שהגיע מהאפליקציה גובר על ההחלטה שנעשתה כאן', $statusId, $internalStatusId);
        }

        return true;
    }

    /** Closes the open conflict once nothing is left to decide and audits an overridden decision. */
    private function settleOpenConflict(Booking $booking, string $resolution, string $reason, int $statusId, ?int $overriddenStatusId = null): void
    {
        $conflict = SyncConflict::query()->where('booking_id', $booking->id)->where('state', SyncConflict::STATE_OPEN)->first();

        // A confirmed decision without a conflict is the normal case and needs no row.
        if ($conflict === null && $overriddenStatusId === null) {
            return;
        }

        $conflict?->update([
            'state' => SyncConflict::STATE_RESOLVED,
            'resolution' => $resolution,
            'resolved_by' => 'sync:import',
            'resolution_reason' => $reason,
            'resolved_at' => now(),
        ]);

        app(AuditService::class)->record([
            'actor_type' => 'system',
            'actor_id' => 'sync:import',
            'source' => 'automation',
            'action' => 'sync.conflict.auto_resolve',
            'entity_type' => 'booking',
            'entity_id' => $booking->serial_number,
            'booking_serial' => $booking->serial_number,
            'unit_id' => $booking->unit_id,
            'booking_type_id' => $booking->booking_type_id,
            'before' => ['internal_status_id' => $overriddenStatusId ?? (int) $conflict->internal_status_id],
            'after' => ['resolution' => $resolution, 'status_id' => $statusId],
            'reason' => $reason,
        ]);
    }

    /** Holds our decision back and asks a person; the base moves to the mobile status so the same row opens nothing twice. */
    private function openConflict(Booking $booking, int $externalStatusId, Carbon $changedAt): void
    {
        $conflict = SyncConflict::query()->firstOrCreate(
            ['booking_id' => $booking->id, 'state' => SyncConflict::STATE_OPEN],
            [
                'base_status_id' => $booking->ext_status_id,
                'internal_status_id' => $booking->status_id,
                'external_status_id' => $externalStatusId,
                'external_changed_at' => $changedAt,
            ]
        );

        if (!$conflict->wasRecentlyCreated) {
            // The mobile app moved again before anyone decided.
            $conflict->update(['external_status_id' => $externalStatusId, 'external_changed_at' => $changedAt]);
        }

        $before = ['internal_status_id' => (int) $booking->status_id];
        $this->saveWithoutTouchingTimestamps($booking, ['ext_status_id' => $externalStatusId]);

        app(AuditService::class)->record([
            'actor_type' => 'system',
            'actor_id' => 'sync:import',
            'source' => 'automation',
            'action' => 'sync.conflict.detected',
            'entity_type' => 'booking',
            'entity_id' => $booking->serial_number,
            'booking_serial' => $booking->serial_number,
            'unit_id' => $booking->unit_id,
            'booking_type_id' => $booking->booking_type_id,
            'before' => $before,
            'after' => ['external_status_id' => $externalStatusId],
            'result' => 'failed',
        ]);
    }

    private function saveWithoutTouchingTimestamps(Booking $booking, array $attributes): void
    {
        $booking->timestamps = false;
        $booking->forceFill($attributes)->save();
        $booking->timestamps = true;
    }

    /** Adds missing values and updates changed ones. @return int rows written */
    private function saveValues(Booking $booking, array $values, bool $isNew, Carbon $at): int
    {
        if ($values === []) {
            return 0;
        }

        $saved = [];

        if (!$isNew) {
            foreach (DB::table('booking_values')->where('booking_id', $booking->id)->get(['id', 'form_field_id', 'option_field_id', 'value']) as $value) {
                $saved["{$value->form_field_id}|{$value->option_field_id}"] = $value;
            }
        }

        // A pending local correction is not overwritten by an older mobile record.
        $corrected = [];

        if (!$isNew && $booking->is_updated_value) {
            $corrected = FormField::query()
                ->where('editable', true)
                ->whereIn('id', array_values(array_unique(array_column($values, 'form_field_id'))))
                ->pluck('id')
                ->flip()
                ->all();
        }

        $timestamp = $at->format('Y-m-d H:i:s');
        $inserts = [];
        $written = 0;

        foreach ($values as $value) {
            $key = "{$value['form_field_id']}|{$value['option_field_id']}";
            $current = $saved[$key] ?? null;

            if ($current === null) {
                $inserts[] = $value + ['booking_id' => $booking->id, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                continue;
            }

            if (isset($corrected[(int) $value['form_field_id']])) {
                continue;
            }

            if (json_decode((string) $current->value, true) !== json_decode((string) $value['value'], true)) {
                DB::table('booking_values')->where('id', $current->id)->update(['value' => $value['value'], 'updated_at' => $timestamp]);
                $written++;
            }
        }

        if ($inserts !== []) {
            BookingValue::insert($inserts);
        }

        return $written + count($inserts);
    }

    /**
     * Comments carry no id or time: they are matched by author and text, only the surplus is added, and the row's time is used.
     * @return int comments added
     */
    private function saveComments(Booking $booking, array $comments, array $userIds, bool $isNew, Carbon $at): int
    {
        if ($comments === []) {
            return 0;
        }

        $saved = [];

        if (!$isNew) {
            foreach (Comment::where('booking_id', $booking->id)->get(['user_id', 'comment']) as $comment) {
                $key = $comment->user_id . '|' . $comment->comment;
                $saved[$key] = ($saved[$key] ?? 0) + 1;
            }
        }

        $timestamp = $at->format('Y-m-d H:i:s');
        $inserts = [];

        foreach ($comments as $comment) {
            $userId = $userIds[$comment['personal_id']];
            $key = $userId . '|' . $comment['comment'];

            if (($saved[$key] ?? 0) > 0) {
                $saved[$key]--;
                continue;
            }

            $inserts[] = [
                'booking_id' => $booking->id,
                'user_id' => $userId,
                'comment' => $comment['comment'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($inserts !== []) {
            Comment::insert($inserts);
        }

        return count($inserts);
    }

    /** @return array{date: ?string, time: ?string} from the DATE and TIME values */
    private function barberDateAndTime(array $values, array $formFields): array
    {
        $dateAndTime = ['date' => null, 'time' => null];

        foreach ($values as $value) {
            if ($value['option_field_id'] !== null || $value['value'] === null) {
                continue;
            }

            $fieldTypeId = (int) ($formFields[$value['form_field_id']]->field_type_id ?? 0);
            $decoded = json_decode($value['value'], true);

            if ($fieldTypeId === FieldTypeEnum::DATE->value) {
                $dateAndTime['date'] = $decoded;
            } elseif ($fieldTypeId === FieldTypeEnum::TIME->value) {
                $dateAndTime['time'] = $decoded;
            }
        }

        return $dateAndTime;
    }

    /** Saves the barber slot: date, start and end (haircut plus cleaning); is_deleted once the status frees it. */
    private function saveBarberSlot(Booking $booking, array $dateAndTime): void
    {
        $freed = StatusBookingEnum::from((int) $booking->status_id)->freesBarberSlot();

        $changes = ['is_deleted' => $freed];
        $date = is_string($dateAndTime['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateAndTime['date']) === 1 ? $dateAndTime['date'] : null;
        $start = is_string($dateAndTime['time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dateAndTime['time']) === 1 ? $dateAndTime['time'] : null;

        if ($date !== null) {
            $changes['date'] = $date;
        }

        if ($start !== null) {
            $this->barberSlotMinutes ??= $this->barberHaircutTime() + $this->barberCleanTime();

            $changes['start_time'] = $start;
            $changes['end_time'] = Carbon::createFromFormat('H:i', $start)->addMinutes($this->barberSlotMinutes)->format('H:i');
        }

        $slot = BarberBooking::where('booking_id', $booking->id)->first();

        if ($slot !== null) {
            $slot->update($changes);

            return;
        }

        if ($date === null || $start === null) {
            Log::warning("ImportBookingService: barber booking {$booking->serial_number} has no valid date and time, so it has no slot.");

            return;
        }

        BarberBooking::create($changes + ['booking_id' => $booking->id, 'user_id' => $booking->user_id]);
    }

    /** Normalizes the export's single-object, list or null form to a list. */
    private function listOf(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        return array_values(array_filter(array_is_list($value) ? $value : [$value], 'is_array'));
    }

    /** Unix time (number or numeric string) in the app's time zone, or null. */
    private function timeFromUnix(mixed $value): ?Carbon
    {
        if ((!is_int($value) && !is_string($value)) || !is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value, config('app.timezone'));
    }
}
