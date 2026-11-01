<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemReferenceHasFormField extends Model
{
    protected $fillable = [
        'system_reference_id',
        'form_field_id',
    ];


    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function systemReference()
    {
        return $this->belongsTo(SystemReference::class, 'system_reference_id');
    }
    public function formField()
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }
}
