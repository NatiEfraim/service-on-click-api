<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Str;

class Booking extends Model
{


    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'uuid',
        'serial_number',
        'unit_id',
        'booking_type_id',
        'user_id',
        'status_id',
        'is_exported',
        'is_status_exported',
        'is_updated_value',
    ];


    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_exported' => 'boolean',
        'is_status_exported' => 'boolean',
        'is_updated_value' => 'boolean',
    ];

    //TODO need to change it
    protected static function booted()
    {
        parent::boot();
        static::creating(function (Booking $booking) {
            if (empty($booking->uuid)) {
                $booking->uuid = (string) Str::uuid();
            }
        });

        static::created(function (Booking $booking) {
            if (is_null($booking->serial_number)) {
                $booking->serial_number = rand(100, 999) . $booking->id;
                $booking->saveQuietly();
            }
        });

        static::deleting(function (Booking $booking) {
            $booking->images->each(function (Image $image) {
                $image->delete();
            });

            $booking->files->each(function (File $file) {
                $file->delete();
            });
        });
    }

    /** Format a date as Y-m-d. @param ?string $date @return ?string */
    public function formatDate(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }

    /** Get created_at formatted as Y-m-d. @param mixed $value @return ?string */
    public function getCreatedAtAttribute($value): ?string
    {
        return $this->formatDate($value);
    }

    /** Get updated_at formatted as Y-m-d. @param mixed $value @return ?string */
    public function getUpdatedAtAttribute($value): ?string
    {
        return $this->formatDate($value);
    }

    /** Flag edited values so the next export resends DATE/TIME values; touches updated_at. @param int $bookingId */
    public static function markValuesAsUpdated(int $bookingId): void
    {
        static::query()
            ->whereKey($bookingId)
            ->update(['is_updated_value' => true]);
    }

    /** Reset export flags; bookings changed after export start stay pending. @param array $bookingIds, \DateTimeInterface $exportStartedAt */
    public static function markAsExported(array $bookingIds, \DateTimeInterface $exportStartedAt): void
    {
        foreach (array_chunk($bookingIds, 1000) as $chunk) {
            static::query()
                ->whereIn('id', $chunk)
                ->where('updated_at', '<', $exportStartedAt)
                ->toBase()
                ->update([
                    'is_exported' => true,
                    'is_status_exported' => true,
                    'is_updated_value' => false,
                ]);
        }
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function bookingValue()
    {
        return $this->hasMany(BookingValue::class);
    }

    public function applicationLog()
    {
        return $this->hasMany(ApplicationLog::class);
    }
    public function viceApprovers()
    {
        return $this->hasMany(ViceApproverHasBooking::class);
    }
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
    public function images()
    {
        return $this->belongsToMany(Image::class, 'booking_has_images', 'booking_id', 'image_id')
            ->withPivot('form_field_id')
            ->withTimestamps();
    }

    public function files()
    {
        return $this->belongsToMany(File::class, 'booking_has_files', 'booking_id', 'file_id')
            ->withPivot('form_field_id')
            ->withTimestamps();
    }
}
