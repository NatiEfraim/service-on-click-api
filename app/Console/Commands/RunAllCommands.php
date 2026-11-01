<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

class RunAllCommands extends Command
{
    protected $signature = 'run:all';

    protected $description = 'Run multiple Artisan commands in sequence';

       /** Local copy of the preprod DB - must never be wiped by migrate:fresh. */
    private const PREPROD_DATABASE = 'srvonclick-mobile-db-preprod';
    public function handle()
    {
        $this->call('optimize:clear');
        $database = config('database.connections.' . config('database.default') . '.database');

        // if ($database === self::PREPROD_DATABASE) {
        //     $this->call('migrate', ['--force' => true]);
        // } else {
        //     $this->call('migrate:fresh', ['--seed' => true]);
        // }
        $this->call('migrate', ['--force' => true]);
        // $this->call('migrate:fresh', ['--seed' => true]);
        if (!$this->hasPersonalAccessClient()) {
            $this->call('passport:client', [
                '--personal' => true,
                '--name' => config('auth.access_token_name'),
                '--provider' => 'users',
            ]);
        }
        $this->call('optimize:clear');
        $this->call('serve');
    }

    private function hasPersonalAccessClient(): bool
    {
        try {
            app(ClientRepository::class)->personalAccessClient('users');
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
