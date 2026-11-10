<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\BookingTypeHasRole;
use App\Models\User;
use Database\Seeders\ProdSeeders\RoleAndPermissionSeeder;
use Database\Seeders\TestSeeders\UserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

class ResetUsersCommand extends Command
{
    protected $signature = 'users:reset
        {--wipe-users : Also delete every user first (cascades to their bookings, logs and comments); needs --force}
        {--force : Confirms --wipe-users}
        {--personalId= : personal_id of a single user to reassign}
        {--role= : Role id to assign (1=SUPER_ADMIN 2=ADMIN 3=APPROVER 4=VICE_APPROVER 5=USER 6=TRANSPORTATION_CONTROL_OFFICER); required with --personalId}';

    protected $description = 'Local development: rebuild all user role/permission assignments, or reassign a single user with --personalId and --role';

    public function handle(): int
    {
    
        $personalId = $this->option('personalId');
        $roleValue  = $this->option('role');

        if ($personalId !== null || $roleValue !== null) {
            return $this->resetSingleUser($personalId, $roleValue);
        }

        return $this->resetAll();
    }

    private function resetAll(): int
    {
        $wipeUsers = (bool) $this->option('wipe-users');

        if ($wipeUsers && !$this->option('force')) {
            $this->error('--wipe-users DELETES every user together with their bookings, application logs and comments. Add --force to do it.');

            return self::FAILURE;
        }

        try {
            $tables = config('permission.table_names');

            DB::transaction(function () use ($tables, $wipeUsers): void {
                DB::table($tables['model_has_roles'])->delete();
                DB::table($tables['model_has_permissions'])->delete();
                DB::table($tables['role_has_permissions'])->delete();

                if ($wipeUsers) {
                    User::query()->delete();
                }

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $this->call('db:seed', ['--class' => RoleAndPermissionSeeder::class]);
                $this->call('db:seed', ['--class' => UserSeeder::class]);
                User::query()->toBase()->update(['is_exported_role' => false]);
            });

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->info('Done: ' . User::count() . ' users, assignments rebuilt.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            Log::error('ResetUsersCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resetSingleUser(?string $personalId, ?string $roleValue): int
    {
        if ($personalId === null || $roleValue === null) {
            $this->error('--personalId and --role must be used together.');

            return self::FAILURE;
        }

        $roleEnum = RoleEnum::tryFrom((int) $roleValue);

        if (!$roleEnum) {
            $this->error(
                "Invalid --role value \"{$roleValue}\". " .
                'Valid: 1=SUPER_ADMIN, 2=ADMIN, 3=APPROVER, 4=VICE_APPROVER, 5=USER, 6=TRANSPORTATION_CONTROL_OFFICER'
            );

            return self::FAILURE;
        }

        $user = User::where('personal_id', $personalId)->first();

        if (!$user) {
            $this->error("No user found with personal_id = {$personalId}.");

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($user, $roleEnum): void {
                $user->syncRoles([]);
                $user->syncPermissions([]);

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                $user->assignRole($roleEnum->value);

                if ($roleEnum !== RoleEnum::SUPER_ADMIN) {
                    $bookingTypeIds = BookingTypeHasRole::query()
                        ->where('role_id', $roleEnum->value)
                        ->pluck('booking_type_id')
                        ->map(fn($id) => (int) $id);

                    $user->givePermissionTo(
                        $bookingTypeIds
                            ->map(fn(int $id): string => $roleEnum->toPermissionEnum()->keyForId($id))
                            ->all()
                    );
                }

                $user->toBase()->update(['is_exported_role' => false]);
            });

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->info("Done: {$user->full_name} (personal_id {$user->personal_id}) is now {$roleEnum->name}.");

            return self::SUCCESS;
        } catch (\Exception $e) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            Log::error('ResetUsersCommand (single): failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
