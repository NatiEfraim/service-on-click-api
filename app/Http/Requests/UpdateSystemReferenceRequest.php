<?php

namespace App\Http\Requests;

use App\Enums\HttpStatusCodeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\SystemReferenceEnum;
use App\Models\SystemReference;
use App\Services\AuditService;
use App\Services\StorageService;
use App\Support\AccessScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UpdateSystemReferenceRequest extends FormRequest
{
    /** The role must manage the booking type being edited (blocking dates cancels bookings); a refusal is audited and answered 403. */
    public function authorize(): bool
    {
        $user = Auth::user();
        $bookingTypeId = (int) $this->input('booking_type_id');

        // No booking type yet: the validation says so.
        if ($user === null || $bookingTypeId <= 0 || (new AccessScope($user))->managesBookingType($bookingTypeId)) {
            return true;
        }

        app(AuditService::class)->denied('config.system_reference.update', 'booking_type', $bookingTypeId, 'no_permission', $user, ['booking_type_id' => $bookingTypeId, 'unit_id' => $user->unit_id]);

        return false;
    }

    public function rules(): array
    {
        try {

            $rules = [
                'booking_type_id' => ['required', 'integer', 'exists:booking_types,id'],
                'details_values' => ['required', 'array'],
            ];

            $bookingTypeId = (int) $this->input('booking_type_id');
            $details = $this->input('details_values');

            if (!is_array($details)) {
                throw ValidationException::withMessages([
                    'message' => 'שליחת בקשת רשת אינה תקינה',
                ]);
            }

            if (
                !$this->validateSystemReferenceIdsByBookingType(
                    bookingTypeId: $bookingTypeId,
                    details: $details
                )
            ) {
                throw ValidationException::withMessages(['message' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים']);
            }

            if (
                !$this->validateSystemReferenceDetails(
                    bookingTypeId: $bookingTypeId,
                    details: $details
                )
            ) {
                throw ValidationException::withMessages(['message' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים']);
            }

            $timeRangeError = $this->validateServiceAndBreakHours(
                bookingTypeId: $bookingTypeId,
                details: $details
            );

            if ($timeRangeError !== null) {
                throw ValidationException::withMessages(['message' => $timeRangeError]);
            }

        } catch (ValidationException $ve) {
            throw $ve;
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            app(StorageService::class)->logToS3();
            throw ValidationException::withMessages(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()])->status(Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return $rules;
    }

    public function messages(): array
    {
        return [
            'booking_type_id.required' => 'יש לספק סוג הזמנה',
            'booking_type_id.exists' => 'פורמט אינו תקין',
        ];
    }

    private function validateSystemReferenceIdsByBookingType(int $bookingTypeId, array $details): bool
    {
        $allowedSystemReferenceIds = SystemReferenceEnum::getSystemReferenceIdsByBookingTypeId($bookingTypeId);

        foreach (array_keys($details) as $systemReferenceId) {
            if (!in_array((int) $systemReferenceId, $allowedSystemReferenceIds, true)) {
                return false;
            }
        }

        return true;
    }

    private function validateSystemReferenceDetails(int $bookingTypeId, array $details): bool
    {
        foreach ($details as $systemReferenceId => $fields) {
            if (!is_array($fields)) {
                return false;
            }

            $record = SystemReference::query()
                ->where('id', $systemReferenceId)
                ->where('unit_id', Auth::user()->unit_id)
                ->where('booking_type_id', $bookingTypeId)
                ->first();

            if (!$record) {
                return false;
            }

            $enum = SystemReferenceEnum::fromSystemReferenceName($record->name);

            if (!$enum) {
                return false;
            }

            if (!$this->validateFieldsForSystemReference(enum: $enum, fields: $fields)) {
                return false;
            }

            if (!$this->validateFieldsAgainstExistingRecord(enum: $enum, record: $record, fields: $fields)) {
                return false;
            }
        }

        return true;
    }

    private function validateFieldsForSystemReference(SystemReferenceEnum $enum, array $fields): bool
    {
        if ($fields === []) {
            return false;
        }

        return match ($enum) {
            SystemReferenceEnum::BARBER_HAIRCUT_TIME => $this->validateHaircutTime($fields),
            SystemReferenceEnum::BARBER_CLEAN_TIME => $this->validateCleanTime($fields),
            SystemReferenceEnum::CAR_MAINTENANCE_DRIVERS_AMOUNT => $this->validateDriversAmount($fields),
            SystemReferenceEnum::IN_UNIT_FOOD_MAX_ADVANCE_DAYS,
            SystemReferenceEnum::OUT_UNIT_FOOD_MAX_ADVANCE_DAYS => $this->validateMaxAdvanceDays($fields),
            default => $this->validateAllowedKeys($fields, $this->allowedKeysBySystemReference($enum)),
        };
    }

    private function allowedKeysBySystemReference(SystemReferenceEnum $enum): array
    {
        return match ($enum) {
            SystemReferenceEnum::CAR_CONTACT_PHONE,
            SystemReferenceEnum::BARBER_CONTACT_PHONE,
            SystemReferenceEnum::EQUIPMENT_MALE_CONTACT_PHONE,
            SystemReferenceEnum::EQUIPMENT_FEMALE_CONTACT_PHONE,
            SystemReferenceEnum::IN_UNIT_FOOD_CONTACT_PHONE,
            SystemReferenceEnum::OUT_UNIT_FOOD_CONTACT_PHONE,
            SystemReferenceEnum::CAR_MAINTENANCE_CONTACT_PHONE,
            SystemReferenceEnum::TRANSPORTATION_CONTACT_PHONE,
            SystemReferenceEnum::CONSTRUCTION_CONTACT_PHONE
            => ['contact_phone'],

            SystemReferenceEnum::CAR_SERVICE_HOURS,
            SystemReferenceEnum::BARBER_SERVICE_HOURS,
            SystemReferenceEnum::BARBER_BREAK_HOURS,
            SystemReferenceEnum::IN_UNIT_FOOD_SERVICE_HOURS,
            SystemReferenceEnum::OUT_UNIT_FOOD_SERVICE_HOURS,
            SystemReferenceEnum::TRANSPORTATION_SERVICE_HOURS
            => ['start_time', 'end_time'],

            SystemReferenceEnum::CAR_RESTRICTIONS,
            SystemReferenceEnum::IN_UNIT_FOOD_RESTRICTIONS,
            SystemReferenceEnum::OUT_UNIT_FOOD_RESTRICTIONS,
            SystemReferenceEnum::BARBER_ADVANCE_TIME
            => ['bookings_limit_time'],

            SystemReferenceEnum::CAR_RESTRICTION_DATES,
            SystemReferenceEnum::BARBER_RESTRICTION_DATES,
            SystemReferenceEnum::IN_UNIT_RESTRICTION_DATES,
            SystemReferenceEnum::OUT_UNIT_RESTRICTION_DATES,
            SystemReferenceEnum::TRANSPORTATION_RESTRICTION_DATES,
            SystemReferenceEnum::CAR_MAINTENANCE_RESTRICTION_DATES
            => ['dates'],

            SystemReferenceEnum::CAR_TOTAL_CAR_STOCK => ['stock_cars'],

            SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_CLOTHING,
            SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_FOOTWEAR,
            SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_RELATED_PRODUCTS,
            SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_CLOTHING,
            SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_FOOTWEAR,
            SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_RELATED_PRODUCTS
            => ['max'],

            SystemReferenceEnum::IN_UNIT_FOOD_PEOPLE_AMOUNT => ['multiple_of'],

            SystemReferenceEnum::IN_UNIT_FOOD_COUNT_BUSINESS_DAYS,
            SystemReferenceEnum::OUT_UNIT_FOOD_COUNT_BUSINESS_DAYS
            => ['count_businsday'],

            SystemReferenceEnum::OUT_UNIT_FOOD_MINIMUM_ORDER_AMOUNT => ['min'],

            SystemReferenceEnum::IN_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS,
            SystemReferenceEnum::OUT_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS
            => ['cancellation_advance_days'],

            SystemReferenceEnum::TRANSPORTATION_MINIMUM_ADVANCE_DAYS => ['minimum_advance_days'],

            default => [],
        };
    }

    /** An empty key set means "unconstrained". */
    private function validateAllowedKeys(array $fields, array $allowedKeys): bool
    {
        if ($allowedKeys === []) {
            return true;
        }

        foreach (array_keys($fields) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                return false;
            }
        }

        return true;
    }

    private function validateHaircutTime(array $fields): bool
    {
        if (!$this->validateAllowedKeys($fields, ['haircut_time'])) {
            return false;
        }

        $haircutTime = $fields['haircut_time'];

        if (!is_numeric($haircutTime)) {
            return false;
        }

        return (int) $haircutTime >= 1;
    }

    private function validateCleanTime(array $fields): bool
    {
        if (!$this->validateAllowedKeys($fields, ['clean_time'])) {
            return false;
        }

        $cleanTime = $fields['clean_time'];

        if (!is_numeric($cleanTime)) {
            return false;
        }

        return (int) $cleanTime >= 0;
    }

    private function validateDriversAmount(array $fields): bool
    {
        if (!$this->validateAllowedKeys($fields, ['drivers_amount'])) {
            return false;
        }

        $driversAmount = $fields['drivers_amount'];

        if (!is_numeric($driversAmount)) {
            return false;
        }

        return (int) $driversAmount >= 1;
    }

    /** Days from today, a whole number of at least 1. */
    private function validateMaxAdvanceDays(array $fields): bool
    {
        if (!$this->validateAllowedKeys($fields, ['max_advance_days'])) {
            return false;
        }

        $maxAdvanceDays = $fields['max_advance_days'];

        if (!is_numeric($maxAdvanceDays) || (int) $maxAdvanceDays != $maxAdvanceDays) {
            return false;
        }

        return (int) $maxAdvanceDays >= 1;
    }

    private function validateFieldsAgainstExistingRecord(SystemReferenceEnum $enum, SystemReference $record, array $fields): bool
    {
        $existingJson = $record->decodedValue();

        foreach ($fields as $key => $value) {
            if (!array_key_exists($key, $existingJson)) {
                return false;
            }

            if (!$this->isValidSystemReferenceFieldValue(enum: $enum, fieldTypeId: (int) $record->field_type_id, value: $value)) {
                return false;
            }
        }

        return true;
    }

    /** count_businsday is a plain boolean, not a checkbox array. */
    private function isValidSystemReferenceFieldValue(SystemReferenceEnum $enum, int $fieldTypeId, mixed $value): bool
    {
        return match ($enum) {
            SystemReferenceEnum::IN_UNIT_FOOD_COUNT_BUSINESS_DAYS,
            SystemReferenceEnum::OUT_UNIT_FOOD_COUNT_BUSINESS_DAYS
            => is_bool($value),

            default => FieldTypeEnum::isValidValueByFieldTypeId(
                fieldTypeId: $fieldTypeId,
                value: $value
            ),
        };
    }

    private function validateServiceAndBreakHours(int $bookingTypeId, array $details): ?string
    {
        foreach ($this->serviceAndBreakHoursPairs() as [$serviceHoursEnum, $breakHoursEnum]) {

            $serviceHours = $this->resolveTimeRange(
                bookingTypeId: $bookingTypeId,
                enum: $serviceHoursEnum,
                details: $details
            );

            if ($serviceHours === null) {
                continue;
            }

            if ($serviceHours['start'] >= $serviceHours['end']) {
                return 'שעת הסיום של שעות הפעילות חייבת להיות מאוחרת משעת ההתחלה';
            }

            if ($breakHoursEnum === null) {
                continue;
            }

            $breakHours = $this->resolveTimeRange(
                bookingTypeId: $bookingTypeId,
                enum: $breakHoursEnum,
                details: $details
            );

            if ($breakHours === null) {
                continue;
            }

            if ($breakHours['start'] >= $breakHours['end']) {
                return 'שעת הסיום של הפסקת הצהריים חייבת להיות מאוחרת משעת ההתחלה';
            }

            if ($breakHours['start'] < $serviceHours['start'] || $breakHours['end'] > $serviceHours['end']) {
                return 'הפסקת הצהריים חייבת להיות בתוך שעות הפעילות';
            }
        }

        return null;
    }

    /** @return array<int, array> pairs of [service-hours reference, its break-hours reference or null] */
    private function serviceAndBreakHoursPairs(): array
    {
        return [
            [SystemReferenceEnum::BARBER_SERVICE_HOURS, SystemReferenceEnum::BARBER_BREAK_HOURS],
            [SystemReferenceEnum::CAR_SERVICE_HOURS, null],
            [SystemReferenceEnum::IN_UNIT_FOOD_SERVICE_HOURS, null],
            [SystemReferenceEnum::OUT_UNIT_FOOD_SERVICE_HOURS, null],
            [SystemReferenceEnum::TRANSPORTATION_SERVICE_HOURS, null],
        ];
    }

    private function resolveTimeRange(int $bookingTypeId, SystemReferenceEnum $enum, array $details): ?array
    {
        $record = SystemReference::query()
            ->where('name', $enum->getSystemReferenceName())
            ->where('unit_id', Auth::user()->unit_id)
            ->where('booking_type_id', $bookingTypeId)
            ->first();

        if (!$record) {
            return null;
        }

        $values = $record->decodedValue();
        $submitted = $details[$record->id] ?? [];

        if (is_array($submitted)) {
            $values = array_replace($values, $submitted);
        }

        $start = $this->toMinutesOfDay($values['start_time'] ?? null);
        $end = $this->toMinutesOfDay($values['end_time'] ?? null);

        if ($start === null || $end === null) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /** "HH:MM" to minutes since midnight; null when invalid. */
    private function toMinutesOfDay(mixed $time): ?int
    {
        if (!is_string($time) || !preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }
}
