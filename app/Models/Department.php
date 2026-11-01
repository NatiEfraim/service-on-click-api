<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An organisational level under a unit (internal only; unknown to the mobile app). */
class Department extends Model
{
    protected $fillable = [
        'unit_id',
        'name',
        'is_active',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'department_id');
    }
}
