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

/** Super-admin form management: add, move, archive fields (archived, never deleted; mobile-app fields renamed/moved/required only). Each change is validated, versioned, audited and queued for mobile. */
class FormFieldService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ConfigOutboxService $configOutbox,
    ) {
    }

    /**
     * Adds a field at the end of the form.
     * @param array{field_display_name: string, field_type_id: int, is_required?: bool, editable?: bool, options?: array<int, string>} $data
     * @return array<string, mixed> the field as the screen shows it
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
            // Lock the form so two new fields do not take the same last place.
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
                // Set explicitly: the model does not hold the database defaults after the insert.
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
     * Changes a field's name, type, required or editable flag; send only the changes and the version you saw.
     * @param array{field_display_name?: string, field_type_id?: int, is_required?: bool, editable?: bool} $data
     * @return array<string, mixed>
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

            // Only what the request changes is validated, so an unrelated change ignores a legacy duplicate name.
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

            // Mobile-app fields follow the mobile app's rules; custom fields follow name, type and required flag.
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
     * Reorders the form: send the ids of ALL standalone fields (fields inside another field follow it).
     * @param array<int, int> $orderedIds
     * @return array{saved: bool, order: array<int, string>} field names in the new order
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
     * Archives the field when any booking holds a value for it, deletes it otherwise. Refused for the last field, mobile-app fields,
     * fields inside another field and fields a setting or filter depends on.
     * @return array{mode: string, used_by: int}
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
                // Nobody filled it: delete its options explicitly instead of relying on the database cascade.
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
     * Sets whether an approver may correct this date/time field while handling a booking.
     * @return array{id: int, editable: bool, version: int}
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

    /** @return array<int, array{id: int, field_display_name: string}> the active fields of the form, for name checks */
    private function siblings(int $unitId, int $bookingTypeId): array
    {
        return FormField::query()
            ->where('unit_id', $unitId)->where('booking_type_id', $bookingTypeId)->where('is_active', true)
            ->get(['id', 'field_display_name'])
            ->map(fn (FormField $field): array => ['id' => (int) $field->id, 'field_display_name' => (string) $field->field_display_name])
            ->all();
    }

    /** Counts the bookings with a value for the field plus the files and images uploaded under it. */
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

    private function authorize(AccessScope $scope, string $op, mixed $id = null): void
    {
        if (!$scope->can('config.global')) {
            $this->audit->denied("config.form_field.{$op}", 'form_field', $id, 'no_permission', $scope->user);

            throw new DomainError('עריכת מבנה הטופס שמורה למנהל מערכת', 403);
        }
    }
}
