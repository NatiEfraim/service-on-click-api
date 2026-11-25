<?php

namespace App\Models;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Support\PermissionRules;
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

    /** Role names that make a person unmanageable: super_admin for everyone; the three system commanders for non-super-admins too. @return array<int, string> */
    public function unmanageableRoleNames(): array
    {
        $names = [RoleEnum::SUPER_ADMIN->toSpatieRoleName()];

        // The commanders of the three systems (admin - מפקדת מחנה, infosec_officer - ביטחון מידע, admin_ict - תקשוב) are peers:
        // none of them sees or changes another (themselves included). Only the super admin manages commanders.
        if (!$this->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName())) {
            $names[] = RoleEnum::ADMIN->toSpatieRoleName();
            $names[] = RoleEnum::INFOSEC_OFFICER->toSpatieRoleName();
            $names[] = RoleEnum::ADMIN_ICT->toSpatieRoleName();
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

    /** Marks is_exported_role=0 and touches updated_at so the next users file picks up this person; call in every transaction that grants or takes a role. */
    public function markRolesChanged(): void
    {
        $this->forceFill(['is_exported_role' => false])->touch();
    }

    /** Sets is_exported_role=1 on people whose roles went in the file; a newer updated_at (changed during the build) keeps them waiting. @param array<int, ?string> $versions user id => updated_at snapshot @return int marked */
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
     * The person's permission names that are real grants (PermissionRules): "{role}_{type}" only where the type has the role. What decides what
     * a person may open, see or press is read from here - a stray permission of a pair the type does not have never counts.
     * @return array<int, string>
     */
    public function validPermissionNames(): array
    {
        return app(PermissionRules::class)->onlyValid($this->getAllPermissions()->pluck('name'));
    }

    /** Permission names that drive Workflow checks; super admin holds none so it acts as admin for every type. Cached per instance. @return array<int, string> */
    public function workflowPermissionNames(): array
    {
        if ($this->workflowPermissionNames !== null) {
            return $this->workflowPermissionNames;
        }

        $names = $this->validPermissionNames();

        if ($this->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName())) {
            foreach (BookingType::query()->where('is_outsource', false)->pluck('id') as $bookingTypeId) {
                $names[] = PermissionEnum::ADMIN->keyForId((int) $bookingTypeId);
            }
        }

        return $this->workflowPermissionNames = array_values(array_unique($names));
    }
}
