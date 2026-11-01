<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An organisational level under a unit. Internal only: the mobile app does not know departments. */
class Department extends Model
{
    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'unit_id',
        'name',
        'is_active',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /** Attribute casts. @var array<string, string> */
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
