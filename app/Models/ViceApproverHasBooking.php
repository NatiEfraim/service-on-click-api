<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViceApproverHasBooking extends Model
{
    protected $fillable = [
        'user_id',
        'booking_id',
        'approved_by_vice_approver',
        'is_deleted',
    ];

    protected $hidden = [
        'updated_at',
        'created_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
