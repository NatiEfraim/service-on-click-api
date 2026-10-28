<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingHasFile extends Model
{
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'booking_id',
        'file_id',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
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
