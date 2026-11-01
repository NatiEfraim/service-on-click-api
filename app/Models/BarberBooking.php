<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BarberBooking extends Model
{
    protected $fillable = [
        'user_id',
        'booking_id',
        'start_time',
        'end_time',
        'date',
        'is_deleted',
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
