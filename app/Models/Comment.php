<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'booking_id',
        'user_id',
        'comment',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'updated_at',
    ];

    /** Get created_at formatted as d/m/Y. @param mixed $value @return string|null */
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
