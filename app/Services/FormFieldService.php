<?php

namespace App\Services;

use App\Domain\Config\FormFieldRules;
use App\Enums\FieldTypeEnum;
use App\Enums\PayloadSectionEnum;
use App\Exceptions\DomainError;
use App\Models\BookingFilter;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\SystemReferenceHasFormField;
use App\Models\UnitHasBookingType;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The form of a booking type in a unit: add, rename, change, move and remove its fields, and decide which date / time field an approver may
 * correct. Super admin only. Every change is checked, versioned, written to the audit log and queued for the mobile app (ConfigOutboxService) in
 * one transaction, so a refused change leaves nothing behind.
 *
 * The two systems keep their own ids, so a field is known to both by its field_name: the fields the mobile app has keep their names, a field made
 * here gets "int_<12 characters>". A field of the mobile app can be renamed, made required or not and moved, but its type is not changed and it is
 * not removed - the code of the mobile app (and of this system) finds it by name. A booking's values are never deleted with their field: a field
 * that holds any is archived (out of the form, the values stay) and only a field nobody filled is deleted.
 */
class FormFieldService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ConfigOutboxService $configOutbox,
    ) {
    }

    /**
     * Add a field to the end of the form.
     * @param array{field_display_name: string, field_type_id: int, is_required?: bool, editable?: bool, options?: array<int, string>} $data
     * @return array<string, mixed> the field as the screen shows it
     * @throws DomainError 403 not a super admin, 404 the unit does not offer the booking type, 422 the field is not valid
     */
    public function create(AccessScope $scope, int $unitId, int $bookingTypeId, array $data): array
    {
        $this->authorize($scope, 'create');

        if (!UnitHasBookingType::query()->where('unit_id', $unitId)->where('booking_type_id', $bookingTypeId)->exists()) {
            throw new DomainError('לא נמצא', 404);
        }

        if ($error = FormFieldRules::validate($data, $this->siblings($unitId, $bookingTypeId))) {
            throw new DomainError($error, 422);
        }

        return DB::transaction(function () use ($scope, $unitId, $bookingTypeId, $data): array {
            // The form is locked while its place at the end is taken, so two new fields do not get the same one.
            $last = (int) FormField::query()->where('unit_id', $unitId)->where('booking_type_id', $bookingTypeId)->where('is_active', true)->lockForUpdate()->max('position');
            $type = (int) $data['field_type_id'];
            $label = trim($data['field_display_name']);
            $required = (bool) ($data['is_required'] ?? true);

            $field = FormField::query()->create([
                'unit_id' => $unitId,
                'booking_type_id' => $bookingTypeId,
                'field_name' => FormFieldRules::newKey((string) Str::uuid()),
                'field_display_name' => $label,
                'field_type_id' => $type,
                ...FormFieldRules::defaultsFor($type),
                'field_validations' => $required ? ['required' => 'יש לספק ' . $label] : null,
                'is_required' => $required,
                'editable' => (bool) ($data['editable'] ?? false),
                'position' => $last + 1,
                // (Said here, because the model does not hold the database's defaults after the insert and the answer below is read from it.)
                'version' => 1,
                'is_active' => true,
            ]);

            foreach (FormFieldRules::cleanOptions($data['options'] ?? []) as $name) {
                if (!in_array($type, FormFieldRules::OPTION_TYPES, true)) {
                    break;
                }

                OptionField::query()->create([
                    'name' => $name,
                    'form_field_id' => $field->id,
                    'field_type_id' => FieldTypeEnum::TEXT->value,
                    'has_category' => false,
                    'is_input' => false,
                    'payload_section_id' => PayloadSectionEnum::SELECTS->value,
                ]);
            }

            $after = $this->snapshot($field);

            $this->audit->record($this->event('create', $field, null, $after), $scope->user);
            $this->configOutbox->queue('form_field', 'create', [
                'unit_id' => $unitId,
                'booking_type_id' => $bookingTypeId,
                'field_key' => $field->field_name,
                'data' => [
                    'field_display_name' => $after['field_display_name'],
                    'field_type_id' => $after['field_type_id'],
                    'is_required' => $after['is_required'],
                    'editable' => $after['editable'],
                    'position' => $after['position'],
                    'options' => $after['options'],
                ],
            ]);

            return $after;
        });
    }

    /**
     * Change a field's name, type, required or editable. Send only what changes, and the version you saw.
     * @param array{field_display_name?: string, field_type_id?: int, is_required?: bool, editable?: bool} $data
     * @return array<string, mixed>
     * @throws DomainError 403, 404, 409 changed meanwhile, 422 not valid (a type that cannot change, a duplicate name...)
     */
    public function update(AccessScope $scope, int $fieldId, array $data, int $expectedVersion): array
    {
        $this->authorize($scope, 'update', $fieldId);

        FormField::query()->where('is_active', true)->findOrFail($fieldId);

        return DB::transaction(function () use ($scope, $fieldId, $data, $expectedVersion): array {
            $field = FormField::query()->where('is_active', true)->whereKey($fieldId)->lockForUpdate()->firstOrFail();

            if ((int) $field->version !== $expectedVersion) {
                throw new DomainError('השדה שונה בינתיים על ידי משתמש אחר. יש לרענן ולנסות שוב.', 409);
            }

            $before = $this->snapshot($field);

            // What the request asks for, over what the field is now. Only what it CHANGES is checked: a field of the mobile app may carry a name
            // that repeats another's or an editable flag on a type that cannot use it, and an unrelated change (required) must not trip on that.
            $label = array_key_exists('field_display_name', $data) ? trim((string) $data['field_display_name']) : $before['field_display_name'];
            $type = array_key_exists('field_type_id', $data) ? (int) $data['field_type_id'] : $before['field_type_id'];
            $wanted = [
                'field_display_name' => $label,
                'field_type_id' => $type,
                'is_required' => array_key_exists('is_required', $data) ? (bool) $data['is_required'] : $before['is_required'],
                'editable' => array_key_exists('editable', $data) ? (bool) $data['editable'] : $before['editable'],
            ];

            $changed = array_filter($wanted, fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH);

            if ($changed === []) {
                return $before;
            }

            $usedBy = $this->usedBy($field);
            $error = null;

            if (isset($changed['field_display_name'])) {
                $error = FormFieldRules::validateLabel($label, $this->siblings((int) $field->unit_id, (int) $field->booking_type_id), (int) $field->id);
            }

            $error ??= isset($changed['field_type_id']) ? FormFieldRules::validateTypeChange($type, $before['field_type_id'], $usedBy, $field->isCustom()) : null;
            $error ??= isset($changed['editable']) && $wanted['editable'] ? FormFieldRules::validateEditable($type) : null;

            if ($error !== null) {
                throw new DomainError($error, 422);
            }

            $attributes = $changed;

            // The rules of a field of the mobile app are the mobile app's; the one of a field made here follows its name, its type and required flag.
            if ($field->isCustom()) {
                $attributes['field_validations'] = $wanted['is_required'] ? ['required' => 'יש לספק ' . $label] : null;
                $attributes += FormFieldRules::defaultsFor($type);
            }

            $field->forceFill([...$attributes, 'version' => (int) $field->version + 1])->save();

            $after = $this->snapshot($field);
            $renamed = isset($changed['field_display_name']) && $usedBy > 0;

            $this->audit->record($this->event('update', $field, $before, $after, $renamed ? "{$usedBy} קריאות קיימות שומרות את שם השדה הקודם בהיסטוריה" : null), $scope->user);
            $this->configOutbox->queue('form_field', 'update', [
                'unit_id' => (int) $field->unit_id,
                'booking_type_id' => (int) $field->booking_type_id,
                'field_key' => $field->field_name,
                'data' => $changed,
            ]);

            return $after;
        });
    }

    /**
     * Put the fields of a form in a new order: send the ids of ALL the fields in it that stand alone (the ones inside another field follow it).
     * @param array<int, int> $orderedIds
     * @return array{saved: bool, order: array<int, string>} the field names in the new order
     * @throws DomainError 403, 409 the list is not the fields of the form as it is now (somebody changed it)
     */
    public function reorder(AccessScope $scope, int $unitId, int $bookingTypeId, array $orderedIds): array
    {
        $this->authorize($scope, 'reorder');

        $given = array_map('intval', $orderedIds);

        return DB::transaction(function () use ($scope, $unitId, $bookingTypeId, $given): array {
            $fields = FormField::query()
                ->where('unit_id', $unitId)->where('booking_type_id', $bookingTypeId)->where('is_active', true)->whereNull('form_field_id')
                ->lockForUpdate()->get(['id', 'field_name', 'position'])->keyBy('id');

            $current = $fields->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $check = $given;
            sort($check);

            if ($current !== $check) {
                throw new DomainError('רשימת השדות אינה תואמת את הטופס הנוכחי. יש לרענן ולנסות שוב.', 409);
            }

            $before = $fields->sortBy([['position', 'asc'], ['id', 'asc']])->pluck('field_name')->values()->all();

            foreach ($given as $position => $id) {
                FormField::query()->whereKey($id)->update(['position' => $position]);
            }

            $after = array_map(fn (int $id): string => $fields[$id]->field_name, $given);

            if ($before !== $after) {
                $this->audit->record([
                    'action' => 'config.form_field.reorder',
                    'entity_type' => 'form',
                    'entity_id' => "{$unitId}:{$bookingTypeId}",
                    'unit_id' => $unitId,
                    'booking_type_id' => $bookingTypeId,
                    'before' => ['order' => $before],
                    'after' => ['order' => $after],
                ], $scope->user);

                $this->configOutbox->queue('form_field', 'reorder', ['unit_id' => $unitId, 'booking_type_id' => $bookingTypeId, 'order' => $after]);
            }

            return ['saved' => true, 'order' => $after];
        });
    }

    /**
     * Take a field out of the form: archived when any booking holds a value for it, deleted when none does. Never the last field of the form, a
     * field of the mobile app, a field inside another field, or one a setting or a filter depends on.
     * @return array{mode: string, used_by: int}
     * @throws DomainError 403, 404, 409 changed meanwhile / the last field / in use by a setting, 422 a field that cannot be removed
     */
    public function remove(AccessScope $scope, int $fieldId, ?int $expectedVersion = null): array
    {
        $this->authorize($scope, 'remove', $fieldId);

        FormField::query()->where('is_active', true)->findOrFail($fieldId);

        return DB::transaction(function () use ($scope, $fieldId, $expectedVersion): array {
            $field = FormField::query()->where('is_active', true)->whereKey($fieldId)->lockForUpdate()->firstOrFail();

            if ($expectedVersion !== null && (int) $field->version !== $expectedVersion) {
                throw new DomainError('השדה שונה בינתיים על ידי משתמש אחר. יש לרענן ולנסות שוב.', 409);
            }

            if (!$field->isCustom()) {
                throw new DomainError('שדה שקיים באפליקציה אינו ניתן להסרה. אפשר לשנות את שמו, את חובתו ואת מקומו.', 422);
            }

            if ($field->form_field_id !== null || $field->childFormFields()->exists()) {
                throw new DomainError('שדה שהוא חלק משדה אחר אינו ניתן להסרה כאן', 422);
            }

            if (SystemReferenceHasFormField::query()->where('form_field_id', $field->id)->exists() || BookingFilter::query()->where('form_field_id', $field->id)->exists()) {
                throw new DomainError('השדה מקושר להגדרת מערכת או למסנן ואינו ניתן להסרה', 409);
            }

            $usedBy = $this->usedBy($field);
            $left = FormField::query()->where('unit_id', $field->unit_id)->where('booking_type_id', $field->booking_type_id)->where('is_active', true)->whereKeyNot($field->id)->count();
            $mode = FormFieldRules::removalMode($usedBy, $left);

            if ($mode === 'blocked') {
                throw new DomainError('לא ניתן להסיר את השדה האחרון בטופס', 409);
            }

            $before = $this->snapshot($field);

            if ($mode === 'archive') {
                $field->forceFill(['is_active' => false, 'version' => (int) $field->version + 1])->save();
            } else {
                // Nobody filled it: its options go with it (said here, not left to the cascade of one database).
                OptionField::query()->where('form_field_id', $field->id)->delete();
                $field->delete();
            }

            $this->audit->record(
                $this->event($mode, $field, $before, null, $usedBy > 0 ? "השדה מכיל ערכים ב-{$usedBy} קריאות - הוסר מהטופס והערכים נשמרים" : null),
                $scope->user
            );
            $this->configOutbox->queue('form_field', $mode, [
                'unit_id' => (int) $before['unit_id'],
                'booking_type_id' => (int) $before['booking_type_id'],
                'field_key' => $before['field_name'],
            ]);

            return ['mode' => $mode, 'used_by' => $usedBy];
        });
    }

    /**
     * Whether an approver may correct the value of a date / time field while a booking is handled.
     * @return array{id: int, editable: bool, version: int}
     * @throws DomainError 403 not a super admin, 404 no such field, 409 changed meanwhile, 422 not a date or a time
     */
    public function setEditable(AccessScope $scope, int $fieldId, bool $editable, int $expectedVersion): array
    {
        if (!$scope->can('config.global')) {
            $this->audit->denied('config.form_field.editable', 'form_field', $fieldId, 'no_permission', $scope->user);

            throw new DomainError('עריכת הגדרות השדות שמורה למנהל מערכת', 403);
        }

        $field = FormField::query()->where('is_active', true)->findOrFail($fieldId);

        if ($editable && ($error = FormFieldRules::validateEditable((int) $field->field_type_id))) {
            throw new DomainError($error, 422);
        }

        return DB::transaction(function () use ($scope, $fieldId, $editable, $expectedVersion): array {
            $field = FormField::query()->whereKey($fieldId)->lockForUpdate()->firstOrFail();

            if ((int) $field->version !== $expectedVersion) {
                throw new DomainError('השדה שונה בינתיים על ידי משתמש אחר. יש לרענן ולנסות שוב.', 409);
            }

            if ((bool) $field->editable === $editable) {
                return ['id' => $field->id, 'editable' => $editable, 'version' => (int) $field->version];
            }

            $field->forceFill(['editable' => $editable, 'version' => (int) $field->version + 1])->save();

            $this->audit->record([
                'action' => 'config.form_field.editable',
                'entity_type' => 'form_field',
                'entity_id' => $field->field_name,
                'unit_id' => $field->unit_id,
                'booking_type_id' => $field->booking_type_id,
                'before' => ['editable' => !$editable],
                'after' => ['editable' => $editable],
            ], $scope->user);

            $this->configOutbox->queue('form_field', 'update', [
                'unit_id' => (int) $field->unit_id,
                'booking_type_id' => (int) $field->booking_type_id,
                'field_key' => $field->field_name,
                'data' => ['editable' => $editable],
            ]);

            return ['id' => $field->id, 'editable' => $editable, 'version' => (int) $field->version];
        });
    }

    //----------------------------- Private Methods ------------------------//

    /** The fields in the form now (the archived ones are not), for the checks of the name. @return array<int, array{id: int, field_display_name: string}> */
    private function siblings(int $unitId, int $bookingTypeId): array
    {
        return FormField::query()
            ->where('unit_id', $unitId)->where('booking_type_id', $bookingTypeId)->where('is_active', true)
            ->get(['id', 'field_display_name'])
            ->map(fn (FormField $field): array => ['id' => (int) $field->id, 'field_display_name' => (string) $field->field_display_name])
            ->all();
    }

    /** How many records hold something for the field: the bookings with a value, and the files and images uploaded under it. */
    public function usedBy(FormField $field): int
    {
        return BookingValue::query()->where('form_field_id', $field->id)->distinct()->count('booking_id')
            + DB::table('booking_has_files')->where('form_field_id', $field->id)->count()
            + DB::table('booking_has_images')->where('form_field_id', $field->id)->count();
    }

    /** @return array<string, mixed> */
    private function snapshot(FormField $field): array
    {
        return [
            'id' => (int) $field->id,
            'field_name' => (string) $field->field_name,
            'unit_id' => (int) $field->unit_id,
            'booking_type_id' => (int) $field->booking_type_id,
            'field_display_name' => (string) $field->field_display_name,
            'field_type_id' => (int) $field->field_type_id,
            'is_required' => (bool) $field->is_required,
            'editable' => (bool) $field->editable,
            'position' => (int) $field->position,
            'version' => (int) $field->version,
            'is_custom' => $field->isCustom(),
            'options' => OptionField::query()->where('form_field_id', $field->id)->where('is_active', true)->orderBy('id')->pluck('name')->all(),
        ];
    }

    /** @param ?array<string, mixed> $before @param ?array<string, mixed> $after @return array<string, mixed> */
    private function event(string $op, FormField $field, ?array $before, ?array $after, ?string $reason = null): array
    {
        return [
            'action' => "config.form_field.{$op}",
            'entity_type' => 'form_field',
            'entity_id' => $field->field_name,
            'unit_id' => $field->unit_id,
            'booking_type_id' => $field->booking_type_id,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
        ];
    }

    /** @throws DomainError 403 */
    private function authorize(AccessScope $scope, string $op, mixed $id = null): void
    {
        if (!$scope->can('config.global')) {
            $this->audit->denied("config.form_field.{$op}", 'form_field', $id, 'no_permission', $scope->user);

            throw new DomainError('עריכת מבנה הטופס שמורה למנהל מערכת', 403);
        }
    }
}
