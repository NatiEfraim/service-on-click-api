<?php

namespace App\Enums;

use App\Models\FieldType;
use App\Models\FormField;
use Carbon\Carbon;

enum FieldTypeEnum: int
{
    case NUMBER = 1;         
    case TEXT = 2;          
    case DATE = 3;           
    case TIME = 4;         
    case SELECT = 5;        
    case TEXT_BOX = 6;       
    case TEXT_AREA = 7;      
    case RADIO = 8;          
    case EMAIL = 9;         
    case TEL = 10;          
    case URL = 11;          
    case PASSWORD = 12;     
    case FILE = 13;          
    case CHECKBOX = 14;      
    case HIDDEN = 15;      
    case COLOR = 16;       
    case RANGE = 17;       
    case MONTH = 18;        
    case WEEK = 19;
    case MULTI_SELECT = 20;
    case MULTI_DATE = 21;
    case NUMBER_ASTERISK = 22;
    case IMAGE = 23;

    /** Get this field type's DB id by its name. @return ?int */
    public function getFieldTypeId(): ?int
    {
        return match ($this) {
            self::NUMBER => FieldType::where('name', 'number')->value('id') ?? null,
            self::TEXT => FieldType::where('name', 'text')->value('id') ?? null,
            self::DATE => FieldType::where('name', 'date')->value('id') ?? null,
            self::TIME => FieldType::where('name', 'time')->value('id') ?? null,
            self::SELECT => FieldType::where('name', 'select')->value('id') ?? null,
            self::TEXT_BOX => FieldType::where('name', 'text_box')->value('id') ?? null,
            self::TEXT_AREA => FieldType::where('name', 'text_area')->value('id') ?? null,
            self::RADIO => FieldType::where('name', 'radio')->value('id') ?? null,
            self::EMAIL => FieldType::where('name', 'email')->value('id') ?? null,
            self::TEL => FieldType::where('name', 'tel')->value('id') ?? null,
            self::URL => FieldType::where('name', 'url')->value('id') ?? null,
            self::PASSWORD => FieldType::where('name', 'password')->value('id') ?? null,
            self::FILE => FieldType::where('name', 'file')->value('id') ?? null,
            self::CHECKBOX => FieldType::where('name', 'checkbox')->value('id') ?? null,
            self::HIDDEN => FieldType::where('name', 'hidden')->value('id') ?? null,
            self::COLOR => FieldType::where('name', 'color')->value('id') ?? null,
            self::RANGE => FieldType::where('name', 'range')->value('id') ?? null,
            self::MONTH => FieldType::where('name', 'month')->value('id') ?? null,
            self::WEEK => FieldType::where('name', 'week')->value('id') ?? null,
            self::MULTI_SELECT => FieldType::where('name', 'multi_select')->value('id') ?? null,
            self::MULTI_DATE => FieldType::where('name', 'multi_date')->value('id') ?? null,
            self::NUMBER_ASTERISK => FieldType::where('name', 'number_with_asterisk')->value('id') ?? null,
            self::IMAGE => FieldType::where('name', 'image')->value('id') ?? null,
        };
    }


    /** Get field type ids that support selectable options. @return array */
    public static function selectedFieldTypeIds(): array
    {
        return [
            self::SELECT->value,
            self::RADIO->value,
            self::CHECKBOX->value,
            self::MULTI_SELECT->value,
        ];
    }

    /** Get file/image field type ids; stored on booking_has_files/images, never in booking_value or exports. @return array */
    public static function uploadFieldTypeIds(): array
    {
        return [
            self::FILE->value,
            self::IMAGE->value,
        ];
    }

    /** Determine if the given field type id is an uploaded file or image. */
    public static function isUploadFieldTypeId(?int $fieldTypeId): bool
    {
        return in_array((int) $fieldTypeId, self::uploadFieldTypeIds(), true);
    }

    /** Get all field type ids that belong to form fields of a specific booking type. */
    public static function getFieldTypeIdsByBookingTypeId(int $bookingTypeId): array
    {
        return FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->whereNotNull('field_type_id')
            ->pluck('field_type_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    /** Validate a value by its field type id. @param int $fieldTypeId, mixed $value @return bool */
    public static function isValidValueByFieldTypeId(int $fieldTypeId, mixed $value): bool
    {
        return match ($fieldTypeId) {
            self::TEXT->value,
            self::TEXT_BOX->value,
            self::TEXT_AREA->value,
            self::PASSWORD->value,
            self::HIDDEN->value => self::isValidText($value),

            self::NUMBER->value,
            self::RANGE->value => self::isValidNumber($value),

            self::DATE->value => self::isValidDateAndNotWeekend($value),

            self::MULTI_DATE->value => self::isValidMultiDateAndNotWeekend($value),

            self::TIME->value => self::isValidTime($value),

            self::EMAIL->value => self::isValidEmail($value),

            self::TEL->value => self::isValidIsraeliPhone($value),

            self::URL->value => self::isValidUrl($value),


            self::SELECT->value,
            self::RADIO->value => self::isValidSelectValue($value),

            self::CHECKBOX->value,
            self::MULTI_SELECT->value => self::isValidArrayValue($value),

            self::IMAGE->value => self::isValidBase64ImageArray($value),

            default => false,
        };
    }

    /** Validate a select/radio value (string or int). @param mixed $value @return bool */
    public static function isValidSelectValue(mixed $value): bool
    {
        return is_string($value) || is_int($value);
    }

    /** Validate a non-empty text value. @param mixed $value @return bool */
    public static function isValidText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** Validate number value: int/float, or a digits-only string with no leading 0. */
    public static function isValidNumber(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        if (!is_string($value)) {
            return false;
        }

        return preg_match('/^[1-9][0-9]*$/', $value) === 1;
    }

    /** Validate a date in a supported format. @param mixed $value @return bool */
    public static function isValidDate(mixed $value): bool
    {
        return !is_null(self::parseDateValue($value));
    }

    /** Validate that the value is a valid date (Y-m-d, d/m/Y, or d-m-Y) and not Friday/Saturday. */
    public static function isValidDateAndNotWeekend(mixed $value): bool
    {
        $date = self::parseDateValue($value);

        if (!$date) {
            return false;
        }

        return !($date->isFriday() || $date->isSaturday());
    }

    /** Parse date value from supported formats (Y-m-d, d/m/Y, d-m-Y). */
    public static function parseDateValue(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date && $date->format($format) === $value) {
                    return $date->startOfDay();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }


    /** Validate array of Y-m-d dates, none of them Friday or Saturday. */
    public static function isValidMultiDateAndNotWeekend(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $date) {
            if (!self::isValidDateAndNotWeekend($date)) {
                return false;
            }
        }

        return true;
    }
    /** Validate time, expected format HH:MM (e.g. "10:30"). */
    public static function isValidTime(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value);
    }

    /** Validate an Israeli phone number (mobile or landline). @param mixed $value @return bool */
    public static function isValidIsraeliPhone(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^(?:05\d-?\d{7}|0[23489]-?\d{7}|07[2-9]-?\d{7})$/', $value);

    }

    /** Validate an email. @param mixed $value @return bool */
    public static function isValidEmail(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Validate a URL. @param mixed $value @return bool */
    public static function isValidUrl(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    public static function isValidArrayValue(mixed $value): bool
    {
        return is_array($value) && !empty($value);
    }

    /** Validate an array of Base64 image Data URIs (png/jpg/jpeg) within the image max size. @param mixed $value @return bool */
    public static function isValidBase64ImageArray(mixed $value): bool
    {
        if (!is_array($value) || empty($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!self::isValidBase64ImageDataUri($item)) {
                return false;
            }
        }

        return true;
    }

    /** Validate one Base64 image Data URI against config('app.booking_type.uploads.image'). @param mixed $value @return bool */
    public static function isValidBase64ImageDataUri(mixed $value): bool
    {
        return self::isValidBase64DataUriAgainstRules(
            value: $value,
            rules: config('app.booking_type.uploads.image'),
        );
    }

    /** Validate an array of Base64 document Data URIs (pdf/docx/xlsx/pptx) within the file max size. @param mixed $value @return bool */
    public static function isValidBase64FileArray(mixed $value): bool
    {
        if (!is_array($value) || empty($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!self::isValidBase64FileDataUri($item)) {
                return false;
            }
        }

        return true;
    }

    /** Validate one Base64 document Data URI against config('app.booking_type.uploads.file'). @param mixed $value @return bool */
    public static function isValidBase64FileDataUri(mixed $value): bool
    {
        return self::isValidBase64DataUriAgainstRules(
            value: $value,
            rules: config('app.booking_type.uploads.file'),
        );
    }

    /** Validate an array where each item is a document or image Data URI (e.g. EQUIPMENT_BOOKING files). */
    public static function isValidBase64FileOrImageArray(mixed $value): bool
    {
        if (!is_array($value) || empty($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!self::isValidBase64FileDataUri($item) && !self::isValidBase64ImageDataUri($item)) {
                return false;
            }
        }

        return true;
    }

    /** Shared Data URI check: well-formed, mime allowed, and Base64 size within $rules['max_kb']. */
    private static function isValidBase64DataUriAgainstRules(mixed $value, array $rules): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        if (
            !preg_match(
                '/^data:(?<mime>[a-zA-Z0-9.+-]+\/[a-zA-Z0-9.+-]+);base64,(?<data>[A-Za-z0-9+\/]+={0,2})$/',
                trim($value),
                $matches,
            )
        ) {
            return false;
        }

        $allowedMimes = array_map('strtolower', $rules['mimes'] ?? []);

        if (!in_array(strtolower($matches['mime']), $allowedMimes, true)) {
            return false;
        }

        $maxBytes = (int) ($rules['max_kb'] ?? 0) * 1024;

        if ($maxBytes <= 0) {
            return true;
        }

        $base64Data = $matches['data'];
        $padding = substr_count(substr($base64Data, -2), '=');
        $decodedSize = (int) (strlen($base64Data) / 4 * 3) - $padding;

        return $decodedSize <= $maxBytes;
    }

    /** Normalize a raw booking value from DB JSON into a clean PHP type. */
    public static function normalize(
        int $fieldTypeValue,
        mixed $value,
        bool $exportJson = false
    ): string|int|float|null|array
    {

        $value = self::decodeJsonValue($value);

        return match ($fieldTypeValue) {
            self::NUMBER->value => self::normalizeNumberValue($value, $exportJson),
            self::TEXT->value => self::normalizeTextValue($value),
            self::DATE->value => self::normalizeDateValue(
                value: $value,
                exportJson: $exportJson
            ),
            self::TIME->value => self::normalizeTimeValue($value),
            default => $value,
        };

    }

    /** Decode a JSON string if possible, else return the value as is. @param mixed $value @return mixed */
    private static function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
            return $decoded;
        }

        return $value;
    }

    /** Normalize a number (string for JSON export, null if not numeric). @param mixed $value, bool $exportJson @return string|int|float|null */
    private static function normalizeNumberValue(
        mixed $value,
        bool $exportJson = false
    ): string|int|float|null {
        if (!is_numeric($value)) {
            return null;
        }

        // A leading-zero number (e.g. vehicle number 08560002) stays a string so the zero is kept.
        if ($exportJson || (is_string($value) && preg_match('/^0[0-9]+$/', $value))) {
            return (string) $value;
        }

        return 0 + $value;
    }

    /** Cast a value to string. @param mixed $value @return string */
    private static function normalizeTextValue(mixed $value): string
    {
        return is_string($value)
            ? $value
            : (string) $value;
    }

    /** Format a date as Y-m-d for JSON export, else d/m/Y. @param mixed $value, bool $exportJson @return ?string */
    private static function normalizeDateValue(
        mixed $value,
        bool $exportJson = false
    ): ?string {
        $format = $exportJson ? 'Y-m-d' : 'd/m/Y';

        if (is_string($value)) {
            return Carbon::parse($value)->format($format);
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return Carbon::parse($value)->format($format);
        }

        return null;
    }

    /** Format a time string as H:i. @param mixed $value @return ?string */
    private static function normalizeTimeValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return Carbon::parse($value)->format('H:i');
    }


    /** Format an already json-decoded DB value by field type id. @param int $fieldTypeValue, mixed $value @return mixed */
    public static function RetrivedformattedFieldTypeValue(int $fieldTypeValue, mixed $value): mixed
    {
        $value = self::unwrapJsonValue(raw: $value);

        return match ($fieldTypeValue) {
            self::DATE->value => self::formatDate($value),   // "d/m/Y"
            self::TIME->value => self::formatTime($value),   // "H:i:s"
            self::MONTH->value => self::formatMonth($value),  // "m/Y"
            self::WEEK->value => self::formatWeek($value),   // "Y-\WW"
            self::NUMBER->value => self::formatNumber($value),
            default => $value,
        };
    }

    /** Validate that a date value is valid and not Friday/Saturday. */
    public function validateDateValue(mixed $value): bool
    {
        try {
            if (!is_string($value)) {
                return false;
            }

            $date = Carbon::createFromFormat('Y-m-d', $value);

            if ($date->isFriday() || $date->isSaturday()) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    // -------------------- Private functions --------------------

    private static function formatDate(mixed $value): ?string
    {
        $c = self::coerceToCarbon($value, [
            'Y-m-d',
            'd/m/Y',
            'd-m-Y',
            'd-m-y',
            'Y/m/d',
            'Y-m-d H:i:s',
            'd/m/Y H:i:s',
            'd-m-Y H:i:s',
        ]);
        return $c?->format('d/m/Y');
    }

    private static function formatTime(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        $c = self::coerceToCarbon($value, ['H:i:s', 'H:i'], timeOnly: true);
        return $c?->format('H:i:s');
    }

    private static function formatMonth(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->format('m/Y');
        }
        if (is_string($value)) {
            $c = Carbon::createFromFormat('Y-m', $value); 
            if ($c !== false)
                return $c->format('m/Y');

            $c = Carbon::createFromFormat('m/Y', $value);
            if ($c !== false)
                return $c->format('m/Y');
        }
        return null;
    }

    private static function formatWeek(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->format('o-\WW');
        }
        if (is_string($value)) {
            if (preg_match('/^(?<y>\d{4})-W?(?<w>\d{1,2})$/', $value, $m)) {
                $year = (int) $m['y'];
                $week = (int) $m['w'];
                if ($week >= 1 && $week <= 53) {
                    $c = Carbon::now()->setISODate($year, $week, 1);
                    return $c->format('o-\WW');
                }
            }
        }
        return null;
    }

    private static function formatNumber(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    // -------------------- Utilities --------------------

    private static function unwrapJsonValue(mixed $raw): mixed
    {
        if (is_array($raw) && array_key_exists('value', $raw) && count($raw) === 1) {
            return $raw['value'];
        }
        return $raw;
    }

    /** Parse to Carbon via formats/timestamp ($timeOnly: on today). @param mixed $value, array $formats, bool $timeOnly @return ?Carbon */
    private static function coerceToCarbon(mixed $value, array $formats, bool $timeOnly = false): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value)) {
            foreach ($formats as $fmt) {
                $c = Carbon::createFromFormat($fmt, $value);
                if ($c !== false) {
                    return $timeOnly
                        ? Carbon::today()->setTimeFromTimeString($c->format('H:i:s'))
                        : $c;
                }
            }
            try {
                $c = Carbon::parse($value);
                return $timeOnly
                    ? Carbon::today()->setTimeFromTimeString($c->format('H:i:s'))
                    : $c;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
