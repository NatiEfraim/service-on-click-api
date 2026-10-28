<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitHasBookingType extends Model
{
    //

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'unit_id',
        'booking_type_id',
    ];


    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'updated_at',
        'created_at',
    ];


    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

}
