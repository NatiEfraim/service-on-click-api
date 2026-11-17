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


    /** Field type ids that support selectable options. */
    public static function selectedFieldTypeIds(): array
    {
        return [
            self::SELECT->value,
            self::RADIO->value,
            self::CHECKBOX->value,
            self::MULTI_SELECT->value,
        ];
    }

    /** File/image type ids; stored on booking_has_files/images, never in booking_value or exports. */
    public static function uploadFieldTypeIds(): array
    {
        return [
            self::FILE->value,
            self::IMAGE->value,
        ];
    }

    public static function isUploadFieldTypeId(?int $fieldTypeId): bool
    {
        return in_array((int) $fieldTypeId, self::uploadFieldTypeIds(), true);
    }

    /** Field type ids used by a booking type's form fields. */
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

    public static function isValidSelectValue(mixed $value): bool
    {
        return is_string($value) || is_int($value);
    }

    public static function isValidText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** int/float, or a digits-only string with no leading 0. */
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

    public static function isValidDate(mixed $value): bool
    {
        return !is_null(self::parseDateValue($value));
    }

    /** A valid date (Y-m-d, d/m/Y or d-m-Y) that is not Friday/Saturday. */
    public static function isValidDateAndNotWeekend(mixed $value): bool
    {
        $date = self::parseDateValue($value);

        if (!$date) {
            return false;
        }

        return !($date->isFriday() || $date->isSaturday());
    }

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


    /** Array of Y-m-d dates, none on Friday/Saturday. */
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
    /** Time as HH:MM. */
    public static function isValidTime(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value);
    }

    /** Israeli phone number (mobile or landline). */
    public static function isValidIsraeliPhone(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^(?:05\d-?\d{7}|0[23489]-?\d{7}|07[2-9]-?\d{7})$/', $value);

    }

    public static function isValidEmail(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isValidUrl(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    public static function isValidArrayValue(mixed $value): bool
    {
        return is_array($value) && !empty($value);
    }

    /** Array of Base64 image Data URIs (png/jpg/jpeg) within the max size. */
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

    /** One Base64 image Data URI, checked against config('app.booking_type.uploads.image'). */
    public static function isValidBase64ImageDataUri(mixed $value): bool
    {
        return self::isValidBase64DataUriAgainstRules(
            value: $value,
            rules: config('app.booking_type.uploads.image'),
        );
    }

    /** Array of Base64 document Data URIs (pdf/docx/xlsx/pptx) within the max size. */
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

    /** One Base64 document Data URI, checked against config('app.booking_type.uploads.file'). */
    public static function isValidBase64FileDataUri(mixed $value): bool
    {
        return self::isValidBase64DataUriAgainstRules(
            value: $value,
            rules: config('app.booking_type.uploads.file'),
        );
    }

    /** Array of document or image Data URIs (e.g. EQUIPMENT_BOOKING files). */
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

    /** Data URI is well-formed, mime allowed and within $rules['max_kb']. */
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

    public static function normalize(
        int $fieldTypeValue,
        mixed $value,
        bool $exportJson = false
    ): string|int|float|null|array
    {

        $value = self::decodeJsonValue($value);

        return match ($fieldTypeValue) {
            self::NUMBER->value => self::normalizeNumberValue(self::unwrapSingleValue($value), $exportJson),
            self::TEXT->value => self::normalizeTextValue(self::unwrapSingleValue($value)),
            self::DATE->value => self::normalizeDateValue(
                value: self::unwrapSingleValue($value),
                exportJson: $exportJson
            ),
            self::TIME->value => self::normalizeTimeValue(self::unwrapSingleValue($value)),
            default => $value,
        };

    }

    /** Decodes a JSON string, else returns the value as is. */
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

    /** Numeric value (string for JSON export), null when not numeric. */
    private static function normalizeNumberValue(
        mixed $value,
        bool $exportJson = false
    ): string|int|float|null {
        if (!is_numeric($value)) {
            return null;
        }

        // Keep a leading zero (e.g. vehicle number 08560002) by returning a string.
        if ($exportJson || (is_string($value) && preg_match('/^0[0-9]+$/', $value))) {
            return (string) $value;
        }

        return 0 + $value;
    }

    /** A single-value field stored wrapped in a one-element list (the web portal's old shape) is its one element. */
    private static function unwrapSingleValue(mixed $value): mixed
    {
        return is_array($value) && array_is_list($value) && count($value) === 1
            ? $value[0]
            : $value;
    }

    private static function normalizeTextValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        // Never "(string) array": that warning used to abort the whole formatting of a booking.
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /** Y-m-d for JSON export, else d/m/Y. */
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

    private static function normalizeTimeValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return Carbon::parse($value)->format('H:i');
    }


    /** Formats a json-decoded DB value by field type id. */
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

    private static function unwrapJsonValue(mixed $raw): mixed
    {
        if (is_array($raw) && array_key_exists('value', $raw) && count($raw) === 1) {
            return $raw['value'];
        }
        return $raw;
    }

    /** Parses via formats or timestamp; $timeOnly places the time on today. */
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
