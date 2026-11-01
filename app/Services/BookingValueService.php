<?php

namespace App\Services;

use App\Domain\Config\FormFieldRules;
use App\Domain\Sync\ExportFileNames;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\StatusBookingEnum;
use App\Exceptions\DomainError;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\BookingRestrictionDate;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\SyncConflict;
use App\Models\SyncFile;
use App\Support\AccessScope;
use App\Traits\ResolvesBarberSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Correction of the editable date and time values while a booking is handled. Who may, and when, is the Workflow's rule (the turn of the
 * role); which fields is decided by form_fields.editable. A corrected value is saved here and queued as an r2b file for the mobile app
 * (BookingStatusExportService sends it). A barber booking's date and time are also its schedule slot, so the slot moves with them - and
 * only to a time the barber is free.
 */
class BookingValueService
{
    use ResolvesBarberSchedule;

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @param array<int|string, mixed> $changes form field id => the new value ("Y-m-d" for a date, "H:i" for a time)
     * @return array{version: int, changed: array<int, string>, message: string}
     * @throws DomainError 404 not visible, 403 not allowed now, 409 changed meanwhile or in conflict, 422 invalid
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

    /** The user's to manage, the version they saw is the current one (else "changed", 409 - before "not your turn"), visible at its stage, and the user's turn. @throws DomainError */
    private function assertMayEdit(AccessScope $scope, ?Booking $booking, int $serial, int $expectedVersion, bool $audit): void
    {
        if ($booking === null || !$scope->managesBooking($booking->unit_id, (int) $booking->booking_type_id)) {
            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        if ((int) $booking->version !== $expectedVersion) {
            throw new DomainError('הקריאה עודכנה בינתיים. יש לרענן ולבדוק את המצב העדכני.', 409);
        }

        if (!$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, (int) $booking->status_id)) {
            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        if (!$scope->workflow()->canEditValues($booking->statusEnum(), $scope->permissions, (int) $booking->booking_type_id)) {
            $audit && $this->audit->denied('booking.values.update', 'booking', $serial, 'not_allowed_at_stage', $scope->user, [
                'booking_serial' => $serial,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
            ]);

            throw new DomainError('עדכון פרטי הקריאה אינו זמין לך בשלב הנוכחי', 403);
        }
    }

    /**
     * The barber slot follows the corrected date and time. The new slot must be on a service day, inside the service hours, clear of the break
     * and of every other booking. @param array<int, array{field: FormField, row: BookingValue, before: mixed, after: string}> $edits @throws DomainError
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

        // Minutes since midnight, not "H:i" text: a slot that runs past midnight must not look earlier than the closing time.
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

        // Another booking holds the time only while it holds its slot: a closed one (rejected, canceled, done) does not. The day's slots are
        // locked while the check runs, so two corrections cannot both take the same free time.
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

    /** Queue the corrections as an r2b file; BookingStatusExportService writes it to the bucket. @param array<int, array{field: FormField, row: BookingValue, before: mixed, after: string}> $edits */
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
