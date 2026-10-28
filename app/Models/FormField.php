<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FormField extends Model
{
    //

    /** Mass-assignable attributes. @var array<int, string> */
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
        // Self relation fields
        'form_field_id',
        'is_input',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /** Attribute casts. @var array<string, string> */
    protected $casts = [
        'field_validations' => 'array',
        'editable' => 'boolean',
        'is_required' => 'boolean',
        'is_input' => 'boolean',
        'payload_section_id' => 'integer',
       
    ];



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

    public function optionField()
    {
        return $this->hasMany(OptionField::class);
    }

    public function systemReferences()
    {
        return $this->belongsToMany(SystemReference::class, 'system_reference_has_form_fields', 'form_field_id', 'system_reference_id');
    }

    //--Add self relation
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
        return $this->hasMany(FormField::class, 'form_field_id');
    }
}
