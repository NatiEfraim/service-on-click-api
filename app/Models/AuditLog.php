<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only: written only by AuditService (hash chain); update or delete throws. */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'actor_type',
        'actor_id',
        'actor_name',
        'source',
        'action',
        'entity_type',
        'entity_id',
        'booking_serial',
        'unit_id',
        'booking_type_id',
        'before',
        'after',
        'reason',
        'result',
        'correlation_id',
        'prev_hash',
        'hash',
    ];

    // Hashes serve the chain check, not the screen.
    protected $hidden = [
        'prev_hash',
        'hash',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_log is append-only'));
        static::deleting(fn () => throw new LogicException('audit_log is append-only'));
    }
}
