<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Tracks b2r files already taken; ftp imports skip names present here so nothing is fetched twice. Stream: export_bookings | application_logs | attachments; source: ftp | local | bucket. */
class DigitalHatchFetchedFile extends Model
{
    use HasUuid;

    /** The stream of the files and images of the bookings (the json files are streams of DigitalHatchFileEnum: export_bookings, application_logs). */
    public const STREAM_ATTACHMENTS = 'attachments';

    public const SOURCE_FTP = 'ftp';
    public const SOURCE_LOCAL = 'local';
    public const SOURCE_BUCKET = 'bucket';

    protected $fillable = [
        'uuid',
        'file_name',
        'stream',
        'source',
    ];

    /**
     * Remembers a file as taken. An earlier row of the same name is left as it was, unless $replace says the file was taken again on purpose
     * (--force) and the row should say how it came this time.
     * @throws \RuntimeException when the ledger cannot be written (has its migration been run again?)
     */
    public static function record(string $fileName, string $stream, string $source, bool $replace = false): void
    {
        try {
            if ($replace) {
                static::updateOrCreate(['file_name' => $fileName], ['stream' => $stream, 'source' => $source]);
            } else {
                static::firstOrCreate(['file_name' => $fileName], ['stream' => $stream, 'source' => $source]);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("the ledger digital_hatch_fetched_files cannot be written (has its migration been run again?): {$e->getMessage()}", 0, $e);
        }
    }
}
