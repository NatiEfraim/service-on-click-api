<?php

namespace App\Models;

use App\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected string $guard_name = "passport";

    /** Mass-assignable attributes. @var array<int, string> */
    protected $fillable = [
        'uuid',
        'full_name',
        'personal_number',
        'personal_id',
        'phone',
        'unit_id',
        'is_deleted',
    ];

    /** Attributes hidden for serialization. @var array<int, string> */
    protected $hidden = [
        'updated_at',
        'created_at',
        'is_deleted',
    ];

    /** Attribute casts. @var array<string, string> */
    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    /** Get all bookings made by the user. @return HasMany */
    public function booking()
    {
        return $this->hasMany(Booking::class);
    }

    public function applicationLog()
    {
        return $this->hasMany(ApplicationLog::class);
    }

    /** Get the user's unit. @return BelongsTo */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function viceApproverHasBooking()
    {
        return $this->hasMany(ViceApproverHasBooking::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function getRoleEnum(): ?RoleEnum
    {
        $role = $this->getRoleNames()->first();

        return $role ? RoleEnum::fromSpatieRole($role) : null;
    }
}
