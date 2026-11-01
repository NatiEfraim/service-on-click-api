<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingValue extends Model
{
    protected $fillable = [
        'booking_id',
        'form_field_id',
        'option_field_id',
        'value',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

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
