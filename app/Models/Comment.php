<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = [
        'booking_id',
        'user_id',
        'comment',
    ];

    protected $hidden = [
        'updated_at',
    ];

    public function getCreatedAtAttribute($value): string|null
    {
        return $value ? Carbon::parse($value)->format('d/m/Y') : null;
    }
    
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
