<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingHasImage extends Model
{
    protected $fillable = [
        'booking_id',
        'image_id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function image()
    {
        return $this->belongsTo(Image::class, 'image_id');
    }
}
