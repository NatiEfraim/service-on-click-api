<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A version of an r2b file this system wrote for the mobile side: its name, the sha256 of its content and where it went. The daily status and
 * users files keep their name while their content grows, so a changed content is sent again and an unchanged one is not.
 * destination: bucket (written there for the mobile side to read), ftp, or local (the local test folder that takes the FTP's place).
 */
class DigitalHatchSentFile extends Model
{
    use HasUuid;

    public const DESTINATION_BUCKET = 'bucket';
    public const DESTINATION_FTP = 'ftp';
    public const DESTINATION_LOCAL = 'local';

    protected $fillable = [
        'uuid',
        'file_name',
        'sha256',
        'destination',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /** Whether this exact content of the file already went to that destination. */
    public static function wasSent(string $fileName, string $sha256, string $destination): bool
    {
        return static::where('file_name', $fileName)->where('sha256', $sha256)->where('destination', $destination)->exists();
    }

    /** Remembers that this content of the file went to that destination (again: the time is updated). */
    public static function record(string $fileName, string $sha256, string $destination): void
    {
        static::updateOrCreate(['file_name' => $fileName, 'sha256' => $sha256, 'destination' => $destination], ['sent_at' => now()]);
    }
}
