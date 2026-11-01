<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only. Rows are written only by App\Services\AuditService (which keeps the hash chain); a model that is changed or deleted throws. */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    /** Mass-assignable attributes. @var array<int, string> */
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

    /** Attributes hidden for serialization - the hashes are for the chain check, not for the screen. @var array<int, string> */
    protected $hidden = [
        'prev_hash',
        'hash',
    ];

    /** Attribute casts. @var array<string, string> */
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
