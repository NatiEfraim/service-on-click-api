<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;
use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\AuditService;
use App\Support\PermissionRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Takes away the "{role}_{booking type id}" permissions of a role the booking type does not have (infosec_officer_1: the shared cars have no
 * infosec officer). They come from nowhere the screens know - an old seeder run, a manual grant - and the API ignores them (PermissionRules),
 * but they are still stored and still shown to whoever reads the database. Without --apply nothing is changed: it only lists them.
 */
class PrunePermissionsCommand extends Command
{
    protected $signature = 'permissions:prune-invalid
        {--apply : Remove them (each person\'s change is audited as user.permissions.change); without it only a list is printed}';

    protected $description = 'Lists (and with --apply removes) the permissions of a role the booking type does not have, e.g. infosec_officer_1';

    public function handle(PermissionRules $rules, AuditService $audit): int
    {
        $rows = DB::table('model_has_permissions as holder')
            ->join('permissions', 'permissions.id', '=', 'holder.permission_id')
            ->where('holder.model_type', (new User())->getMorphClass())
            ->get(['holder.model_id as user_id', 'permissions.name as permission']);

        $invalidByUser = [];

        foreach ($rows as $row) {
            if (!$rules->isValid($row->permission)) {
                $invalidByUser[(int) $row->user_id][] = $row->permission;
            }
        }

        // Permission definitions of a pair the type does not have, held by nobody, are stale too (the permission seeder drops them on a rebuild).
        $invalidDefinitions = Permission::query()->get(['id', 'name'])->filter(fn (Permission $permission): bool => !$rules->isValid($permission->name));

        if ($invalidByUser === [] && $invalidDefinitions->isEmpty()) {
            $this->info('No invalid permission: every {role}_{type} belongs to a role its booking type has.');

            return self::SUCCESS;
        }

        $users = User::query()->whereIn('id', array_keys($invalidByUser))->get()->keyBy('id');

        foreach ($invalidByUser as $userId => $names) {
            $this->line(sprintf('  %s: %s', $users[$userId]->personal_number ?? "user {$userId}", implode(', ', $names)));
        }

        $this->line(sprintf('%d invalid grant(s) of %d person(s); %d permission definition(s) of a pair no type has.', array_sum(array_map('count', $invalidByUser)), count($invalidByUser), $invalidDefinitions->count()));

        if (!$this->option('apply')) {
            $this->warn('Nothing was changed. Run again with --apply to remove them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($invalidByUser, $users, $audit, $invalidDefinitions): void {
            foreach ($invalidByUser as $userId => $names) {
                /** @var User $user */
                $user = $users[$userId];
                $before = $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();

                $user->revokePermissionTo($names);

                $after = $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();

                // The mobile app hears of it only when a permission it knows went (a web-only role or type never travels).
                if (collect($names)->contains(fn (string $name): bool => $this->mobileFacing($name))) {
                    $user->markRolesChanged();
                }

                $audit->record([
                    'action' => 'user.permissions.change',
                    'entity_type' => 'user',
                    'entity_id' => $user->actorId(),
                    'unit_id' => $user->unit_id,
                    'before' => ['permissions' => $before],
                    'after' => ['permissions' => $after],
                    'reason' => 'הרשאה לתפקיד שאינו קיים בתחום הוסרה (permissions:prune-invalid)',
                    'source' => 'automation',
                ]);
            }

            // The definitions of a pair no type has (nobody holds them now; deleting one also deletes what still points at it).
            $invalidDefinitions->each(fn (Permission $permission) => $permission->delete());
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Removed.');

        return self::SUCCESS;
    }

    private function mobileFacing(string $permissionName): bool
    {
        $parsed = PermissionEnum::parsePermissionName($permissionName);

        return $parsed === null || (!$parsed['permission']->toRoleEnum()->isWebOnly() && !BookingTypeEnum::isWebOnlyId($parsed['booking_type_id']));
    }
}
