<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class FieldType extends Model
{
    //

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'name',
        'display_name',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function formField()
    {
        return $this->hasMany(FormField::class);
    }
    public function systemReference()
    {
        return $this->hasMany(SystemReference::class);
    }

}
