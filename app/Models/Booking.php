<?php

namespace App\Models;

use App\Enums\StatusBookingEnum;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Booking extends Model
{
    protected $fillable = [
        'uuid',
        'serial_number',
        'unit_id',
        'booking_type_id',
        'user_id',
        'status_id',
        'version',
        'ext_status_id',
        'parked_status_id',
        'status_source',
        'status_changed_at',
        'is_exported',
        'is_status_exported',
        'is_updated_value',
        'handler_user_id',
        'handler_assigned_at',
        'stage_due_at',
    ];


    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'handler_assigned_at' => 'datetime',
        'stage_due_at' => 'datetime',
        'version' => 'integer',
        'parked_status_id' => 'integer',
        'handler_user_id' => 'integer',
        'is_exported' => 'boolean',
        'is_status_exported' => 'boolean',
        'is_updated_value' => 'boolean',
    ];

    /**
     * What a person may read by uuid: always their own bookings; otherwise only the bookings they manage (their unit, a type
     * they hold a managing or route permission on - see AccessScope::managesBooking); the super admin everything. A request
     * of a route type holds personal data, so "anyone with a role sees the whole unit" (the old rule) is gone.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = new AccessScope($user);

        if ($scope->isSuperAdmin()) {
            return $query;
        }

        $managed = $scope->managedBookingTypeIds();

        return $query->where(fn (Builder $where) => $where
            ->where('user_id', $user->id)
            ->orWhere(fn (Builder $managedBookings) => $managedBookings
                ->where('unit_id', $user->unit_id)
                ->whereIn('booking_type_id', $managed ?: [-1])));
    }

    /** The assigned handler (מטפל) of a route-type request. */
    public function handler()
    {
        return $this->belongsTo(User::class, 'handler_user_id');
    }

    /** Open bookings plus those whose status changed since the given time (legacy rows count from updated_at). */
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

    public function formatDate(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }

    public function getCreatedAtAttribute($value): ?string
    {
        return $this->formatDate($value);
    }

    public function getUpdatedAtAttribute($value): ?string
    {
        return $this->formatDate($value);
    }

    public function statusEnum(): StatusBookingEnum
    {
        return StatusBookingEnum::from((int) $this->status_id);
    }

    /** The status that decides whose turn it is: parked_status_id for a booking on hold, its own otherwise. A hold changes status_id but not stage, buttons or visibility. */
    public function positionStatus(): StatusBookingEnum
    {
        $status = $this->statusEnum();

        if (!$status->isPendingForSupplier() || $this->parked_status_id === null) {
            return $status;
        }

        return StatusBookingEnum::tryFrom((int) $this->parked_status_id) ?? $status;
    }

    /** The hold the booking is on, or null. */
    public function holdStatus(): ?StatusBookingEnum
    {
        $status = $this->statusEnum();

        return $status->isPendingForSupplier() ? $status : null;
    }

    /**
     * What parked_status_id becomes when moving to $target: a hold (8/9/10) parks at the status it was entered from, and
     * hold-to-hold keeps the same park. The external wait (19) RECORDS the same "entered from" - the screens draw the
     * timeline split from it - but stays a real move: positionStatus()/positionSql() ignore it (19 is not in holdIds),
     * so the turn passes to the approver as before. Any other target clears the park.
     */
    public function parkedStatusFor(StatusBookingEnum $target): ?int
    {
        if (!$target->isPendingForSupplier() && $target !== StatusBookingEnum::WAITING_FOR_EXTERNAL_PARTY) {
            return null;
        }

        $status = $this->statusEnum();

        // Already parked (on a hold): the park travels with it, into another hold or into the external wait.
        if ($status->isPendingForSupplier()) {
            return $this->parked_status_id === null ? null : (int) $this->parked_status_id;
        }

        return $status->canBeParked() ? $status->value : null;
    }

    /** The SQL of positionStatus(), for queries over many bookings. */
    public static function positionSql(): string
    {
        $holds = implode(', ', StatusBookingEnum::holdIds());

        return "CASE WHEN bookings.status_id IN ({$holds}) AND bookings.parked_status_id IS NOT NULL THEN bookings.parked_status_id ELSE bookings.status_id END";
    }

    /** Bookings whose position (see positionStatus()) is one of these statuses. @param array<int, int> $statusIds */
    public function scopeAtPosition(Builder $query, array $statusIds): Builder
    {
        return $query->whereIn(DB::raw(self::positionSql()), $statusIds);
    }

    /** Opening time with time of day (created_at reads as a date only). */
    public function openedAt(): Carbon
    {
        return Carbon::parse($this->getRawOriginal('created_at'));
    }

    /** When the current status began; falls back to updated_at for legacy rows. */
    public function statusSince(): Carbon
    {
        return $this->status_changed_at ?? Carbon::parse($this->getRawOriginal('updated_at'));
    }

    /** Timeline: application log entries that set a status, oldest first. */
    public function statusHistory()
    {
        return $this->hasMany(ApplicationLog::class)->whereNotNull('status_id')->orderBy('created_at')->orderBy('id');
    }

    public function syncConflicts()
    {
        return $this->hasMany(SyncConflict::class);
    }

    /** Flags edited values so the next export resends DATE/TIME values. */
    public static function markValuesAsUpdated(int $bookingId): void
    {
        static::query()
            ->whereKey($bookingId)
            ->update(['is_updated_value' => true]);
    }

    /** Resets export flags; bookings changed after the export started stay pending. */
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
