<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActionType extends Model
{
    //


    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'type',
        'display_type',
        'description',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'updated_at',
        'created_at',
    ];


    public function applicationLog()
    {
        return $this->hasMany(ApplicationLog::class);
    }
}
