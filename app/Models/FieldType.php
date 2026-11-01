<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class FieldType extends Model
{
    protected $fillable = [
        'name',
        'display_name',
    ];

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
