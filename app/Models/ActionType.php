<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActionType extends Model
{
    protected $fillable = [
        'type',
        'display_type',
        'description',
    ];

    protected $hidden = [
        'updated_at',
        'created_at',
    ];


    public function applicationLog()
    {
        return $this->hasMany(ApplicationLog::class);
    }
}
