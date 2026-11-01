<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The handling target of a booking type: the total hours from the booking's opening to its closing. */
class SlaTarget extends Model
{
    /** The only stage in use. */
    public const STAGE_TOTAL = 'total';

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'booking_type_id',
        'stage',
        'target_hours',
        'warn_ratio',
        'set_by',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /** Attribute casts. @var array<string, string> */
    protected $casts = [
        'target_hours' => 'float',
        'warn_ratio' => 'float',
    ];

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }
}
