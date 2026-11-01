<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A file sent back to the mobile app (r2b file in the bucket) and its outcome. */
class SyncFile extends Model
{
    public const KIND_BOOKING_STATUS = 'booking_status';
    public const KIND_BOOKING_VALUES = 'booking_values';
    /** A configuration change (form structure, options, statements, settings). */
    public const KIND_SYSTEM_CONFIG = 'system_config';

    public const STATE_QUEUED = 'queued';
    public const STATE_SENT = 'sent';
    public const STATE_FAILED = 'failed';
    /** A snapshot that never went out, replaced by a later one. */
    public const STATE_SUPERSEDED = 'superseded';
    /** PayloadGuard found an internal name; held until a person reviews it. */
    public const STATE_BLOCKED = 'blocked';
    /** Config channel is off (SYNC_CONFIG_CHANNEL_ENABLED=false); sent once it is on. */
    public const STATE_AWAITING_SUPPORT = 'awaiting_external_support';

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

    protected $casts = [
        'attempts' => 'integer',
        'record_count' => 'integer',
        'next_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
