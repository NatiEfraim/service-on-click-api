<?php

namespace App\Console\Commands;

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
        {--force : Confirms --wipe-users}';

    protected $description = 'Local development: clear the role/permission assignments, rebuild them and run the UserSeeder again';

    public function handle(): int
    {
        // The UserSeeder holds real people; it is meant for local development and tests only.
        if (!app()->environment(['local', 'testing'])) {
            $this->error('users:reset runs only on the local and testing environments.');

            return self::FAILURE;
        }

        $wipeUsers = (bool) $this->option('wipe-users');

        // No interactive prompt on purpose: on Windows Symfony switches the code page while it waits for the answer,
        // which breaks the autoloader when the project path has non-ASCII characters (it does: المستندات).
        if ($wipeUsers && !$this->option('force')) {
            $this->error('--wipe-users DELETES every user together with their bookings, application logs and comments. Add --force to do it.');

            return self::FAILURE;
        }

        try {
            $tables = config('permission.table_names');

            // One transaction: if a seeder fails, nothing is left half reset.
            DB::transaction(function () use ($tables, $wipeUsers): void {
                DB::table($tables['model_has_roles'])->delete();
                DB::table($tables['model_has_permissions'])->delete();
                DB::table($tables['role_has_permissions'])->delete();

                if ($wipeUsers) {
                    // Bookings, application logs, comments and the like are removed with the user (ON DELETE CASCADE).
                    User::query()->delete();
                }

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                // Roles and permissions first: the UserSeeder hands them out.
                $this->call('db:seed', ['--class' => RoleAndPermissionSeeder::class]);
                $this->call('db:seed', ['--class' => UserSeeder::class]);

                // The assignments were rebuilt, so nobody's roles are known to be the ones that already went to the mobile side: all wait for the users file again.
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
}
