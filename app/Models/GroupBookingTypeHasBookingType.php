<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupBookingTypeHasBookingType extends Model
{
    //
        /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'group_booking_id',
        'booking_type_id',
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
    public function groupBookingType()
    {
        return $this->belongsTo(BookingType::class, 'group_booking_id');
    }
}
