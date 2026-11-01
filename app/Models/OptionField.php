<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OptionField extends Model
{
    //
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'uuid',
        'name',
        'category_name',
        'form_field_id',
        'field_type_id',
        'has_category',
        'payload_section_id',
        'option_field_id',
        'is_input',
        'gender',
        'is_active',
    ];


    protected $casts = [
        'has_category' => 'boolean',
        'is_input' => 'boolean',
        'is_active' => 'boolean',
        'payload_section_id' => 'integer',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected static function booted()
    {
        parent::boot();
        static::creating(function (OptionField $optionField) {
            if (empty($optionField->uuid)) {
                $optionField->uuid = (string) Str::uuid();
            }
        });
    }


    public function formField()
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    public function fieldType()
    {
        return $this->belongsTo(FieldType::class, 'field_type_id');
    }

    public function parentOptionField()
    {
        return $this->belongsTo(OptionField::class, 'option_field_id');
    }

    /** The options below this one that are in the form (archived ones are not). */
    public function childOptionField()
    {
        return $this->hasMany(OptionField::class, 'option_field_id')->where('option_fields.is_active', true);
    }
    public function bookingValue()
    {
        return $this->hasMany(BookingValue::class);
    }

}
