<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'name',
        'display_name',
        'position',
    ];


    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function booking()
    {
        return $this->hasMany(Booking::class);
    }
    public function bookingTypeHasStatuses()
    {
        return $this->hasMany(BookingTypeHasStatus::class);
    }

    public function bookingTypes()
    {
        return $this->belongsToMany(BookingType::class, 'booking_type_has_statuses', 'status_id', 'booking_type_id');
    }
}
