<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupBookingType extends Model
{
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'name',
        'display_name',
        'icon',
        'position',
    ];


    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',

    ];

    public function groupBookingTypeHasBookingType()
    {
        return $this->hasMany(GroupBookingTypeHasBookingType::class);
    }
    public function bookingTypes()
    {
        return $this->belongsToMany(BookingType::class, 'group_booking_type_has_booking_types', 'group_booking_id', 'booking_type_id');
    }
}
