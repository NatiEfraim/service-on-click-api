<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A version of a file already put on the digital hatch FTP: its name and the sha256 of what was sent. The daily status and users
 * files keep their name while their content grows, so a changed content is sent again and an unchanged one is not.
 */
class DigitalHatchSentFile extends Model
{
    protected $fillable = [
        'file_name',
        'sha256',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
