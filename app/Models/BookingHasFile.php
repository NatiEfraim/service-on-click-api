<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingHasFile extends Model
{
    protected $fillable = [
        'booking_id',
        'file_id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function file()
    {
        return $this->belongsTo(File::class, 'file_id');
    }
}
