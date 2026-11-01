<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A file this system sends back to the mobile app (an r2b file in the bucket), with what happened to it. */
class SyncFile extends Model
{
    public const KIND_BOOKING_STATUS = 'booking_status';
    public const KIND_BOOKING_VALUES = 'booking_values';
    /** A change of the configuration: the form structure, the options, the statements, the settings. */
    public const KIND_SYSTEM_CONFIG = 'system_config';

    public const STATE_QUEUED = 'queued';
    public const STATE_SENT = 'sent';
    public const STATE_FAILED = 'failed';
    /** A snapshot of the day that never made it, replaced by a later one. */
    public const STATE_SUPERSEDED = 'superseded';
    /** The check on what leaves (PayloadGuard) found an internal name in it: it is not sent until a person has looked at it. */
    public const STATE_BLOCKED = 'blocked';
    /** The configuration channel is switched off (SYNC_CONFIG_CHANNEL_ENABLED=false): the change is kept and goes out when it is switched on. */
    public const STATE_AWAITING_SUPPORT = 'awaiting_external_support';

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'direction',
        'kind',
        'file_name',
        'sha256',
        'state',
        'attempts',
        'next_attempt_at',
        'last_error',
        'record_count',
        'payload',
        'completed_at',
    ];

    /** Attribute casts. @var array<string, string> */
    protected $casts = [
        'attempts' => 'integer',
        'record_count' => 'integer',
        'next_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
