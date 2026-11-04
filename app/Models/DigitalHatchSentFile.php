<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Tracks r2b files written for the mobile side; content sha256 deduplicates so an unchanged file is not sent again. Destination: bucket | ftp | local (test folder). */
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
