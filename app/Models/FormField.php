<?php

namespace App\Models;

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
    ];

    /** field_name prefix of fields created in this system (seeded ones follow the mobile FormFieldEnum). */
    public const CUSTOM_PREFIX = 'int_';

    public function isCustom(): bool
    {
        return str_starts_with((string) $this->field_name, self::CUSTOM_PREFIX);
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
