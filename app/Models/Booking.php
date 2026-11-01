<?php

namespace App\Models;

use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum;
use Illuminate\Database\Eloquent\Builder;
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
        'version',
        'ext_status_id',
        'status_source',
        'status_changed_at',
        'is_exported',
        'is_status_exported',
        'is_updated_value',
    ];


    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'version' => 'integer',
        'is_exported' => 'boolean',
        'is_status_exported' => 'boolean',
        'is_updated_value' => 'boolean',
    ];

    /** Bookings the user may see: own bookings for plain users (a user with no role counts as plain), the whole unit otherwise. @param Builder $query, User $user @return Builder */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $isPlainUser = $user->roles->every(
            fn($role): bool => (int) $role->id === RoleEnum::USER->value
        );

        return $isPlainUser
            ? $query->where('user_id', $user->id)
            : $query->where('unit_id', $user->unit_id);
    }

    /**
     * The open bookings and those that moved (were closed, say) since the given time. A booking closed before it has nothing left to tell about the
     * stays of the period that begins there, so the figures need not read the whole history. (A booking saved before status_changed_at existed
     * counts from its last update, as statusSince() does.)
     * @param Builder $query, \DateTimeInterface $since @return Builder
     */
    public function scopeOpenOrMovedSince(Builder $query, \DateTimeInterface $since): Builder
    {
        return $query->where(fn (Builder $where) => $where
            ->whereNotIn('status_id', StatusBookingEnum::terminalIds())
            ->orWhere('status_changed_at', '>=', $since)
            ->orWhere(fn (Builder $legacy) => $legacy->whereNull('status_changed_at')->where('updated_at', '>=', $since)));
    }

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

    /** The booking's status as the enum. @return StatusBookingEnum */
    public function statusEnum(): StatusBookingEnum
    {
        return StatusBookingEnum::from((int) $this->status_id);
    }

    /** When the booking was opened, time of day included (created_at reads as a date only). @return Carbon */
    public function openedAt(): Carbon
    {
        return Carbon::parse($this->getRawOriginal('created_at'));
    }

    /** Since when the booking is in its current status; a booking saved before status_changed_at existed counts from its last update. @return Carbon */
    public function statusSince(): Carbon
    {
        return $this->status_changed_at ?? Carbon::parse($this->getRawOriginal('updated_at'));
    }

    /** The booking's timeline: every entry of the application log that moved it into a status, oldest first. */
    public function statusHistory()
    {
        return $this->hasMany(ApplicationLog::class)->whereNotNull('status_id')->orderBy('created_at')->orderBy('id');
    }

    public function syncConflicts()
    {
        return $this->hasMany(SyncConflict::class);
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
