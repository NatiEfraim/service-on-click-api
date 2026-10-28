<?php

namespace App\Models;

use App\Services\StorageService;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::deleting(function (File $file) {
            if ($file->path) {
                app(StorageService::class)->deleteFile($file->path);
            }
        });
    }

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'name',
        'display_name',
        'path',
        'file_name',
        'is_downloaded',
    ];

    protected $casts = [
        'is_downloaded' => 'boolean',
    ];

    protected $hidden = [
        'id'
    ];

    /** Flag these files as downloaded (sent to Hatch and backed up to export-files). updated_at is not touched. */
    public static function markAsDownloaded(array $fileIds): void
    {
        foreach (array_chunk($fileIds, 1000) as $chunk) {
            static::query()
                ->whereIn('id', $chunk)
                ->toBase()
                ->update(['is_downloaded' => true]);
        }
    }

    public function bookings()
    {
        return $this->belongsToMany(Booking::class, 'booking_has_files', 'file_id', 'booking_id')
            ->withTimestamps();
    }
}

