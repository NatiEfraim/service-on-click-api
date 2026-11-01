<?php

namespace App\Models;

use App\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class BookingTypeHasRole extends Model
{
    protected $fillable = [
        'booking_type_id',
        'role_id',
    ];

    protected $hidden = [
        'updated_at',
        'created_at',
    ];

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /** Role enums used for approver management (excludes SUPER_ADMIN, ADMIN, USER). */
    public static function approverManagementRoleEnums(): Collection
    {
        $excludedRoleIds = [
            RoleEnum::SUPER_ADMIN->value,
            RoleEnum::ADMIN->value,
            RoleEnum::USER->value,
        ];

        return static::query()
            ->whereNotIn('role_id', $excludedRoleIds)
            ->distinct()
            ->pluck('role_id')
            ->map(fn($roleId) => RoleEnum::tryFrom((int) $roleId))
            ->filter()
            ->values();
    }
}
