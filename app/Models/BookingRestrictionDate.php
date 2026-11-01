<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingRestrictionDate extends Model
{
    protected $fillable = [
        'booking_type_id',
        'date',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class);
    }
}
