<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A file already taken from the digital hatch FTP (by its name there), so it is not fetched again. */
class DigitalHatchFetchedFile extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'file_name',
    ];
}
