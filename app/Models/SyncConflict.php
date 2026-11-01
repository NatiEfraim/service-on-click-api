<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The mobile app changed a booking's status while a decision made here was waiting to be sent. */
class SyncConflict extends Model
{
    public const STATE_OPEN = 'open';
    public const STATE_RESOLVED = 'resolved';

    // Resolved by a person.
    public const ACCEPT_EXTERNAL = 'accept_external';
    public const KEEP_INTERNAL = 'keep_internal';

    // Resolved automatically: the mobile app caught up, or moved to an overriding status (e.g. canceled).
    public const CONFIRMED_BY_MOBILE = 'confirmed_by_mobile';
    public const SUPERSEDED_BY_MOBILE = 'superseded_by_mobile';

    protected $fillable = [
        'booking_id',
        'base_status_id',
        'internal_status_id',
        'external_status_id',
        'external_changed_at',
        'state',
        'resolution',
        'resolved_by',
        'resolution_reason',
        'resolved_at',
    ];

    protected $casts = [
        'external_changed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
