<?php

namespace App\Services;

use App\Domain\Config\FormFieldRules;
use App\Domain\Sync\ExportFileNames;
use App\Domain\Workflow\TypeRoute;
use App\Enums\ActionTypeEnum;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormSectionEnum;
use App\Enums\StatusBookingEnum;
use App\Exceptions\DomainError;
use App\Models\ApplicationLog;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\BookingRestrictionDate;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\SyncConflict;
use App\Models\SyncFile;
use App\Support\AccessScope;
use App\Support\DueMoment;
use App\Support\FieldValueValidator;
use App\Traits\ResolvesBarberSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Corrects editable date/time values while a booking is handled; queues the change for mobile, moves the barber slot to a free time. */
class BookingValueService
{
    use ResolvesBarberSchedule;

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @param array<int|string, mixed> $changes form field id => new value ("Y-m-d" for a date, "H:i" for a time)
     * @return array{version: int, changed: array<int, string>, message: string}
     */
    public function update(AccessScope $scope, int $serial, array $changes, int $expectedVersion, ?string $reason): array
    {
        $user = $scope->user;

        $this->assertMayEdit($scope, Booking::query()->where('serial_number', $serial)->first(), $serial, $expectedVersion, audit: true);

        if ($changes === []) {
            throw new DomainError('לא בוצע שינוי', 422);
        }

        return DB::transaction(function () use ($scope, $user, $serial, $changes, $expectedVersion, $reason): array {
            $booking = Booking::query()->where('serial_number', $serial)->lockForUpdate()->first();

            $this->assertMayEdit($scope, $booking, $serial, $expectedVersion, audit: false);

            if (SyncConflict::query()->where('booking_id', $booking->id)->where('state', SyncConflict::STATE_OPEN)->exists()) {
                throw new DomainError('נדרשת הכרעה בקריאה זו לפני עדכון נוסף', 409);
            }

            // A type with a route of its own: the handlers write their own section (place, date, time of the interview), nothing goes to the mobile app.
            if (($route = $scope->workflow()->routeFor((int) $booking->booking_type_id)) !== null) {
                return $this->updateHandlerValues($scope, $booking, $route, $changes, $reason, $serial);
            }

            $fields = FormField::query()
                ->where('booking_type_id', $booking->booking_type_id)
                ->where('unit_id', $booking->unit_id)
                ->where('editable', true)
                ->where('is_active', true)
                ->whereIn('id', array_map('intval', array_keys($changes)))
                ->get()
                ->keyBy('id');

            $restrictedDates = BookingRestrictionDate::query()
                ->where('booking_type_id', $booking->booking_type_id)
                ->get()
                ->map(fn (BookingRestrictionDate $restriction): string => Carbon::parse($restriction->date)->toDateString())
                ->all();

            $today = now()->toDateString();
            $edits = [];

            foreach ($changes as $fieldId => $new) {
                $field = $fields->get((int) $fieldId) ?? throw new DomainError('השדה אינו ניתן לעדכון במהלך הטיפול', 422);
                $new = is_string($new) ? trim($new) : $new;

                if ($error = FormFieldRules::validateValue((int) $field->field_type_id, $new, $restrictedDates, $today)) {
                    throw new DomainError("{$field->field_display_name}: {$error}", 422);
                }

                $row = BookingValue::query()
                    ->where('booking_id', $booking->id)
                    ->where('form_field_id', $field->id)
                    ->whereNull('option_field_id')
                    ->first() ?? throw new DomainError("{$field->field_display_name}: אין ערך לעדכן בקריאה זו", 422);

                if ((string) $row->value === (string) $new) {
                    continue;
                }

                $edits[$field->id] = ['field' => $field, 'row' => $row, 'before' => $row->value, 'after' => (string) $new];
            }

            if ($edits === []) {
                throw new DomainError('לא בוצע שינוי', 422);
            }

            if ((int) $booking->booking_type_id === BookingTypeEnum::BARBER_BOOKING->getBookingTypeId()) {
                $this->moveBarberSlot($booking, $edits);
            }

            foreach ($edits as $edit) {
                $edit['row']->update(['value' => $edit['after']]);
            }

            $correlationId = (string) Str::uuid();

            $booking->forceFill(['version' => (int) $booking->version + 1, 'is_updated_value' => true])->save();

            $labels = array_map(fn (array $edit): string => $edit['field']->field_display_name, $edits);

            $this->audit->record([
                'action' => 'booking.values.update',
                'entity_type' => 'booking',
                'entity_id' => $serial,
                'booking_serial' => $serial,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
                'before' => array_combine($labels, array_map(fn (array $edit) => $edit['before'], $edits)),
                'after' => array_combine($labels, array_map(fn (array $edit): string => $edit['after'], $edits)),
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'correlation_id' => $correlationId,
            ], $user);

            $this->queueForMobileApp($booking, $edits, $user->personal_id, $correlationId);

            return ['version' => (int) $booking->version, 'changed' => array_values($labels), 'message' => 'פרטי הקריאה עודכנו'];
        });
    }

    /**
     * The handler section of a route type: upserts the values (the rows may not exist yet), validates each against its field, keeps the interview
     * complete and ahead while the request waits for it (and recomputes the moment the scheduler waits for), logs the edit on the timeline.
     * @param array<int|string, mixed> $changes form field id => new value
     * @return array{version: int, changed: array<int, string>, message: string}
     */
    private function updateHandlerValues(AccessScope $scope, Booking $booking, TypeRoute $route, array $changes, ?string $reason, int $serial): array
    {
        $user = $scope->user;

        $fields = FormField::query()
            ->where('booking_type_id', $booking->booking_type_id)
            ->where('unit_id', $booking->unit_id)
            ->section(FormSectionEnum::HANDLER)
            ->where('is_active', true)
            ->whereIn('field_type_id', FormFieldRules::HANDLER_EDITABLE_TYPES)
            ->get()
            ->keyBy('id');

        $rows = BookingValue::query()
            ->where('booking_id', $booking->id)
            ->whereIn('form_field_id', $fields->keys()->all())
            ->whereNull('option_field_id')
            ->get()
            ->keyBy('form_field_id');

        $scalar = static function (mixed $value): ?string {
            if (is_array($value) && array_is_list($value) && count($value) === 1) {
                $value = $value[0];
            }

            return is_scalar($value) ? (string) $value : null;
        };

        $errors = [];
        $edits = [];

        foreach ($changes as $fieldId => $new) {
            $field = $fields->get((int) $fieldId) ?? throw new DomainError('השדה אינו ניתן לעדכון במהלך הטיפול', 422);
            $new = is_string($new) ? trim($new) : $new;

            foreach (FieldValueValidator::errorsFor($field, $new) as $error) {
                $errors["values.{$field->id}"][] = $error;
            }

            $row = $rows->get($field->id);

            if ($row !== null && $scalar($row->value) === (string) $new) {
                continue;
            }

            $edits[$field->id] = ['field' => $field, 'row' => $row, 'before' => $row === null ? null : $scalar($row->value), 'after' => (string) $new];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($edits === []) {
            throw new DomainError('לא בוצע שינוי', 422);
        }

        // The values as they will be, by field name: the stored ones overlaid with the edits.
        $byName = [];

        foreach ($fields as $field) {
            $value = isset($edits[$field->id]) ? $edits[$field->id]['after'] : $scalar($rows->get($field->id)?->value);

            if ($value !== null && $value !== '') {
                $byName[$field->field_name] = $value;
            }
        }

        $statusId = (int) $booking->status_id;
        $dueAt = DueMoment::for($route, $statusId, $byName);

        // While the request waits for the interview the three fields must stay complete and the moment ahead.
        if (DueMoment::statusHasDueTransition($route, $statusId)) {
            if ($dueAt === null) {
                throw new DomainError('יש להזין את מקום, תאריך ושעת התחקיר', 422);
            }

            if ($dueAt->lessThanOrEqualTo(now())) {
                throw new DomainError('מועד התחקיר חייב להיות עתידי', 422);
            }
        }

        foreach ($edits as $edit) {
            BookingValue::query()->updateOrCreate(
                ['booking_id' => $booking->id, 'form_field_id' => $edit['field']->id],
                ['option_field_id' => null, 'value' => $edit['after']],
            );
        }

        $correlationId = (string) Str::uuid();

        $booking->forceFill([
            'version' => (int) $booking->version + 1,
            ...(DueMoment::statusHasDueTransition($route, $statusId) ? ['stage_due_at' => $dueAt] : []),
        ])->save();

        ApplicationLog::query()->create([
            'booking_id' => $booking->id,
            'action_type_id' => ActionTypeEnum::UPDATE_HANDLER_VALUES->value,
            'status_id' => $statusId,
            'source' => 'internal',
            'correlation_id' => $correlationId,
            'user_id' => $user->id,
            'is_exported' => false,
        ]);

        $labels = array_map(fn (array $edit): string => $edit['field']->field_display_name, $edits);

        $this->audit->record([
            'action' => 'booking.values.update',
            'entity_type' => 'booking',
            'entity_id' => $serial,
            'booking_serial' => $serial,
            'unit_id' => $booking->unit_id,
            'booking_type_id' => $booking->booking_type_id,
            'before' => array_combine($labels, array_map(fn (array $edit) => $edit['before'], $edits)),
            'after' => array_combine($labels, array_map(fn (array $edit): string => $edit['after'], $edits)),
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'correlation_id' => $correlationId,
        ], $user);

        return ['version' => (int) $booking->version, 'changed' => array_values($labels), 'message' => 'פרטי הטיפול עודכנו'];
    }

    /** Checks ownership, version (a stale one answers "changed", 409, before "not your turn"), visibility at its stage and the user's turn. */
    private function assertMayEdit(AccessScope $scope, ?Booking $booking, int $serial, int $expectedVersion, bool $audit): void
    {
        if ($booking === null || !$scope->managesBooking($booking->unit_id, (int) $booking->booking_type_id)) {
            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        if ((int) $booking->version !== $expectedVersion) {
            throw new DomainError('הקריאה עודכנה בינתיים. יש לרענן ולבדוק את המצב העדכני.', 409);
        }

        if (!$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, $booking->positionStatus()->value)) {
            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        // A booking on hold is still the turn of the role it is parked with.
        if (!$scope->workflow()->canEditValues($booking->positionStatus(), $scope->actingPermissions, (int) $booking->booking_type_id)) {
            $audit && $this->audit->denied('booking.values.update', 'booking', $serial, 'not_allowed_at_stage', $scope->user, [
                'booking_serial' => $serial,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
            ]);

            throw new DomainError('עדכון פרטי הקריאה אינו זמין לך בשלב הנוכחי', 403);
        }
    }

    /**
     * Moves the barber slot to the corrected date and time: a service day, inside service hours, clear of the break and other bookings.
     * @param array<int, array{field: FormField, row: BookingValue, before: mixed, after: string}> $edits
     */
    private function moveBarberSlot(Booking $booking, array $edits): void
    {
        $slot = BarberBooking::query()->where('booking_id', $booking->id)->first();

        if ($slot === null) {
            return;
        }

        $date = $slot->date instanceof Carbon ? $slot->date->toDateString() : Carbon::parse($slot->date)->toDateString();
        $start = substr((string) $slot->start_time, 0, 5);
        $dateChanged = false;

        foreach ($edits as $edit) {
            $type = (int) $edit['field']->field_type_id;

            if ($type === FieldTypeEnum::DATE->value) {
                $date = $edit['after'];
                $dateChanged = true;
            } elseif ($type === FieldTypeEnum::TIME->value) {
                $start = $edit['after'];
            }
        }

        if ($dateChanged && !in_array($date, $this->barberServiceDates(), true)) {
            throw new DomainError('התאריך שנבחר אינו יום פעילות של המספרה', 422);
        }

        // Minutes since midnight, not "H:i" text: a slot past midnight must not look earlier than closing.
        $minutes = fn (string $time): int => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
        $service = $this->barberServiceHours();
        $break = $this->barberBreakHours();
        $startAt = $minutes($start);
        $endAt = $startAt + $this->barberHaircutTime() + $this->barberCleanTime();

        if ($startAt < $minutes(Carbon::parse($service['start_time'])->format('H:i')) || $endAt > $minutes(Carbon::parse($service['end_time'])->format('H:i'))) {
            throw new DomainError('השעה שנבחרה מחוץ לשעות הפעילות של המספרה', 422);
        }

        if ($startAt < $minutes(Carbon::parse($break['end_time'])->format('H:i')) && $endAt > $minutes(Carbon::parse($break['start_time'])->format('H:i'))) {
            throw new DomainError('השעה שנבחרה חופפת להפסקת המספרה', 422);
        }

        // Inside the service hours, so it is still the same day.
        $end = sprintf('%02d:%02d', intdiv($endAt, 60), $endAt % 60);

        // Closed bookings (rejected, canceled, done) do not hold their slot; the day's slots are
        // locked during the check so two corrections cannot take the same free time.
        $slotFreeingStatuses = array_values(array_unique([...StatusBookingEnum::terminalIds(), ...array_map(
            fn (StatusBookingEnum $status): int => $status->value,
            array_filter(StatusBookingEnum::cases(), fn (StatusBookingEnum $status): bool => $status->freesBarberSlot())
        )]));

        $taken = BarberBooking::query()
            ->where('is_deleted', false)
            ->where('booking_id', '!=', $booking->id)
            ->whereDate('date', $date)
            ->whereHas('booking', fn ($holder) => $holder->whereNotIn('status_id', $slotFreeingStatuses))
            ->where(fn ($overlaps) => $overlaps
                ->where(fn ($range) => $range->where('start_time', '<', $end . ':00')->where('end_time', '>', $start . ':00'))
                ->orWhere(fn ($open) => $open->whereNull('end_time')->where('start_time', $start . ':00')))
            ->lockForUpdate()
            ->first(['id']) !== null;

        if ($taken) {
            throw new DomainError('השעה שנבחרה תפוסה', 422);
        }

        $slot->update(['date' => $date, 'start_time' => $start . ':00', 'end_time' => $end . ':00']);
    }

    /** Queues the corrections as an r2b file for BookingStatusExportService. @param array<int, array{field: FormField, row: BookingValue, before: mixed, after: string}> $edits */
    private function queueForMobileApp(Booking $booking, array $edits, ?string $personalId, string $correlationId): void
    {
        $changedAt = now();

        $json = json_encode([
            'changes' => array_values(array_map(fn (array $edit): array => [
                'serial_number' => (int) $booking->serial_number,
                'field_id' => (int) $edit['field']->id,
                'value' => $edit['after'],
                'personal_id' => $personalId !== null ? preg_replace('/\D/', '', $personalId) : null,
                'changed_at' => $changedAt->getTimestamp(),
                'correlation_id' => $correlationId,
            ], $edits)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        SyncFile::query()->create([
            'direction' => 'outbound',
            'kind' => SyncFile::KIND_BOOKING_VALUES,
            'file_name' => ExportFileNames::bookingValuesFile($changedAt, $correlationId),
            'sha256' => hash('sha256', $json),
            'state' => SyncFile::STATE_QUEUED,
            'record_count' => count($edits),
            'payload' => $json,
        ]);
    }
}
