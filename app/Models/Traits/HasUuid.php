<?php

namespace App\Models\Traits;

use Illuminate\Support\Str;

/** Gives a model a uuid when it is created without one (the model's table needs a "uuid" column). */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
