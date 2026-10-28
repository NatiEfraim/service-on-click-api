<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{

    //

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'name',
        'is_deleted',
        'created_at',
        'updated_at'
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'is_deleted',
        'created_at',
        'updated_at',
    ];

    public function user()
    {
        return $this->hasMany(User::class);
    }

    public function booking()
    {
        return $this->hasMany(Booking::class);
    }

    public function formField()
    {
        return $this->hasMany(FormField::class);
    }

    public function bookingFilter()
    {
        return $this->hasMany(BookingFilter::class);
    }

    public function systemReference()
    {
        return $this->hasMany(SystemReference::class);
    }

    public function unitHasBookingType()
    {
        return $this->hasMany(UnitHasBookingType::class);
    }
    public function bookingTypeHasStatements()
    {
        return $this->hasMany(BookingTypeHasStatement::class);
    }
}
