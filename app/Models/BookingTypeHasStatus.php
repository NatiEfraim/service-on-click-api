<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingTypeHasStatus extends Model
{
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'booking_type_id',
        'status_id',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'updated_at',
        'created_at',
    ];

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
}
