<?php

namespace App\Domain\Config;

use App\Enums\FieldTypeEnum;

/** Rules for the form of a booking type: editable fields, value checks and structure changes. */
final class FormFieldRules
{
    /** Types an approver may correct while handling a booking (requester fields flagged editable). */
    public const EDITABLE_TYPES = [3, 4];

    /** Types a handler field of a route type may have (written by the handlers during the handling, see FormSectionEnum::HANDLER). */
    public const HANDLER_EDITABLE_TYPES = [2, 3, 4, 5, 7];

    /** Types a new field may have (password, hidden, colour, range, month, week and asterisk number are excluded). */
    public const CREATABLE_TYPES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 14, 20, 21, 23];

    /** Types whose value is chosen from the field's options. */
    public const OPTION_TYPES = [5, 8, 14, 20];

    /** The types that hold an uploaded file or image. */
    public const UPLOAD_TYPES = [13, 23];

    public const LABEL_MIN = 2;
    public const LABEL_MAX = 50;

    /** @return ?string Hebrew error, or null when allowed */
    public static function validateEditable(int $fieldTypeId): ?string
    {
        return in_array($fieldTypeId, self::EDITABLE_TYPES, true) ? null : 'רק שדות תאריך ושעה ניתנים לעריכה על ידי מאשר';
    }

    /**
     * @param array<int, string> $restrictedDates blocked dates (Y-m-d) of the booking type
     * @return ?string Hebrew error, or null when valid
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
     * @param array{field_display_name?: mixed, field_type_id?: mixed, editable?: mixed, options?: mixed} $data
     * @param array<int, array{id: int, field_display_name: string}> $siblings the other active fields of the form
     * @return ?string Hebrew error, or null when valid
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

    /** Name rules: 2-50 chars, basic punctuation, unique in the form. @param array<int, array{id: int, field_display_name: string}> $siblings @param ?int $selfId the field being renamed */
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
     * A choice stays a choice, a plain field stays plain; mobile-app fields and fields holding values keep their type.
     * @param bool $custom whether the field was made here @param int $usedBy records holding a value for it
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

    /** At least one option, no duplicates, each a plain name. @param mixed $options */
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

    /** @return array<int, string> trimmed, empty ones dropped */
    public static function cleanOptions(mixed $options): array
    {
        return array_values(array_filter(
            array_map(fn ($option): string => trim((string) $option), is_array($options) ? $options : []),
            fn (string $option): bool => $option !== ''
        ));
    }

    /**
     * Archived when any booking holds a value, deleted when none does, refused for the last field.
     * @return string blocked | archive | delete
     */
    public static function removalMode(int $usedBy, int $activeSiblingsAfter): string
    {
        if ($activeSiblingsAfter < 1) {
            return 'blocked';
        }

        return $usedBy > 0 ? 'archive' : 'delete';
    }

    /** @return array{is_input: bool, payload_section_id: int} */
    public static function defaultsFor(int $fieldTypeId): array
    {
        $isInput = !in_array($fieldTypeId, self::OPTION_TYPES, true);

        // 0 = none (a choice), 1 = inputs (a typed value or an upload).
        return ['is_input' => $isInput, 'payload_section_id' => $isInput ? 1 : 0];
    }

    /** field_name for a field made here: "int_" plus 12 chars of a uuid. */
    public static function newKey(string $uuid): string
    {
        return 'int_' . substr(str_replace('-', '', $uuid), 0, 12);
    }
}
