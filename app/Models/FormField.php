<?php

namespace App\Models;

use App\Enums\FormSectionEnum;
use App\Enums\SystemDomainEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FormField extends Model
{
    protected $fillable = [
        'unit_id',
        'booking_type_id',
        'field_name',
        'field_display_name',
        'field_type_id',
        'field_validations',
        'is_required',
        'payload_section_id',
        'editable',
        // Optimistic locking, form position, archived flag.
        'version',
        'position',
        'is_active',
        'form_field_id',
        'is_input',
        // Route forms: who fills the field, its width, and the person lookup it triggers / is filled from.
        'section',
        'col_span',
        'lookup_source',
        'autofill_key',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'field_validations' => 'array',
        'editable' => 'boolean',
        'is_required' => 'boolean',
        'is_input' => 'boolean',
        'payload_section_id' => 'integer',
        'version' => 'integer',
        'position' => 'integer',
        'is_active' => 'boolean',
        'col_span' => 'integer',
    ];

    /** field_name prefix of fields created in this system (seeded ones follow the mobile FormFieldEnum). */
    public const CUSTOM_PREFIX = 'int_';

    /**
     * field_name prefix of the mobile-owned "גורם מטפל" fields: the mobile app manages them (its options come from
     * option_field_has_users, a mobile-only table), the web only mirrors them to display imported values. Never offered
     * on the web forms (is_active=0), never edited here and never sent in a form_field/option_field op.
     */
    public const MOBILE_OWNED_PREFIX = 'vice_approver_handler_';

    // ------------------------------------------------------------------------------------------------------
    // The id contract of the three systems (SystemDomainEnum - the b2r booking files carry raw field ids, so a
    // field both the web and the mobile know MUST sit at the same id in both databases; the import maps by id):
    //   1..MOBILE_SHARED_MAX    מפקדת מחנה: the mobile-shared fields, each at its exact mobile id (incl. 106/107).
    //   1001-2999 / 3001-4999   ביטחון מידע / תקשוב: each web-only system's own block (SystemDomainEnum::fieldRange).
    //   WEB_CREATED_FLOOR..     fields created at runtime (the form editor's custom "int_" fields).
    // SystemDomainContractSeeder enforces the bands on every seeding run.
    // ------------------------------------------------------------------------------------------------------

    public const MOBILE_SHARED_MAX = 1000;
    public const WEB_CREATED_FLOOR = 5001;

    /** The next free id inside the given system's field block; its seeders pin every row they create with it. */
    public static function nextSeededIdFor(SystemDomainEnum $domain): int
    {
        [$floor, $ceiling] = $domain->fieldRange();

        $next = max($floor, 1 + (int) self::query()->whereBetween('id', [$floor, $ceiling])->max('id'));

        if ($next > $ceiling) {
            throw new \RuntimeException("FormField: the {$domain->value} field block {$floor}-{$ceiling} is full.");
        }

        return $next;
    }

    /** The next id for a field created at runtime - never auto-increment, which MariaDB resets to max(id)+1 on restart. */
    public static function nextWebCreatedId(): int
    {
        return max(self::WEB_CREATED_FLOOR, 1 + (int) self::query()->max('id'));
    }

    public function isCustom(): bool
    {
        return str_starts_with((string) $this->field_name, self::CUSTOM_PREFIX);
    }

    public function isMobileOwned(): bool
    {
        return str_starts_with((string) $this->field_name, self::MOBILE_OWNED_PREFIX);
    }

    public function sectionEnum(): FormSectionEnum
    {
        return FormSectionEnum::tryFrom((string) $this->section) ?? FormSectionEnum::REQUESTER;
    }

    public function isHandlerField(): bool
    {
        return $this->sectionEnum() === FormSectionEnum::HANDLER;
    }

    /** Fields of one section. */
    public function scopeSection(Builder $query, FormSectionEnum $section): Builder
    {
        return $query->where('section', $section->value);
    }

    /**
     * The validation rules as "rule => Hebrew message", whatever shape the column holds: the editor stores an array; the
     * older seeders stored json_encode() output in the array-cast column, which comes back as a JSON string. Null when none.
     * @return ?array<string, string>
     */
    public function normalizedValidations(): ?array
    {
        $raw = $this->field_validations;

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $rules = [];

        foreach ($raw as $rule => $message) {
            if (is_string($rule) && trim($rule) !== '') {
                $rules[trim($rule)] = is_scalar($message) ? (string) $message : '';
            }
        }

        return $rules === [] ? null : $rules;
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function fieldType()
    {
        return $this->belongsTo(FieldType::class, 'field_type_id');
    }

    public function bookingValue()
    {
        return $this->hasMany(BookingValue::class);
    }

    public function bookingFilter()
    {
        return $this->hasMany(BookingFilter::class);
    }
    public function systemReferenceHasFormField():HasMany
    {
        return $this->hasMany(SystemReferenceHasFormField::class);
    }

    /** Active options only; archived ones stay reachable through BookingValue::optionField. */
    public function optionField()
    {
        return $this->hasMany(OptionField::class)->where('option_fields.is_active', true);
    }

    public function systemReferences()
    {
        return $this->belongsToMany(SystemReference::class, 'system_reference_has_form_fields', 'form_field_id', 'system_reference_id');
    }

    protected static function booted()
    {
        parent::boot();

        static::creating(function (FormField $formField) {
            if (empty($formField->uuid)) {
                $formField->uuid = (string) Str::uuid();
            }
        });
    }
    
    public function parentFormField()
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    public function childFormFields()
    {
        return $this->hasMany(FormField::class, 'form_field_id')->where('form_fields.is_active', true);
    }
}
