<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupBookingType extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'icon',
        'position',
        'topic',
    ];


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
