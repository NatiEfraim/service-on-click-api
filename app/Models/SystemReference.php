<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemReference extends Model
{
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'booking_type_id',
        'unit_id',
        'name',
        'title',
        'description',
        'value',
        'field_type_id',
        'details_values',
        'details_display_names',
        'details_validations',
        'details_types',
        'limit',
    ];


    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /** Attribute casts. @var array<string, string> */
    protected $casts = [
        'details_values' => 'array',
        'details_display_names' => 'array',
        'details_validations' => 'array',
        'details_types' => 'array',
        'value' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];


    /** The value as an array; the 'array' cast still yields a JSON string for double-encoded rows. @return array<string, mixed> */
    public function decodedValue(): array
    {
        $value = $this->value;

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function systemReferenceHasFormField()
    {
        return $this->hasMany(SystemReferenceHasFormField::class);
    }
    public function formFields()
    {
        return $this->belongsToMany(FormField::class, 'system_reference_has_form_fields', 'system_reference_id', 'form_field_id');
    }

    public function fieldType()
    {
        return $this->belongsTo(FieldType::class, 'field_type_id');
    }
}
