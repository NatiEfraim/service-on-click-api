<?php

namespace App\Domain\Config;

use App\Enums\FieldTypeEnum;

/**
 * Rules for the form of a booking type: which fields may be marked editable, what a new value may be, and what may be done to the structure
 * (add, rename, change, move, remove a field). No database access - unit-tested standalone.
 */
final class FormFieldRules
{
    /** The types an approver may correct while a booking is handled: a date and a time (the mobile app lets an approver correct nothing else). */
    public const EDITABLE_TYPES = [3, 4];

    /** The types a new field may have. Left out: password (12), hidden (15), colour (16), range (17), month (18), week (19), number with asterisk (22). */
    public const CREATABLE_TYPES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 14, 20, 21, 23];

    /** The types whose value is chosen from the options of the field (FieldTypeEnum::selectedFieldTypeIds). */
    public const OPTION_TYPES = [5, 8, 14, 20];

    /** The types that hold an uploaded file or image. */
    public const UPLOAD_TYPES = [13, 23];

    public const LABEL_MIN = 2;
    public const LABEL_MAX = 50;

    /** May a field of this type be marked editable? @return ?string the Hebrew error, or null when it may */
    public static function validateEditable(int $fieldTypeId): ?string
    {
        return in_array($fieldTypeId, self::EDITABLE_TYPES, true) ? null : 'רק שדות תאריך ושעה ניתנים לעריכה על ידי מאשר';
    }

    /**
     * Check the new value an approver gives an editable field.
     * @param array<int, string> $restrictedDates blocked dates (Y-m-d) of the booking type
     * @return ?string the Hebrew error, or null when the value is fine
     */
    public static function validateValue(int $fieldTypeId, mixed $value, array $restrictedDates = [], ?string $today = null): ?string
    {
        $value = trim((string) $value);

        if ($fieldTypeId === FieldTypeEnum::DATE->value) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if (!$date || $date->format('Y-m-d') !== $value) {
                return 'תאריך אינו תקין';
            }

            if ($today !== null && $value < $today) {
                return 'לא ניתן לקבוע תאריך שכבר עבר';
            }

            if (in_array($value, $restrictedDates, true)) {
                return 'התאריך חסום להזמנות בתחום זה';
            }

            return null;
        }

        if ($fieldTypeId === FieldTypeEnum::TIME->value) {
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? null : 'שעה אינה תקינה (HH:MM)';
        }

        return 'שדה זה אינו ניתן לעדכון במהלך הטיפול';
    }

    /**
     * Check a field to be added: its name, its type, whether an approver may correct it, and (for a choice) its options.
     * @param array{field_display_name?: mixed, field_type_id?: mixed, editable?: mixed, options?: mixed} $data
     * @param array<int, array{id: int, field_display_name: string}> $siblings the other fields of the form (those in it, not the archived ones)
     * @return ?string the Hebrew error, or null when it is fine
     */
    public static function validate(array $data, array $siblings): ?string
    {
        $type = (int) ($data['field_type_id'] ?? 0);

        if ($error = self::validateLabel((string) ($data['field_display_name'] ?? ''), $siblings)) {
            return $error;
        }

        if (!in_array($type, self::CREATABLE_TYPES, true)) {
            return 'סוג השדה אינו נתמך';
        }

        if (!empty($data['editable']) && ($error = self::validateEditable($type))) {
            return $error;
        }

        if (in_array($type, self::OPTION_TYPES, true)) {
            return self::validateNewOptions($data['options'] ?? []);
        }

        return null;
    }

    /**
     * The name of a field: 2-50 characters, basic punctuation, not the name of another field of the form.
     * @param array<int, array{id: int, field_display_name: string}> $siblings @param ?int $selfId the field being renamed
     */
    public static function validateLabel(string $label, array $siblings, ?int $selfId = null): ?string
    {
        $label = trim($label);

        if (mb_strlen($label) < self::LABEL_MIN || mb_strlen($label) > self::LABEL_MAX) {
            return 'שם השדה חייב להכיל בין ' . self::LABEL_MIN . ' ל-' . self::LABEL_MAX . ' תווים';
        }

        if (!preg_match('/^[\p{Hebrew}a-zA-Z0-9\s"\'\-\(\),\.\/]+$/u', $label)) {
            return 'שם השדה יכול להכיל אותיות, ספרות וסימני פיסוק בסיסיים בלבד';
        }

        foreach ($siblings as $sibling) {
            if ((int) $sibling['id'] !== $selfId && mb_strtolower(trim($sibling['field_display_name'])) === mb_strtolower($label)) {
                return 'כבר קיים שדה בשם זה בטופס';
            }
        }

        return null;
    }

    /**
     * Changing the type of a field. A choice stays a choice and a plain field a plain field (the options would have to appear or go); the type
     * of a field of the mobile app never changes (its code depends on it); a field that holds values keeps its type.
     * @param bool $custom whether the field was made here @param int $usedBy how many records hold something for it
     */
    public static function validateTypeChange(int $newType, int $currentType, int $usedBy, bool $custom): ?string
    {
        if ($newType === $currentType) {
            return null;
        }

        if (!in_array($newType, self::CREATABLE_TYPES, true)) {
            return 'סוג השדה אינו נתמך';
        }

        if (!$custom) {
            return 'סוג של שדה שקיים באפליקציה אינו ניתן לשינוי';
        }

        if (in_array($newType, self::OPTION_TYPES, true) !== in_array($currentType, self::OPTION_TYPES, true)) {
            return 'לא ניתן להפוך שדה בחירה לשדה רגיל או להפך. אפשר להוסיף שדה חדש ולהסיר את הישן';
        }

        if ($usedBy > 0) {
            return "לא ניתן לשנות את סוג השדה: הוא מכיל ערכים ב-{$usedBy} קריאות קיימות. אפשר להוסיף שדה חדש ולהסיר את הישן";
        }

        return null;
    }

    /** The options of a new choice: at least one, none twice, each a plain name. @param mixed $options */
    public static function validateNewOptions(mixed $options): ?string
    {
        $options = self::cleanOptions($options);

        if ($options === []) {
            return 'שדה בחירה חייב לכלול לפחות אפשרות אחת';
        }

        if (count($options) !== count(array_unique(array_map('mb_strtolower', $options)))) {
            return 'קיימות אפשרויות כפולות';
        }

        foreach ($options as $option) {
            if (mb_strlen($option) > 50 || !preg_match('/^[a-zA-Z0-9א-ת\s"\',\.\-()]+$/u', $option)) {
                return 'שם אפשרות אינו תקין (עד 50 תווים, אותיות, ספרות וסימנים בסיסיים)';
            }
        }

        return null;
    }

    /** The names of the options as given: trimmed, the empty ones left out. @return array<int, string> */
    public static function cleanOptions(mixed $options): array
    {
        return array_values(array_filter(
            array_map(fn ($option): string => trim((string) $option), is_array($options) ? $options : []),
            fn (string $option): bool => $option !== ''
        ));
    }

    /**
     * What removing a field does: it is archived when any booking holds a value for it (history is never altered), deleted when none does,
     * and refused when it is the last one in the form.
     * @return string blocked | archive | delete
     */
    public static function removalMode(int $usedBy, int $activeSiblingsAfter): string
    {
        if ($activeSiblingsAfter < 1) {
            return 'blocked';
        }

        return $usedBy > 0 ? 'archive' : 'delete';
    }

    /** The settings of a new field that follow from its type, the way the seeded fields have them. @return array{is_input: bool, payload_section_id: int} */
    public static function defaultsFor(int $fieldTypeId): array
    {
        $isInput = !in_array($fieldTypeId, self::OPTION_TYPES, true);

        // 0 = none (a choice), 1 = inputs (a typed value or an upload).
        return ['is_input' => $isInput, 'payload_section_id' => $isInput ? 1 : 0];
    }

    /** The field_name of a field made here, the same in both systems (the ids are not): "int_" and 12 characters of a uuid. */
    public static function newKey(string $uuid): string
    {
        return 'int_' . substr(str_replace('-', '', $uuid), 0, 12);
    }
}
