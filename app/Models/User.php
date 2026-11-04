<?php

namespace App\Models;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Eloquent\Builder;
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

    /** @var ?array<int, string> */
    private ?array $workflowPermissionNames = null;

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
        'is_exported_role',
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
        'is_exported_role' => 'boolean',
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

    /**
     * The roles that put a person out of this user's reach (not listed, no roles or permissions granted or replaced): nobody deals with a
     * super admin through the API, and an admin is the super admin's to manage, so an admin sees neither.
     * @return array<int, string>
     */
    public function unmanageableRoleNames(): array
    {
        $names = [RoleEnum::SUPER_ADMIN->toSpatieRoleName()];

        if (!$this->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName())) {
            $names[] = RoleEnum::ADMIN->toSpatieRoleName();
        }

        return $names;
    }

    /** The people $actor may list and manage; the unit is limited by the caller. Keep in step with isManageableBy(). */
    public function scopeManageableBy(Builder $query, User $actor): Builder
    {
        return $query->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', $actor->unmanageableRoleNames()));
    }

    /** Whether $actor may see this person and change their roles and permissions. Keep in step with scopeManageableBy(). */
    public function isManageableBy(User $actor): bool
    {
        return !$this->hasAnyRole($actor->unmanageableRoleNames());
    }

    /**
     * The person's roles or permissions changed here, so they wait for the users file again (is_exported_role = 0). updated_at moves too: the file
     * carries it, and it is how a file being built right now learns that this person changed (see markRolesExported()).
     * Every code path that grants or takes away a role must call this, inside the transaction that does it.
     */
    public function markRolesChanged(): void
    {
        $this->forceFill(['is_exported_role' => false])->touch();
    }

    /**
     * Sets is_exported_role = 1 on the people whose roles went out in the users file. Someone whose roles changed while the file was built has a
     * newer updated_at (markRolesChanged), so they stay waiting and go in the next file - the check is to the second, as updated_at is.
     * Not touching updated_at: the flag is bookkeeping, not a change of the person.
     * @param array<int, ?string> $versions user id => updated_at as stored when the file was built (UsersExportService::payload)
     * @return int the people marked
     */
    public static function markRolesExported(array $versions): int
    {
        $idsByVersion = [];

        foreach ($versions as $id => $updatedAt) {
            $idsByVersion[(string) $updatedAt][] = $id;
        }

        $marked = 0;

        foreach ($idsByVersion as $updatedAt => $ids) {
            foreach (array_chunk($ids, 2000) as $chunk) {
                $marked += static::query()
                    ->whereIn('id', $chunk)
                    ->where('is_exported_role', false)
                    ->when(
                        (string) $updatedAt === '',
                        fn (Builder $query) => $query->whereNull('updated_at'),
                        fn (Builder $query) => $query->where('updated_at', $updatedAt),
                    )
                    ->toBase()
                    ->update(['is_exported_role' => true]);
            }
        }

        return $marked;
    }

    /**
     * The permission names that decide what the user may do to a booking (Workflow). The super admin holds no "{role}_{type}" permissions,
     * so it acts as the admin of every booking type that has bookings. Read once per user instance: a resource calls this for every row.
     * @return array<int, string>
     */
    public function workflowPermissionNames(): array
    {
        if ($this->workflowPermissionNames !== null) {
            return $this->workflowPermissionNames;
        }

        $names = $this->getAllPermissions()->pluck('name')->all();

        if ($this->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName())) {
            foreach (BookingType::query()->where('is_outsource', false)->pluck('id') as $bookingTypeId) {
                $names[] = PermissionEnum::ADMIN->keyForId((int) $bookingTypeId);
            }
        }

        return $this->workflowPermissionNames = array_values(array_unique($names));
    }
}
