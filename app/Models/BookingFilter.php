<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingFilter extends Model
{
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'unit_id',
        'booking_type_id',
        'form_field_id',
        'operator',
    ];


    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];



    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }


    public function formField()
    {
        return $this->belongsTo(FormField::class, 'form_field_id')->with(['fieldType']);
    }
}
