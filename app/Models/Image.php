<?php

namespace App\Models;

use App\Services\StorageService;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::deleting(function (Image $image) {
            if ($image->path) {
                app(StorageService::class)->deleteImage($image->path);
            }
        });
    }

    protected $fillable = [
        'name',
        'type',
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

    /** Flag these images as downloaded (sent to Hatch and backed up to export-files). updated_at is not touched. */
    public static function markAsDownloaded(array $imageIds): void
    {
        foreach (array_chunk($imageIds, 1000) as $chunk) {
            static::query()
                ->whereIn('id', $chunk)
                ->toBase()
                ->update(['is_downloaded' => true]);
        }
    }

    public function bookings()
    {
        return $this->belongsToMany(Booking::class, 'booking_has_images', 'image_id', 'booking_id')
            ->withTimestamps();
    }
}
