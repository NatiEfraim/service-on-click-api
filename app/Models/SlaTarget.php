<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Handling target of a booking type: hours from opening to closing. */
class SlaTarget extends Model
{
    /** The only stage in use. */
    public const STAGE_TOTAL = 'total';

    protected $fillable = [
        'booking_type_id',
        'stage',
        'target_hours',
        'warn_ratio',
        'set_by',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'target_hours' => 'float',
        'warn_ratio' => 'float',
    ];

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }
}
