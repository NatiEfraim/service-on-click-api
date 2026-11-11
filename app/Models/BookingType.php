<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingType extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'icon',
        'is_outsource',
        'url',
        'position',
        'has_group',
    ];


    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function booking()
    {
        return $this->hasMany(Booking::class);
    }

    public function systemReference()
    {
        return $this->hasMany(SystemReference::class);
    }

    public function formField()
    {
        return $this->hasMany(FormField::class);
    }
    public function bookingFilter()
    {
        return $this->hasMany(BookingFilter::class);
    }
    public function unitHasBookingType()
    {
        return $this->hasMany(UnitHasBookingType::class);
    }
    public function bookingTypeHasStatements()
    {
        return $this->hasMany(BookingTypeHasStatement::class);
    }
    public function groupBookingTypeHasBookingType()
    {
        return $this->hasMany(GroupBookingTypeHasBookingType::class);
    }
    public function groupBookingTypes()
    {
        return $this->belongsToMany(GroupBookingType::class, 'group_booking_type_has_booking_types', 'booking_type_id', 'group_booking_id');
    }

    public function bookingTypeHasStatuses()
    {
        return $this->hasMany(BookingTypeHasStatus::class);
    }

    public function statuses()
    {
        return $this->belongsToMany(Status::class, 'booking_type_has_statuses', 'booking_type_id', 'status_id');
    }

    public function bookingTypeHasRoles()
    {
        return $this->hasMany(BookingTypeHasRole::class);
    }

    public function roles()
    {
        return $this->belongsToMany(\Spatie\Permission\Models\Role::class, 'booking_type_has_roles', 'booking_type_id', 'role_id');
    }

    public function bookingRestrictionDates(): HasMany
    {
        return $this->hasMany(BookingRestrictionDate::class);
    }
}
