<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationLog extends Model
{
    protected $fillable = [
        'booking_id',
        'action_type_id',
        'status_id',
        'source',
        'correlation_id',
        'user_id',
        'is_exported',
    ];

    protected $casts = [
        'is_exported' => 'boolean',
    ];

    protected $hidden = [
        'updated_at',
        'created_at',
    ];


    /** Flags only the given logs as exported; logs written mid-export stay pending. */
    public static function markAsExported(array $applicationLogIds): void
    {
        foreach (array_chunk($applicationLogIds, 1000) as $chunk) {
            static::query()
                ->whereIn('id', $chunk)
                ->toBase()
                ->update(['is_exported' => true]);
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
    public function actionType()
    {
        return $this->belongsTo(ActionType::class, 'action_type_id');
    }



}
