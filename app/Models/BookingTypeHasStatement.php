<?php

namespace App\Models;

use App\Enums\StatementShowTimeEnum;
use Illuminate\Database\Eloquent\Model;

class BookingTypeHasStatement extends Model
{

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'unit_id',
        'booking_type_id',
        'statement',
        'is_active',
        'show_time',
    ];


    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',

    ];

    /** Attribute casts. @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'show_time' => StatementShowTimeEnum::class,
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }
}
