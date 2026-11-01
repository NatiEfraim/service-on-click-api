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

    protected $fillable = [
        'uuid',
        'full_name',
        'personal_number',
        'personal_id',
        'phone',
        'unit_id',
        'department_id',
        'is_deleted',
    ];

    protected $hidden = [
        'updated_at',
        'created_at',
        'is_deleted',
    ];

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

    public function booking()
    {
        return $this->hasMany(Booking::class);
    }

    public function applicationLog()
    {
        return $this->hasMany(ApplicationLog::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /** Identifier used in the audit log and mobile files: personal number, else ID number. */
    public function actorId(): ?string
    {
        return $this->personal_number ?? $this->personal_id;
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
