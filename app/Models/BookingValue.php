<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingValue extends Model
{
    //

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'booking_id',
        'form_field_id',
        'option_field_id',
        'value',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /** Attribute casts. @var array<string, string> */
    protected $casts = [
        'value' => 'array',
    ];



    public function formField()
    {
        return $this->belongsTo(FormField::class, 'form_field_id')->with(['fieldType']);
    }

    public function optionField()
    {
        return $this->belongsTo(OptionField::class, 'option_field_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

}
