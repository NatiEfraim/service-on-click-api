<?php

namespace App\Services;

use App\Domain\Config\FormFieldRules;
use App\Enums\BookingTypeEnum;
use App\Enums\PermissionEnum;
use App\Enums\StatusBookingEnum;
use App\Enums\SystemReferenceEnum;
use App\Exceptions\DomainError;
use App\Models\Booking;
use App\Models\BookingRestrictionDate;
use App\Models\BookingTypeHasStatement;
use App\Models\BookingValue;
use App\Models\FieldType;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\Status;
use App\Models\SystemReference;
use App\Models\User;
use App\Support\AccessScope;
use App\Traits\CancelsBookingsOnRestrictionDates;
use Carbon\Carbon;

/** Settings screen data for one booking type/unit: references, fields, options, statement, managers, blocking dates. Read-only; changes go through approver/super admin endpoints. */
class SettingsService
{
    use CancelsBookingsOnRestrictionDates;

    public function __construct(private readonly AuditService $audit)
    {
    }

    /** @return array<string, mixed> system_references, form_fields (with used_by), options, statement, managers, open_count, can_edit_global */
    public function view(AccessScope $scope, int $unitId, int $bookingTypeId): array
    {
        if (!$scope->canManageSettings($unitId, $bookingTypeId)) {
            $this->audit->denied('config.view', 'settings', "{$unitId}:{$bookingTypeId}", 'no_permission', $scope->user, ['unit_id' => $unitId, 'booking_type_id' => $bookingTypeId]);

            throw new DomainError('אין לך הרשאה לצפות בהגדרות תחום זה', 403);
        }

        // Archived fields are out of the form; their values stay in the bookings.
        $fields = FormField::query()
            ->where('unit_id', $unitId)
            ->where('booking_type_id', $bookingTypeId)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $usedBy = BookingValue::query()
            ->whereIn('form_field_id', $fields->modelKeys())
            ->selectRaw('form_field_id, COUNT(DISTINCT booking_id) AS bookings')
            ->groupBy('form_field_id')
            ->pluck('bookings', 'form_field_id');

        $parentIds = $fields->pluck('form_field_id')->filter()->unique()->flip();
        $standAlone = $fields->whereNull('form_field_id')->count();

        return [
            'system_references' => SystemReference::query()
                ->where('unit_id', $unitId)
                ->where('booking_type_id', $bookingTypeId)
                ->orderBy('id')
                ->get()
                ->map(fn (SystemReference $reference): array => [
                    'id' => $reference->id,
                    'name' => $reference->name,
                    'title' => $reference->title,
                    'description' => $reference->description,
                    'field_type_id' => $reference->field_type_id,
                    'value' => $reference->decodedValue(),
                ])
                ->all(),
            'form_fields' => $fields->map(fn (FormField $field): array => [
                'id' => $field->id,
                'parent_id' => $field->form_field_id,
                'field_name' => $field->field_name,
                'field_display_name' => $field->field_display_name,
                'field_type_id' => (int) $field->field_type_id,
                'is_required' => $field->is_required,
                'editable' => $field->editable,
                'position' => (int) $field->position,
                'version' => (int) $field->version,
                'used_by' => (int) ($usedBy[$field->id] ?? 0),
                // Custom fields only (not the mobile app's) can change type or be removed.
                'is_custom' => $field->isCustom(),
                'can_change_type' => $field->isCustom() && !isset($usedBy[$field->id]),
                'can_remove' => $field->isCustom() && $field->form_field_id === null && !isset($parentIds[$field->id]) && $standAlone > 1,
            ])->all(),
            // Types offered by the "add a field" form.
            'field_types' => FieldType::query()
                ->whereIn('id', FormFieldRules::CREATABLE_TYPES)
                ->orderBy('id')
                ->get(['id', 'name', 'display_name'])
                ->map(fn (FieldType $type): array => [
                    'id' => (int) $type->id,
                    'name' => $type->name,
                    'display_name' => $type->display_name,
                    'has_options' => in_array((int) $type->id, FormFieldRules::OPTION_TYPES, true),
                    'can_be_editable' => in_array((int) $type->id, FormFieldRules::EDITABLE_TYPES, true),
                ])
                ->all(),
            'options' => OptionField::query()
                ->whereIn('form_field_id', $fields->modelKeys())
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'form_field_id', 'option_field_id', 'name', 'category_name', 'has_category', 'gender'])
                ->map(fn (OptionField $option): array => [
                    'id' => $option->id,
                    'form_field_id' => $option->form_field_id,
                    'parent_id' => $option->option_field_id,
                    'name' => $option->name,
                    'category_name' => $option->category_name,
                    'has_category' => $option->has_category,
                    'gender' => $option->gender,
                ])
                ->all(),
            'statement' => BookingTypeHasStatement::query()
                ->where('unit_id', $unitId)
                ->where('booking_type_id', $bookingTypeId)
                ->first(['id', 'statement', 'is_active', 'show_time']),
            'managers' => $this->managers($unitId, $bookingTypeId),
            'open_count' => Booking::query()
                ->where('unit_id', $unitId)
                ->where('booking_type_id', $bookingTypeId)
                ->whereNotIn('status_id', StatusBookingEnum::terminalIds())
                ->count(),
            'can_edit_global' => $scope->can('config.global'),
            'has_blocking_dates' => BookingTypeEnum::hasRestrictionDates($bookingTypeId),
        ];
    }

    /**
     * The bookings that blocking these dates would cancel, shown before the list is saved; already blocked dates cancel nothing.
     * @param array<int, string> $dates the full list of Y-m-d dates the reference would hold
     * @return array{new_dates: array<int, string>, removed_dates: array<int, string>, affected_count: int, affected_serials: array<int, int>, affected_in_other_units: int, cancel_status_id: int, cancel_status_label: ?string}
     */
    public function restrictionImpact(AccessScope $scope, int $systemReferenceId, array $dates): array
    {
        $reference = SystemReference::query()->findOrFail($systemReferenceId);

        if (!$scope->canManageSettings((int) $reference->unit_id, (int) $reference->booking_type_id)) {
            $this->audit->denied('config.restriction_impact', 'system_reference', $systemReferenceId, 'no_permission', $scope->user);

            throw new DomainError('אין לך הרשאה לנהל הגדרות בתחום זה', 403);
        }

        if (!SystemReferenceEnum::isRestrictionDatesReference((int) $reference->booking_type_id, (int) $reference->id)) {
            throw new DomainError('הגדרה זו אינה רשימת תאריכים חסומים', 422);
        }

        $dates = array_values(array_unique(array_map(
            fn ($date): string => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : throw new DomainError('תאריך אינו תקין', 422),
            $dates
        )));

        $existing = BookingRestrictionDate::query()
            ->where('booking_type_id', $reference->booking_type_id)
            ->get()
            ->map(fn (BookingRestrictionDate $restriction): string => Carbon::parse($restriction->date)->toDateString())
            ->all();

        $newDates = array_values(array_diff($dates, $existing));
        $bookingTypeId = (int) $reference->booking_type_id;
        $cancelStatus = BookingTypeEnum::getCancelStatusForRestrictionDates($bookingTypeId);

        // Blocked dates belong to the booking type, not a unit: a new date cancels EVERY unit's bookings on it.
        // Serial numbers of other units' bookings are shown only to the super admin.
        $affected = Booking::query()
            ->whereIn('id', $this->findBookingIdsOnRestrictedDates($bookingTypeId, $newDates))
            ->orderBy('serial_number')
            ->get(['serial_number', 'unit_id']);

        $listed = $scope->isSuperAdmin() ? $affected : $affected->where('unit_id', $reference->unit_id);
        $serials = $listed->map(fn (Booking $booking): int => (int) $booking->serial_number)->values()->all();

        return [
            'new_dates' => $newDates,
            'removed_dates' => array_values(array_diff($existing, $dates)),
            'affected_count' => $affected->count(),
            'affected_serials' => $serials,
            'affected_in_other_units' => $affected->count() - count($serials),
            'cancel_status_id' => $cancelStatus->value,
            'cancel_status_label' => Status::query()->whereKey($cancelStatus->value)->value('display_name'),
        ];
    }

    /** Unit members holding a managing permission on the type, with their roles on it. @return array<int, array<string, mixed>> */
    private function managers(int $unitId, int $bookingTypeId): array
    {
        $prefixes = [PermissionEnum::ADMIN, PermissionEnum::APPROVER, PermissionEnum::VICE_APPROVER, PermissionEnum::TRANSPORTATION_CONTROL_OFFICER];
        $names = array_map(fn (PermissionEnum $permission): string => $permission->value . $bookingTypeId, $prefixes);

        return User::query()
            ->where('unit_id', $unitId)
            ->where('is_deleted', false)
            ->whereHas('permissions', fn ($permissions) => $permissions->whereIn('name', $names))
            ->with('permissions')
            ->orderBy('full_name')
            ->get()
            ->map(fn (User $user): array => [
                'uuid' => $user->uuid,
                'full_name' => $user->full_name,
                'personal_number' => $user->personal_number,
                'roles' => $user->permissions
                    ->pluck('name')
                    ->filter(fn (string $name): bool => in_array($name, $names, true))
                    ->map(fn (string $name): string => rtrim(substr($name, 0, -strlen((string) $bookingTypeId)), '_'))
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
